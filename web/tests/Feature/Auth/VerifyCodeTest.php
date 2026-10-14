<?php

declare(strict_types=1);

use App\Auth\Accounts;
use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

uses(RefreshDatabase::class);

describe('an existing account', function (): void {
    it('signs in with the right code', function (): void {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        $code = receiveCode($this, 'ada@example.com');

        typeCode($this, 'ada@example.com', $code)
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.name', 'Ada Lovelace');

        $this->assertAuthenticatedAs($user, 'web');
        expect(User::query()->count())->toBe(1);
    });

    it('can then be asked who is signed in, and signs out', function (): void {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        typeCode($this, 'ada@example.com', receiveCode($this, 'ada@example.com'))->assertOk();

        $this->getJson('/api/v1/me', frontend())->assertOk()->assertJsonPath('data.id', $user->id);

        $this->postJson('/api/v1/auth/logout', [], frontend())->assertNoContent();

        // One process serves one request in real life: forget what the guards remembered of the previous call.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/me', frontend())->assertUnauthorized();
        $this->assertGuest('web');
    });

    it('uses the code once', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        $code = receiveCode($this, 'ada@example.com');

        typeCode($this, 'ada@example.com', $code)->assertOk();
        $this->postJson('/api/v1/auth/logout', [], frontend())->assertNoContent();

        typeCode($this, 'ada@example.com', $code)->assertUnprocessable()->assertJsonPath('code', 'login_code_expired');
        $this->assertGuest('web');
    });

    it('ignores the name it is given: only a new account takes one', function (): void {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        typeCode($this, 'ada@example.com', receiveCode($this, 'ada@example.com'), ['name' => 'Someone Else'])->assertOk();

        expect($user->fresh()->name)->toBe('Ada Lovelace');
    });

    it('marks the address verified', function (): void {
        $user = User::factory()->unverified()->create(['email' => 'ada@example.com']);

        typeCode($this, 'ada@example.com', receiveCode($this, 'ada@example.com'))->assertOk();

        expect($user->fresh()->email_verified_at)->not->toBeNull();
    });

    it('finds the account whatever the case of the address', function (): void {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $code = receiveCode($this, 'ADA@Example.com');

        typeCode($this, ' ADA@EXAMPLE.COM ', $code)->assertOk()->assertJsonPath('data.id', $user->id);

        expect(User::query()->count())->toBe(1);
    });

    it('finds an account created with capitals, since addresses are stored in lowercase', function (): void {
        $user = User::factory()->create(['email' => 'Ada@Example.COM']);

        expect($user->fresh()->email)->toBe('ada@example.com');

        typeCode($this, 'ada@example.com', receiveCode($this, 'ada@example.com'))->assertOk()->assertJsonPath('data.id', $user->id);
    });

    it('tolerates spaces inside the code, as pasted from a mail', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        $code = receiveCode($this, 'ada@example.com');

        typeCode($this, 'ada@example.com', substr($code, 0, 3).' '.substr($code, 3))->assertOk();
    });
});

describe('a new account', function (): void {
    it('asks for a name first, and keeps the code meanwhile', function (): void {
        $code = receiveCode($this, 'new@example.com');

        typeCode($this, 'new@example.com', $code)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'name_required')
            ->assertJsonValidationErrors('name');

        expect(User::query()->count())->toBe(0)
            ->and(LoginCode::query()->findOrFail('new@example.com')->attempts)->toBe(0);
        $this->assertGuest('web');
    });

    it('is created with the name once the code is right', function (): void {
        $code = receiveCode($this, 'New@Example.com');
        typeCode($this, 'new@example.com', $code)->assertUnprocessable();

        typeCode($this, 'new@example.com', $code, ['name' => '  Grace Hopper '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Grace Hopper')
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.is_admin', false);

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();

        expect($user->name)->toBe('Grace Hopper')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->is_admin)->toBeFalse();
        $this->assertAuthenticatedAs($user, 'web');
    });

    it('can be created in one call when the name comes with the code', function (): void {
        typeCode($this, 'new@example.com', receiveCode($this, 'new@example.com'), ['name' => 'Grace Hopper'])->assertCreated();

        expect(User::query()->count())->toBe(1);
    });

    it('does not create an account on a wrong code, whatever the name', function (): void {
        $code = receiveCode($this, 'new@example.com');

        typeCode($this, 'new@example.com', wrongCode($code), ['name' => 'Grace Hopper'])->assertUnprocessable()->assertJsonPath('code', 'login_code_wrong');

        expect(User::query()->count())->toBe(0);
    });

    it('refuses an empty or oversized name', function (mixed $name): void {
        $code = receiveCode($this, 'new@example.com');

        typeCode($this, 'new@example.com', $code, ['name' => $name])->assertUnprocessable();

        expect(User::query()->count())->toBe(0);
    })->with([
        'blank' => ['   '],
        'too long' => [str_repeat('n', 256)],
        'an array' => [['Grace']],
    ]);

    it('takes the language in use as the language of the account', function (string $header): void {
        typeCode($this, 'new@example.com', receiveCode($this, 'new@example.com', $header), ['name' => 'Grace'], ['Accept-Language' => $header])->assertCreated();

        expect(User::query()->where('email', 'new@example.com')->firstOrFail()->locale)->toBe($header);
    })->with(['en', 'fr']);

    it('ends up with one account when two sign-ups race for the same address', function (): void {
        $winner = User::factory()->create(['name' => 'Winner', 'email' => 'new@example.com']);

        // The other sign-up got there between our lookup and our insert.
        [$user, $created] = app(Accounts::class)->register('New@Example.com', 'Loser', 'en');

        expect($created)->toBeFalse()
            ->and($user->is($winner))->toBeTrue()
            ->and(User::query()->count())->toBe(1);
    });
});

describe('a wrong code', function (): void {
    it('is refused with the attempts left', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        $code = receiveCode($this, 'ada@example.com');

        typeCode($this, 'ada@example.com', wrongCode($code))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'login_code_wrong')
            ->assertJsonPath('errors.code.0', 'Incorrect code. 4 attempts left.');

        $this->assertGuest('web');
    });

    it('burns the code at the fifth attempt, even for the right one', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        $code = receiveCode($this, 'ada@example.com');

        foreach (range(1, 4) as $ignored) {
            typeCode($this, 'ada@example.com', wrongCode($code))->assertJsonPath('code', 'login_code_wrong');
        }

        typeCode($this, 'ada@example.com', wrongCode($code))->assertUnprocessable()->assertJsonPath('code', 'login_code_exhausted');
        typeCode($this, 'ada@example.com', $code)->assertUnprocessable()->assertJsonPath('code', 'login_code_exhausted');

        $this->assertGuest('web');
    });

    it('is refused in the language of the request', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        $code = receiveCode($this, 'ada@example.com');

        typeCode($this, 'ada@example.com', wrongCode($code), headers: ['Accept-Language' => 'fr'])
            ->assertJsonPath('errors.code.0', 'Code incorrect. Il reste 4 essais.');
    });

    it('is answered the same for an account and for an address without one', function (): void {
        User::factory()->create(['email' => 'known@example.com']);
        $known = receiveCode($this, 'known@example.com');
        $unknown = receiveCode($this, 'unknown@example.com');

        $first = typeCode($this, 'known@example.com', wrongCode($known));
        $second = typeCode($this, 'unknown@example.com', wrongCode($unknown));

        expect($first->getStatusCode())->toBe($second->getStatusCode())
            ->and($first->json())->toBe($second->json());
    });

    it('is answered the same for an address nobody asked a code for', function (): void {
        User::factory()->create(['email' => 'known@example.com']);

        $first = typeCode($this, 'known@example.com', '123456');
        $second = typeCode($this, 'stranger@example.com', '123456');

        expect($first->getStatusCode())->toBe(422)
            ->and($first->json())->toBe($second->json())
            ->and($first->json('code'))->toBe('login_code_expired');
    });

    it('is refused for an address of a megabyte, before any code or counter is touched', function (): void {
        $code = receiveCode($this, 'ada@example.com');

        typeCode($this, str_repeat('a', 1_000_000).'@example.com', $code)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonCount(1, 'errors.email');

        expect(LoginCode::query()->findOrFail('ada@example.com')->attempts)->toBe(0)
            ->and(loginFailureCount('ada@example.com'))->toBe(0);
    });

    it('is refused when it is not six digits, before touching the stored code', function (string $code): void {
        User::factory()->create(['email' => 'ada@example.com']);
        receiveCode($this, 'ada@example.com');

        typeCode($this, 'ada@example.com', $code)->assertUnprocessable()->assertJsonValidationErrors('code');

        expect(LoginCode::query()->findOrFail('ada@example.com')->attempts)->toBe(0);
    })->with(['too short' => ['12345'], 'too long' => ['1234567'], 'letters' => ['abcdef']]);
});

describe('an expired code', function (): void {
    it('is refused after ten minutes', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        $this->freezeSecond();
        $code = receiveCode($this, 'ada@example.com');

        $this->travel(10)->minutes();
        $this->travel(2)->seconds();

        typeCode($this, 'ada@example.com', $code)->assertUnprocessable()->assertJsonPath('code', 'login_code_expired');
        $this->assertGuest('web');
    });

    it('is still good just before', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        $this->freezeSecond();
        $code = receiveCode($this, 'ada@example.com');

        $this->travel(9)->minutes();

        typeCode($this, 'ada@example.com', $code)->assertOk();
    });
});

describe('staying signed in', function (): void {
    /**
     * The "remember me" cookie of a response.
     */
    function rememberCookie(TestResponse $response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), 'remember_web_')) {
                return $cookie;
            }
        }

        return null;
    }

    it('lasts 30 days, through a cookie, not through the session', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);

        $response = typeCode($this, 'ada@example.com', receiveCode($this, 'ada@example.com'))->assertOk();
        $remember = rememberCookie($response);

        expect($remember)->not->toBeNull()
            ->and($remember->getExpiresTime())->toBeBetween(time() + 30 * 86400 - 120, time() + 30 * 86400 + 120)
            ->and($remember->isHttpOnly())->toBeTrue()
            ->and(config('session.lifetime'))->toBe(120);
    });

    it('tells the browser to forget the cookie when the person signs out, and then brings nobody back', function (): void {
        User::factory()->create(['email' => 'ada@example.com']);
        $response = typeCode($this, 'ada@example.com', receiveCode($this, 'ada@example.com'))->assertOk();
        $remember = rememberCookie($response);

        // The request that signs out carries the cookie, which is then told to expire.
        $logout = $this->withCredentials()->withUnencryptedCookie($remember->getName(), $remember->getValue())
            ->postJson('/api/v1/auth/logout', [], frontend())->assertNoContent();
        $forgotten = rememberCookie($logout);

        expect($forgotten)->not->toBeNull()->and($forgotten->getExpiresTime())->toBeLessThan(time());

        // The browser obeyed: the session is gone and there is no cookie left to bring the person back.
        $this->flushSession();
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/me', frontend())->assertUnauthorized();
    });

    it('keeps the other devices of the account signed in when one signs out', function (): void {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $first = rememberCookie(typeCode($this, 'ada@example.com', receiveCode($this, 'ada@example.com'))->assertOk());

        // A second device signs in: its own session and cookie.
        $this->flushSession();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $second = rememberCookie(typeCode($this, 'ada@example.com', receiveCode($this, 'ada@example.com'))->assertOk());

        // The first device signs out...
        $this->flushSession();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withCredentials()->withUnencryptedCookie($first->getName(), $first->getValue())
            ->postJson('/api/v1/auth/logout', [], frontend())->assertNoContent();

        // ...and the second one, whose session has expired since, is brought back by its cookie.
        $this->flushSession();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->withCredentials()->withUnencryptedCookie($second->getName(), $second->getValue())
            ->getJson('/api/v1/me', frontend())
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    });
});

describe('the account language', function (): void {
    it('is put in the locale cookie at sign-in, even when the browser speaks another language', function (): void {
        User::factory()->speaking('en')->create(['email' => 'ada@example.com']);

        $response = typeCode($this, 'ada@example.com', receiveCode($this, 'ada@example.com', 'fr'), headers: ['Accept-Language' => 'fr'])->assertOk();

        $response->assertCookie('locale', 'en', false);
    });

    it('is initialized from the language in use when the account has none', function (): void {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $response = typeCode($this, 'ada@example.com', receiveCode($this, 'ada@example.com', 'fr'), headers: ['Accept-Language' => 'fr'])->assertOk();

        expect($user->fresh()->locale)->toBe('fr');
        $response->assertCookie('locale', 'fr', false);
    });

    it('is replaced when it is no longer a language the application speaks', function (): void {
        $user = User::factory()->speaking('xx')->create(['email' => 'ada@example.com']);

        typeCode($this, 'ada@example.com', receiveCode($this, 'ada@example.com', 'en'))->assertOk()->assertCookie('locale', 'en', false);

        expect($user->fresh()->locale)->toBe('en');
    });
});

describe('too many wrong codes for an address', function (): void {
    it('answers 429 with the time to wait, whichever codes were asked for in between', function (string $account): void {
        if ($account === 'known') {
            User::factory()->create(['email' => 'ada@example.com']);
        }

        $this->freezeSecond();

        // Two codes, five wrong guesses at each (the per-minute throttle of the endpoint is left to cool down in between).
        foreach ([1, 2] as $ignored) {
            $code = receiveCode($this, 'ada@example.com');

            foreach (range(1, 5) as $guess) {
                typeCode($this, 'ada@example.com', wrongCode($code))->assertUnprocessable();
            }

            $this->travel(1)->minutes();
        }

        // A third code is a fresh set of five guesses, but the address already collected ten wrong codes.
        $code = receiveCode($this, 'ada@example.com');

        $response = typeCode($this, 'ada@example.com', $code)
            ->assertStatus(429)
            ->assertJsonPath('code', 'login_code_locked')
            ->assertJsonPath('message', 'Too many incorrect codes for this address: try again later.');

        expect((int) $response->headers->get('Retry-After'))->toBe(3480)
            ->and($response->headers->get('Content-Type'))->toBe('application/json')
            ->and(LoginCode::query()->findOrFail('ada@example.com')->attempts)->toBe(0);
        $this->assertGuest('web');
    })->with(['an account' => ['known'], 'an address without one' => ['unknown']]);

    it('is answered the same for an account and for an address without one', function (): void {
        User::factory()->create(['email' => 'known@example.com']);
        $this->freezeSecond();

        foreach ([1, 2] as $ignored) {
            foreach (['known@example.com', 'unknown@example.com'] as $email) {
                $code = receiveCode($this, $email);

                foreach (range(1, 5) as $guess) {
                    typeCode($this, $email, wrongCode($code));
                }
            }

            $this->travel(1)->minutes();
        }

        $known = typeCode($this, 'known@example.com', receiveCode($this, 'known@example.com'));
        $unknown = typeCode($this, 'unknown@example.com', receiveCode($this, 'unknown@example.com'));

        expect($known->getStatusCode())->toBe(429)->and($unknown->getStatusCode())->toBe(429)
            ->and($known->json())->toBe($unknown->json())
            ->and($known->headers->get('Retry-After'))->toBe($unknown->headers->get('Retry-After'));
    });

    it('is answered in the language of the request', function (): void {
        $this->freezeSecond();

        foreach ([1, 2] as $ignored) {
            $code = receiveCode($this, 'ada@example.com');

            foreach (range(1, 5) as $guess) {
                typeCode($this, 'ada@example.com', wrongCode($code));
            }

            $this->travel(1)->minutes();
        }

        typeCode($this, 'ada@example.com', receiveCode($this, 'ada@example.com'), headers: ['Accept-Language' => 'fr'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Trop de codes incorrects pour cette adresse : réessayez plus tard.');
    });

    it('lets the other addresses sign in', function (): void {
        $this->freezeSecond();

        foreach ([1, 2] as $ignored) {
            $code = receiveCode($this, 'ada@example.com');

            foreach (range(1, 5) as $guess) {
                typeCode($this, 'ada@example.com', wrongCode($code));
            }

            $this->travel(1)->minutes();
        }

        User::factory()->create(['email' => 'grace@example.com']);

        typeCode($this, 'grace@example.com', receiveCode($this, 'grace@example.com'))->assertOk();
    });
});

describe('a failure while signing in', function (): void {
    it('does not burn the code when the account cannot be created', function (): void {
        $code = receiveCode($this, 'new@example.com');
        User::creating(fn () => throw new RuntimeException('Forced failure.'));

        typeCode($this, 'new@example.com', $code, ['name' => 'Grace Hopper'])->assertServerError();

        expect(LoginCode::query()->findOrFail('new@example.com')->attempts)->toBe(0)
            ->and(User::query()->count())->toBe(0);
        $this->assertGuest('web');

        // The trouble is over: the same code, which was never spent, signs the person up.
        User::flushEventListeners();

        typeCode($this, 'new@example.com', $code, ['name' => 'Grace Hopper'])->assertCreated();
        $this->assertAuthenticated('web');
        expect(LoginCode::query()->count())->toBe(0);
    });

    it('does not burn the code when the account cannot be saved', function (): void {
        User::factory()->unverified()->create(['email' => 'ada@example.com']);
        $code = receiveCode($this, 'ada@example.com');
        User::saving(fn () => throw new RuntimeException('Forced failure.'));

        typeCode($this, 'ada@example.com', $code)->assertServerError();

        expect(LoginCode::query()->findOrFail('ada@example.com')->attempts)->toBe(0)
            ->and(User::query()->sole()->email_verified_at)->toBeNull();
        $this->assertGuest('web');

        User::flushEventListeners();

        typeCode($this, 'ada@example.com', $code)->assertOk();
        expect(User::query()->sole()->email_verified_at)->not->toBeNull();
    });

    it('still spends the code once the account is saved', function (): void {
        $code = receiveCode($this, 'new@example.com');

        typeCode($this, 'new@example.com', $code, ['name' => 'Grace Hopper'])->assertCreated();

        expect(LoginCode::query()->count())->toBe(0);
        typeCode($this, 'new@example.com', $code, ['name' => 'Grace Hopper'])->assertUnprocessable()->assertJsonPath('code', 'login_code_expired');
    });

    it('keeps the attempt that a wrong code cost, as before', function (): void {
        $code = receiveCode($this, 'new@example.com');

        typeCode($this, 'new@example.com', wrongCode($code), ['name' => 'Grace Hopper'])->assertUnprocessable();

        expect(LoginCode::query()->findOrFail('new@example.com')->attempts)->toBe(1);
    });
});

describe('throttling and access', function (): void {
    it('limits the codes typed from one address and IP, then answers 429', function (): void {
        $code = receiveCode($this, 'ada@example.com');

        foreach (range(1, 10) as $ignored) {
            typeCode($this, 'ada@example.com', wrongCode($code))->assertUnprocessable();
        }

        $response = typeCode($this, 'ada@example.com', wrongCode($code))->assertStatus(429);

        expect((int) $response->headers->get('Retry-After'))->toBeBetween(1, 60);
    });

    it('counts the codes typed from all the addresses of an IPv6 /64 together', function (): void {
        // The cap on wrong codes per address would also answer 429 here: set it out of the way to see the throttle.
        config(['auth.login_codes.failures_per_email' => ['attempts' => 1000, 'minutes' => 60]]);
        $code = receiveCode($this, 'ada@example.com');

        foreach (range(1, 10) as $number) {
            $this->withServerVariables(['REMOTE_ADDR' => "2001:db8:aaaa:1::{$number}"])
                ->postJson('/api/v1/auth/verify', ['email' => 'ada@example.com', 'code' => wrongCode($code)], frontend())
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:aaaa:1:ffff::1'])
            ->postJson('/api/v1/auth/verify', ['email' => 'ada@example.com', 'code' => wrongCode($code)], frontend())
            ->assertStatus(429)
            ->assertJsonMissingPath('code');

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:aaaa:2::1'])
            ->postJson('/api/v1/auth/verify', ['email' => 'ada@example.com', 'code' => wrongCode($code)], frontend())
            ->assertUnprocessable();
    });

    it('is the web front only', function (): void {
        $code = receiveCode($this, 'ada@example.com');

        $this->postJson('/api/v1/auth/verify', ['email' => 'ada@example.com', 'code' => $code, 'name' => 'Ada'])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_stateful');

        expect(User::query()->count())->toBe(0)->and(LoginCode::query()->count())->toBe(1);
        $this->assertGuest('web');
    });

    it('answers a missing field in JSON', function (): void {
        $this->postJson('/api/v1/auth/verify', [], frontend())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'code']);
    });
});
