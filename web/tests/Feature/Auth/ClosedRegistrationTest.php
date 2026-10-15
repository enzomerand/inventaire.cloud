<?php

declare(strict_types=1);

use App\Models\LoginCode;
use App\Models\User;
use App\Settings\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Sets whether new addresses can create an account, the way an administrator does.
 */
function registration(bool $open): void
{
    app(InstanceSettings::class)->setRegistrationOpen($open);
}

/**
 * The SQL statements run against the database while a callback runs, in order and without their values.
 *
 * @return list<string>
 */
function statementsOf(Closure $callback): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $callback();

    $statements = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return $statements;
}

/**
 * What a client sees of an answer that does not depend on the address: its status and headers (cookies and the date
 * aside, which differ from one request to the next), and its body once the address in it is replaced.
 *
 * @return array{status: int, headers: array<string, list<string|null>>, cookies: list<string>, body: string}
 */
function whatTheClientSees(TestResponse $response, string $email): array
{
    $headers = $response->headers->allPreserveCase();
    $cookies = array_map(fn ($cookie): string => $cookie->getName(), $response->headers->getCookies());
    unset($headers['Set-Cookie'], $headers['Date']);
    ksort($headers);

    return [
        'status' => $response->getStatusCode(),
        'headers' => $headers,
        'cookies' => $cookies,
        'body' => str_replace($email, 'ADDRESS', (string) $response->getContent()),
    ];
}

describe('asking for a code while registration is closed', function (): void {
    beforeEach(fn () => registration(false));

    it('gives an address that has an account its code, as ever', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);

        askForCode($this, 'ada@example.com')->assertOk();

        expect(sentMails())->toHaveCount(1)
            ->and(sentMails()[0]->getTo()[0]->getAddress())->toBe('ada@example.com')
            ->and(lastSentCode())->toMatch('/^\d{6}$/')
            ->and(LoginCode::query()->count())->toBe(1);
    });

    it('sends nothing to an address that has none', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);

        askForCode($this, 'bob@example.com')->assertOk();

        expect(sentMails())->toBe([]);
    });

    it('answers an address that has no account exactly like one that has: status, headers and body', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);

        $known = askForCode($this, 'ada@example.com');
        $this->flushSession();
        $unknown = askForCode($this, 'bob@example.com');

        expect($known->getStatusCode())->toBe(200)
            ->and(whatTheClientSees($unknown, 'bob@example.com'))->toBe(whatTheClientSees($known, 'ada@example.com'));
    });

    it('does the same work before the answer for both, never looking the account up', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        $deferred = holdDeferred();

        $known = statementsOf(fn () => askForCode($this, 'ada@example.com')->assertOk());
        $this->flushSession();
        $unknown = statementsOf(fn () => askForCode($this, 'bob@example.com')->assertOk());

        expect($unknown)->toBe($known)
            ->and(implode("\n", $known))->not->toContain('"users"')->not->toContain('`users`')->not->toContain('"settings"')->not->toContain('`settings`')
            ->and($deferred->count())->toBe(2)
            ->and(sentMails())->toBe([]);
    });

    it('looks the account up after the answer, and only then', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        $deferred = holdDeferred();
        askForCode($this, 'ada@example.com')->assertOk();
        $this->flushSession();
        askForCode($this, 'bob@example.com')->assertOk();

        $statements = statementsOf(fn () => $deferred->release());

        expect(implode("\n", $statements))->toContain('users')
            ->and(sentMails())->toHaveCount(1)
            ->and(sentMails()[0]->getTo()[0]->getAddress())->toBe('ada@example.com');
    });

    it('stores a code nobody receives for an address that has none, so that typing one back behaves the same for both', function (): void {
        askForCode($this, 'bob@example.com')->assertOk();

        $row = LoginCode::query()->sole();

        expect($row->email)->toBe('bob@example.com')
            ->and($row->attempts)->toBe(0)
            ->and($row->code_hash)->toMatch('/^[0-9a-f]{64}$/')
            ->and($row->expires_at->isBetween(now()->addMinutes(9), now()->addMinutes(10)->addSecond()))->toBeTrue()
            ->and(sentMails())->toBe([]);
    });

    it('throttles an address that has none exactly like one that has', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);

        foreach (['ada@example.com', 'bob@example.com'] as $email) {
            foreach (range(1, 3) as $_) {
                askForCode($this, $email)->assertOk();
            }

            askForCode($this, $email)->assertStatus(429);
        }
    });

    it('sends codes again to anybody once registration is open', function (): void {
        registration(true);

        askForCode($this, 'bob@example.com')->assertOk();

        expect(sentMails())->toHaveCount(1)->and(sentMails()[0]->getTo()[0]->getAddress())->toBe('bob@example.com');
    });

    it('keeps the code of an address whose mail failed, so that nothing tells it from an address that has no account', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        config([
            'mail.default' => 'unreachable',
            'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1],
        ]);
        Log::spy();

        askForCode($this, 'ada@example.com')->assertOk();
        $this->flushSession();
        askForCode($this, 'bob@example.com')->assertOk();
        $this->flushSession();

        // Both hold a pending code, as they would had the mail server answered: typing one back is judged the same.
        $known = whatTheClientSees(typeCode($this, 'ada@example.com', '000000'), 'ada@example.com');
        $this->flushSession();
        $unknown = whatTheClientSees(typeCode($this, 'bob@example.com', '000000'), 'bob@example.com');

        expect(LoginCode::query()->count())->toBe(2)
            ->and($unknown)->toBe($known)
            ->and(json_decode($known['body'], true)['code'])->toBe('login_code_wrong');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'Could not send a sign-in code'))->once();
    });

    it('drops the code of an address whose mail failed while registration is open, for everybody alike', function (): void {
        registration(true);
        User::factory()->create(['email' => 'ada@example.com']);
        config([
            'mail.default' => 'unreachable',
            'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1],
        ]);

        askForCode($this, 'ada@example.com')->assertOk();
        $this->flushSession();
        askForCode($this, 'bob@example.com')->assertOk();

        expect(LoginCode::query()->count())->toBe(0);
    });
});

describe('typing a code back while registration is closed', function (): void {
    it('signs in an account that exists, as ever', function (): void {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $code = receiveCode($this, 'ada@example.com');
        registration(false);

        typeCode($this, 'ada@example.com', $code)->assertOk()->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user, 'web');
    });

    it('refuses to create an account with a code issued before registration closed, as it would a wrong one', function (): void {
        $code = receiveCode($this, 'new@example.com');
        registration(false);

        $this->flushSession();
        $answer = typeCode($this, 'new@example.com', $code, ['name' => 'Grace Hopper'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'login_code_wrong')
            ->assertJsonPath('errors.code.0', 'Incorrect code. 4 attempts left.');

        expect($answer->headers->get('Content-Type'))->toContain('application/json')
            ->and(User::query()->count())->toBe(0);
        $this->assertGuest('web');
    });

    it('does not even ask for a name: that would tell the code was the right one', function (): void {
        $code = receiveCode($this, 'new@example.com');
        registration(false);

        typeCode($this, 'new@example.com', $code)->assertUnprocessable()->assertJsonPath('code', 'login_code_wrong');
        expect(User::query()->count())->toBe(0);
    });

    it('counts the right code as an attempt and burns it at the fifth, like any wrong one', function (): void {
        $code = receiveCode($this, 'new@example.com');
        registration(false);

        foreach ([4, 3, 2, 1] as $left) {
            typeCode($this, 'new@example.com', $code, ['name' => 'Grace'])->assertUnprocessable()->assertJsonPath('code', 'login_code_wrong')
                ->assertJsonPath('errors.code.0', trans_choice('Incorrect code. :count attempt left.|Incorrect code. :count attempts left.', $left, [], 'en'));
            $this->flushSession();
        }

        typeCode($this, 'new@example.com', $code, ['name' => 'Grace'])->assertUnprocessable()->assertJsonPath('code', 'login_code_exhausted');

        // Burnt: reopening registration does not bring it back.
        registration(true);
        $this->flushSession();
        typeCode($this, 'new@example.com', $code, ['name' => 'Grace'])->assertUnprocessable()->assertJsonPath('code', 'login_code_exhausted');
        expect(User::query()->count())->toBe(0);
    });

    it('accepts the same code once registration is open again, if attempts are left', function (): void {
        $code = receiveCode($this, 'new@example.com');
        registration(false);
        typeCode($this, 'new@example.com', $code, ['name' => 'Grace'])->assertUnprocessable();
        registration(true);
        $this->flushSession();

        typeCode($this, 'new@example.com', $code, ['name' => 'Grace Hopper'])->assertCreated()->assertJsonPath('data.name', 'Grace Hopper');

        expect(User::query()->where('email', 'new@example.com')->exists())->toBeTrue();
    });

    it('answers a wrong code for an address without an account exactly as for one with an account, attempt after attempt', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        $real = receiveCode($this, 'ada@example.com');
        $this->flushSession();
        registration(false);
        // Asked while closed: this address has no account, and holds a code nobody received.
        askForCode($this, 'bob@example.com')->assertOk();
        $this->flushSession();

        $known = [];
        $unknown = [];

        foreach (range(1, 6) as $_) {
            $known[] = whatTheClientSees(typeCode($this, 'ada@example.com', wrongCode($real)), 'ada@example.com');
            $this->flushSession();
            $unknown[] = whatTheClientSees(typeCode($this, 'bob@example.com', wrongCode($real)), 'bob@example.com');
            $this->flushSession();
        }

        expect($unknown)->toBe($known)
            ->and(array_column($known, 'status'))->toBe([422, 422, 422, 422, 422, 422]);
        expect(json_decode($known[0]['body'], true)['code'])->toBe('login_code_wrong')
            ->and(json_decode($known[4]['body'], true)['code'])->toBe('login_code_exhausted');
    });

    it('answers a code for an address nobody asked a code for exactly as for one with an account', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        registration(false);

        $known = whatTheClientSees(typeCode($this, 'ada@example.com', '123456'), 'ada@example.com');
        $this->flushSession();
        $unknown = whatTheClientSees(typeCode($this, 'bob@example.com', '123456'), 'bob@example.com');

        expect($unknown)->toBe($known)->and(json_decode($known['body'], true)['code'])->toBe('login_code_expired');
    });

    it('reads the setting for every address, so that the work is the same whether it has an account or not', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        registration(false);
        receiveCode($this, 'ada@example.com');
        $this->flushSession();
        askForCode($this, 'bob@example.com');
        $this->flushSession();

        $known = statementsOf(fn () => typeCode($this, 'ada@example.com', '000000'));
        $this->flushSession();
        $unknown = statementsOf(fn () => typeCode($this, 'bob@example.com', '000000'));

        expect($unknown)->toBe($known);
    });
});

describe('the login page', function (): void {
    it('says nothing about sign-ups while they are open', function (): void {
        $this->get(route('login'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('If you are new here, the same steps create your account.')
            // (The sentence is among the messages every page hands to its scripts, so look for the notice itself.)
            ->assertDontSee('role="note"', false);
    });

    it('says, to everybody alike, that only existing accounts can sign in once registration is closed', function (): void {
        registration(false);

        $this->get(route('login'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('role="note">Sign-ups are closed: only existing accounts can sign in.', false)
            ->assertDontSee('If you are new here')
            // The steps are the same: the page does not know which addresses have an account.
            ->assertSee('x-data="login"', false)
            ->assertSee('Send me a code');
    });

    it('is translated', function (): void {
        registration(false);

        $this->get(route('login'), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('role="note">Les inscriptions sont fermées : seuls les comptes existants peuvent se connecter.', false);
    });
});
