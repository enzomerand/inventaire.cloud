<?php

declare(strict_types=1);

use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Asks for a code from a given IP address.
 */
function askFrom(object $test, string $ip, string $email): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/code', ['email' => $email], frontend());
}

describe('requesting a code', function (): void {
    it('emails a six-digit code to the address', function (): void {
        askForCode($this, 'ada@example.com')
            ->assertOk()
            ->assertExactJson(['data' => ['email' => 'ada@example.com', 'expires_in' => 600]]);

        $mails = sentMails();

        expect($mails)->toHaveCount(1)
            ->and($mails[0]->getTo()[0]->getAddress())->toBe('ada@example.com')
            ->and(lastSentCode())->toMatch('/^\d{6}$/')
            ->and($mails[0]->getSubject())->toContain(lastSentCode());
    });

    it('sends a plain text body and a simple HTML one, with nothing fetched from outside', function (): void {
        askForCode($this, 'ada@example.com')->assertOk();

        $mail = sentMails()[0];
        $code = lastSentCode();

        expect($mail->getTextBody())->toContain($code)->toContain('10')
            ->and($mail->getHtmlBody())->toContain($code)->toContain('<html')
            ->and($mail->getHtmlBody())->not->toContain('http://')->not->toContain('https://')->not->toContain('<img')->not->toContain('<script')->not->toContain('<link');
    });

    it('normalizes the address to lowercase before storing and mailing', function (): void {
        askForCode($this, "  Ada.Lovelace@Example.COM \n")->assertOk()->assertJsonPath('data.email', 'ada.lovelace@example.com');

        expect(LoginCode::query()->pluck('email')->all())->toBe(['ada.lovelace@example.com'])
            ->and(sentMails()[0]->getTo()[0]->getAddress())->toBe('ada.lovelace@example.com');
    });

    it('writes the mail in the language of the request', function (string $header, string $subject): void {
        askForCode($this, 'ada@example.com', ['Accept-Language' => $header])->assertOk();

        expect(sentMails()[0]->getSubject())->toStartWith($subject)
            ->and(sentMails()[0]->getTextBody())->toContain($subject === 'Your' ? 'valid for 10 minutes' : 'valable 10 minutes');
    })->with([
        'english' => ['en', 'Your'],
        'french' => ['fr', 'Votre'],
    ]);

    it('writes the mail in the language of the cookie when there is one', function (): void {
        // JSON calls only carry cookies when asked to.
        $this->withCredentials()->withUnencryptedCookie('locale', 'fr');

        askForCode($this, 'ada@example.com', ['Accept-Language' => 'en'])->assertOk();

        expect(sentMails()[0]->getSubject())->toStartWith('Votre code');
    });

    it('is sent at once, even when the queue is not a synchronous one', function (): void {
        config(['queue.default' => 'database']);

        askForCode($this, 'ada@example.com')->assertOk();

        expect(sentMails())->toHaveCount(1);
    });

    it('drops the code when the mail cannot be sent', function (): void {
        config([
            'mail.default' => 'unreachable',
            'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1],
        ]);

        askForCode($this, 'ada@example.com')->assertServerError();

        expect(LoginCode::query()->count())->toBe(0);
    });
});

describe('what the answer gives away', function (): void {
    it('is the same whether the address has an account or not', function (): void {
        User::factory()->create(['email' => 'known@example.com']);

        $known = askForCode($this, 'known@example.com');
        $unknown = askForCode($this, 'unknown@example.com');

        expect($known->getStatusCode())->toBe($unknown->getStatusCode())
            ->and(str_replace('known@', 'x@', (string) $known->getContent()))->toBe(str_replace('unknown@', 'x@', (string) $unknown->getContent()))
            ->and($known->headers->get('Content-Type'))->toBe($unknown->headers->get('Content-Type'));
    });

    it('does the same work for both: a code stored and a mail sent, without ever reading the users', function (): void {
        User::factory()->create(['email' => 'known@example.com']);
        DB::enableQueryLog();

        askForCode($this, 'known@example.com')->assertOk();
        askForCode($this, 'unknown@example.com')->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");

        expect($queries)->not->toContain('"users"')->not->toContain('`users`')
            ->and(LoginCode::query()->count())->toBe(2)
            ->and(sentMails())->toHaveCount(2);
    });
});

describe('validation', function (): void {
    it('refuses a missing or malformed address in JSON', function (mixed $email): void {
        $this->postJson('/api/v1/auth/code', ['email' => $email], frontend())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        expect(sentMails())->toBe([])->and(LoginCode::query()->count())->toBe(0);
    })->with([
        'missing' => [null],
        'not an address' => ['not-an-address'],
        'no domain' => ['ada@'],
        'too long' => [str_repeat('a', 250).'@example.com'],
        'a list' => [['a@example.com']],
    ]);

    it('refuses an address of a megabyte as one that is too long, without sending anything', function (): void {
        $this->postJson('/api/v1/auth/code', ['email' => str_repeat('Ä', 500_000).'@Example.com'], frontend())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonCount(1, 'errors.email');

        expect(sentMails())->toBe([])->and(LoginCode::query()->count())->toBe(0);
    });

    it('keys the limiters of a text too long to be an address on a constant, not on a hash of it', function (): void {
        $request = fn (string $email): Request => Request::create('/api/v1/auth/code', 'POST', ['email' => $email], server: ['REMOTE_ADDR' => '203.0.113.9']);
        $keys = function (string $limiter, string $email) use ($request): array {
            $limits = RateLimiter::limiter($limiter)($request($email));

            return array_map(fn (Limit $limit): string => $limit->key, is_array($limits) ? $limits : [$limits]);
        };

        $oversized = $keys('login-code', str_repeat('a', 5_000_000));

        expect($oversized)->toBe(['email-ip:too-long|203.0.113.9', 'email:too-long', 'ip:203.0.113.9'])
            // Whatever it holds: two such texts are one and the same bucket.
            ->and($keys('login-code', str_repeat('b', 1_000)))->toBe($oversized)
            // An address of a normal size is still told apart, in its canonical form.
            ->and($keys('login-code', ' Ada@Example.com')[1])->toBe('email:'.sha1('ada@example.com'))
            ->and($keys('login-verify', str_repeat('a', 5_000_000)))->toBe(['ip:203.0.113.9|email:too-long'])
            ->and($keys('login-verify', 'ada@example.com'))->toBe(['ip:203.0.113.9|email:'.sha1('ada@example.com')]);
    });
});

describe('throttling', function (): void {
    it('allows three codes per address in ten minutes, then answers 429 with Retry-After', function (): void {
        foreach (range(1, 3) as $ignored) {
            askForCode($this, 'ada@example.com')->assertOk();
        }

        $response = askForCode($this, 'ada@example.com')->assertStatus(429);

        expect((int) $response->headers->get('Retry-After'))->toBeBetween(1, 600)
            ->and($response->headers->get('Content-Type'))->toBe('application/json')
            ->and(sentMails())->toHaveCount(3);
    });

    it('counts the address in canonical form: capitals do not buy more codes', function (): void {
        foreach (['ada@example.com', 'ADA@example.com', ' Ada@Example.com'] as $email) {
            askForCode($this, $email)->assertOk();
        }

        askForCode($this, 'aDa@EXAMPLE.com')->assertStatus(429);
    });

    it('does not let one address use up the codes of another', function (): void {
        foreach (range(1, 3) as $ignored) {
            askForCode($this, 'ada@example.com')->assertOk();
        }

        askForCode($this, 'grace@example.com')->assertOk();
    });

    it('limits the codes sent from one IP address, whatever the addresses', function (): void {
        config(['auth.login_codes.send_per_ip' => ['attempts' => 4, 'minutes' => 10]]);

        foreach (range(1, 4) as $number) {
            askForCode($this, "person{$number}@example.com")->assertOk();
        }

        askForCode($this, 'person5@example.com')->assertStatus(429);
    });

    it('allows three codes per address and network together, and another network its own three', function (): void {
        foreach (range(1, 3) as $ignored) {
            askFrom($this, '203.0.113.1', 'ada@example.com')->assertOk();
        }

        askFrom($this, '203.0.113.1', 'ada@example.com')->assertStatus(429);
        askFrom($this, '203.0.113.2', 'ada@example.com')->assertOk();
    });

    it('allows ten codes per address and hour, from however many networks, then answers 429 with Retry-After', function (): void {
        foreach (range(1, 10) as $number) {
            askFrom($this, "203.0.113.{$number}", 'ada@example.com')->assertOk();
        }

        $response = askFrom($this, '203.0.113.11', 'ada@example.com')->assertStatus(429);

        expect((int) $response->headers->get('Retry-After'))->toBeBetween(1, 3600)
            ->and($response->headers->get('Content-Type'))->toBe('application/json')
            ->and(sentMails())->toHaveCount(10);

        // Another address is not concerned, and the hour passes.
        askFrom($this, '203.0.113.11', 'grace@example.com')->assertOk();

        $this->travel(61)->minutes();

        askFrom($this, '203.0.113.12', 'ada@example.com')->assertOk();
    });

    it('limits the address per hour exactly like a known one: the same answer for an account and for none', function (): void {
        User::factory()->create(['email' => 'known@example.com']);

        foreach (['known@example.com', 'unknown@example.com'] as $email) {
            foreach (range(1, 10) as $number) {
                askFrom($this, "203.0.113.{$number}", $email)->assertOk();
            }
        }

        $known = askFrom($this, '203.0.113.11', 'known@example.com')->assertStatus(429);
        $unknown = askFrom($this, '203.0.113.12', 'unknown@example.com')->assertStatus(429);

        expect($known->headers->get('Content-Type'))->toBe($unknown->headers->get('Content-Type'))
            ->and($known->getContent())->toBe($unknown->getContent());
    });

    it('counts an IPv4 address on its own', function (): void {
        config(['auth.login_codes.send_per_ip' => ['attempts' => 2, 'minutes' => 10]]);

        askFrom($this, '203.0.113.1', 'one@example.com')->assertOk();
        askFrom($this, '203.0.113.1', 'two@example.com')->assertOk();
        askFrom($this, '203.0.113.1', 'three@example.com')->assertStatus(429);

        askFrom($this, '203.0.113.2', 'three@example.com')->assertOk();
    });

    it('counts all the addresses of an IPv6 /64 together: its owner picks as many as they like', function (): void {
        config(['auth.login_codes.send_per_ip' => ['attempts' => 4, 'minutes' => 10]]);

        $sameNetwork = ['2001:db8:aaaa:1::1', '2001:db8:aaaa:1:ffff:ffff:ffff:ffff', '2001:db8:aaaa:1:1234::5', '2001:DB8:AAAA:1:0:0:0:9'];

        foreach ($sameNetwork as $number => $ip) {
            askFrom($this, $ip, "person{$number}@example.com")->assertOk();
        }

        // A fifth address of the same /64, even a never-seen one, is refused...
        askFrom($this, '2001:db8:aaaa:1:42::7', 'person9@example.com')->assertStatus(429);

        // ...while the next /64 has its own allowance.
        askFrom($this, '2001:db8:aaaa:2::1', 'person9@example.com')->assertOk();
    });

    it('limits an unknown address exactly like a known one', function (): void {
        User::factory()->create(['email' => 'known@example.com']);

        foreach (['known@example.com', 'unknown@example.com'] as $email) {
            foreach (range(1, 3) as $ignored) {
                askForCode($this, $email)->assertOk();
            }

            askForCode($this, $email)->assertStatus(429);
        }
    });
});

describe('who may call it', function (): void {
    it('is the web front only: a call from elsewhere is refused with a reason', function (): void {
        $this->postJson('/api/v1/auth/code', ['email' => 'ada@example.com'])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_stateful');

        expect(sentMails())->toBe([])->and(LoginCode::query()->count())->toBe(0);
    });

    it('works from the host of APP_URL, whatever it is, with its port', function (): void {
        // The list of stateful hosts is built from APP_URL when the configuration loads: build it as an instance would.
        config(['app.url' => 'https://inventory.example.test:8443']);
        config(['sanctum.stateful' => (require config_path('sanctum.php'))['stateful']]);

        $this->postJson('/api/v1/auth/code', ['email' => 'ada@example.com'], ['Origin' => 'https://inventory.example.test:8443', 'Referer' => 'https://inventory.example.test:8443/login'])
            ->assertOk();

        // The same host without its port is another origin.
        $this->postJson('/api/v1/auth/code', ['email' => 'grace@example.com'], ['Referer' => 'https://inventory.example.test/login'])
            ->assertForbidden();
    });

    it('works from any host listed in SANCTUM_STATEFUL_DOMAINS', function (): void {
        config(['sanctum.stateful' => ['lan.example.test:9000']]);

        $this->postJson('/api/v1/auth/code', ['email' => 'ada@example.com'], ['Referer' => 'http://lan.example.test:9000/login'])->assertOk();
        $this->postJson('/api/v1/auth/code', ['email' => 'grace@example.com'], frontend())->assertForbidden();
    });
});
