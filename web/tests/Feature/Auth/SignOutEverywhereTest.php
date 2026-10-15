<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateSession;
use App\Installer\InstallationState;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Device;

uses(RefreshDatabase::class);

/*
 * "Sign out everywhere" renews the remember token that every session and "remember me" cookie of the account is
 * tied to. Each scenario runs with the sessions kept in memory (the array driver, which the suite uses) and in the
 * database (the default of a real instance), since the second is where sessions outlive a request for real.
 */
/**
 * Configures the session driver of the test, before the first request.
 */
function useSessionDriver(string $driver): void
{
    config(['session.driver' => $driver]);
    app('session')->forgetDrivers();
}

/**
 * A person with an account, and the browsers they signed in with.
 *
 * @return array{0: User, 1: Device, 2: Device}
 */
function accountOnTwoDevices(object $test): array
{
    $user = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
    $laptop = new Device($test);
    $phone = new Device($test);

    $laptop->signIn('ada@example.com')->assertOk();
    $phone->signIn('ada@example.com')->assertOk();

    return [$user, $laptop, $phone];
}

/**
 * Asserts that a device is signed in, on a web page and on the API.
 */
function assertSignedIn(Device $device): void
{
    $device->visit('/app')->assertOk();
    $device->request('GET', '/api/v1/me')->assertOk()->assertJsonPath('data.email', 'ada@example.com');
}

/**
 * Asserts that a device is signed out, on a web page (sent to the login page) and on the API (a 401 in JSON).
 */
function assertSignedOut(Device $device): void
{
    $device->visit('/app')->assertRedirect(route('login'));
    $device->request('GET', '/api/v1/me')->assertUnauthorized();
}

/**
 * Opens the session a device holds in the session store of the process (the way the next request would), reads what
 * a callback finds there and puts the store back to empty.
 *
 * @template T
 *
 * @param  callable(Session): T  $read
 * @return T
 */
function inSessionOf(Device $device, callable $read): mixed
{
    $store = app('session')->driver();
    $store->flush();
    $store->setId((string) $device->sessionId());
    $store->start();

    try {
        return $read($store);
    } finally {
        $store->flush();
    }
}

/**
 * The fingerprint a device's session holds right now, without any request in between.
 */
function fingerprintOf(Device $device): ?string
{
    return inSessionOf($device, fn ($session): mixed => $session->get('password_hash_web'));
}

/**
 * Takes the fingerprint out of a device's session, as a session opened before fingerprints existed lacks it.
 */
function forgetFingerprintOf(Device $device): void
{
    inSessionOf($device, function ($session): void {
        $session->forget('password_hash_web');
        $session->save();
    });
}

describe('signing out everywhere', function (): void {
    it('refuses the other devices and keeps this one signed in', function (string $driver): void {
        useSessionDriver($driver);
        [, $laptop, $phone] = accountOnTwoDevices($this);
        assertSignedIn($laptop);
        assertSignedIn($phone);

        $laptop->request('POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();

        assertSignedIn($laptop);
        assertSignedOut($phone);
    })->with(['array', 'database']);

    it('refuses a "remember me" cookie that was copied before, once its session is gone', function (string $driver): void {
        useSessionDriver($driver);
        [, $laptop] = accountOnTwoDevices($this);
        $control = $laptop->withoutSession();
        $replay = $laptop->withoutSession();

        // Before: the cookie alone signs somebody in, which is what makes a copy of it dangerous.
        assertSignedIn($control);

        $laptop->request('POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();

        assertSignedOut($replay);
    })->with(['array', 'database']);

    it('refuses what this device held before: its old session and its old cookie', function (string $driver): void {
        useSessionDriver($driver);
        [, $laptop] = accountOnTwoDevices($this);
        $before = $laptop->copy();

        $laptop->request('POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();

        assertSignedOut($before);
        assertSignedIn($laptop);
    })->with(['array', 'database']);

    it('gives this device a new session and a "remember me" cookie for the new token', function (string $driver): void {
        useSessionDriver($driver);
        [$user, $laptop] = accountOnTwoDevices($this);
        $oldToken = $user->fresh()->remember_token;
        $oldSession = $laptop->cookie(config()->string('session.cookie'));
        $oldRemember = $laptop->cookie($laptop->rememberCookieName());

        $laptop->request('POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();

        expect($user->fresh()->remember_token)->not->toBe($oldToken)->toHaveLength(60)
            ->and($laptop->cookie(config()->string('session.cookie')))->not->toBe($oldSession)
            ->and($laptop->cookie($laptop->rememberCookieName()))->not->toBe($oldRemember);
    })->with(['array', 'database']);

    it('does not change the update time of the account', function (): void {
        [$user, $laptop] = accountOnTwoDevices($this);
        $stamp = $user->fresh()->updated_at;

        $this->travel(10)->minutes();
        $laptop->request('POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();

        expect($user->fresh()->updated_at->equalTo($stamp))->toBeTrue();
    });

    it('can be done again and again', function (): void {
        [, $laptop, $phone] = accountOnTwoDevices($this);

        $laptop->request('POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();
        $laptop->request('POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();

        assertSignedIn($laptop);
        assertSignedOut($phone);
    });

    it('leaves the other accounts alone', function (): void {
        [, $laptop] = accountOnTwoDevices($this);
        $other = User::factory()->create(['email' => 'grace@example.com']);
        $grace = new Device($this);
        $grace->signIn('grace@example.com')->assertOk();

        $laptop->request('POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();

        $grace->request('GET', '/api/v1/me')->assertOk()->assertJsonPath('data.id', $other->id);
    });

    it('is for somebody who is signed in, and for the web front', function (): void {
        $this->postJson('/api/v1/me/sign-out-everywhere', [], frontend())->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/me/sign-out-everywhere')->assertForbidden()->assertJsonPath('code', 'not_stateful');
    });
});

describe('signing out of one device', function (): void {
    it('keeps the others signed in, session and all', function (string $driver): void {
        useSessionDriver($driver);
        [, $laptop, $phone] = accountOnTwoDevices($this);

        $laptop->request('POST', '/api/v1/auth/logout')->assertNoContent();

        assertSignedOut($laptop);
        assertSignedIn($phone);
        assertSignedIn($phone->withoutSession());
    })->with(['array', 'database']);
});

describe('a session or a cookie from before this update', function (): void {
    it('is refused when the session is still alive but has no fingerprint: the person signs in once more', function (string $driver): void {
        useSessionDriver($driver);
        [, $laptop, $phone] = accountOnTwoDevices($this);

        // A session opened by the previous version has no fingerprint in it: take it out of the phone's. Giving it
        // the current one at its next request, as the framework does, would let it outlive any later sign-out
        // everywhere, since it would then carry the fingerprint of whoever asked first.
        forgetFingerprintOf($phone);

        assertSignedOut($phone);
        assertSignedIn($laptop);
    })->with(['array', 'database']);

    it('refuses a session without a fingerprint on the API too, with a JSON 401', function (): void {
        [, , $phone] = accountOnTwoDevices($this);
        forgetFingerprintOf($phone);

        $phone->request('GET', '/api/v1/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    });

    it('is refused when it is a "remember me" cookie: the person signs in once more', function (): void {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $guard = app('auth')->guard('web');

        /** The cookie of a device, as the browser sends it back: what the previous version put in it, or what this one does. */
        $cookie = fn (string $fingerprint): string => encrypt(CookieValuePrefix::create($guard->getRecallerName(), app('encrypter')->getKey())."{$user->id}|{$user->remember_token}|".$guard->hashPasswordForCookie($fingerprint), false);
        $send = function (string $value) use ($guard): TestResponse {
            // Nothing of the previous call: no session left in the store, no guard that remembers the user.
            $this->flushSession();
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
            $this->app['auth']->shouldUse('web');

            return $this->withUnencryptedCookie($guard->getRecallerName(), $value)->withCredentials()->getJson('/api/v1/me', frontend());
        };

        // The same cookie with the new fingerprint is accepted, which shows the cookie itself is sound...
        $send($cookie($user->getAuthPassword()))->assertOk();

        // ...and the previous version's, made with its constant, empty fingerprint, is not.
        $send($cookie(''))->assertUnauthorized();
    });
});

describe('the fingerprint of a new session', function (): void {
    it('is in the session as soon as the person is signed in, before any other request', function (string $driver): void {
        useSessionDriver($driver);
        $user = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        $device = new Device($this);

        $device->signIn('ada@example.com')->assertOk();

        expect(fingerprintOf($device))->toBe(app('auth')->guard('web')->hashPasswordForCookie($user->fresh()->getAuthPassword()));
    })->with(['array', 'database']);

    it('is the new one on the device that signed out everywhere, again before any other request', function (string $driver): void {
        useSessionDriver($driver);
        [$user, $laptop] = accountOnTwoDevices($this);
        $old = fingerprintOf($laptop);

        $laptop->request('POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();

        expect(fingerprintOf($laptop))->toBe(app('auth')->guard('web')->hashPasswordForCookie($user->fresh()->getAuthPassword()))
            ->not->toBe($old);
    })->with(['array', 'database']);

    it('is also given to an account created by signing up', function (): void {
        $device = new Device($this);
        $device->request('POST', '/api/v1/auth/code', ['email' => 'new@example.com'])->assertOk();
        $device->request('POST', '/api/v1/auth/verify', ['email' => 'new@example.com', 'code' => lastSentCode(), 'name' => 'New Person'])->assertCreated();

        expect(fingerprintOf($device))->toMatch('/^[0-9a-f]{64}$/');
    });
});

describe('the pages of the signed-in space and the others', function (): void {
    it('lets a revoked session open the public home, and sends it to the login page from the signed-in space', function (): void {
        [, $laptop, $phone] = accountOnTwoDevices($this);
        $laptop->request('POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();

        // The public pages do not check the session at all: nothing redirects a visitor away from the home page.
        $phone->visit('/')->assertOk()->assertViewIs('home');

        foreach (['/app', '/app/workspaces', '/app/account'] as $page) {
            $phone->visit($page)->assertRedirect(route('login'));
        }
    });

    it('is sent on by the login page and ended by the signed-in space, which the next visit shows', function (): void {
        [, $laptop, $phone] = accountOnTwoDevices($this);
        $laptop->request('POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();

        $phone->visit('/login')->assertRedirect(route('app'));
        $phone->visit('/app')->assertRedirect(route('login'));
        $phone->visit('/login')->assertOk();
    });

    it('does not ask a visitor who is not signed in for anything', function (): void {
        $this->get('/')->assertOk()->assertViewIs('home');
        $this->get('/login')->assertOk();
        $this->get('/app')->assertRedirect(route('login'));
    });

    it('keeps the session of a device that is up to date on the signed-in space', function (): void {
        [, $laptop] = accountOnTwoDevices($this);

        $laptop->visit('/app')->assertOk();
        $laptop->visit('/app/workspaces')->assertOk();
    });
});

describe('signing out everywhere and the API tokens', function (): void {
    it('revokes them, since a lost device may hold one', function (): void {
        [$user, $laptop] = accountOnTwoDevices($this);
        $plain = $user->createToken('script')->plainTextToken;
        $user->createToken('other script');
        $someoneElse = User::factory()->create();
        $someoneElse->createToken('theirs');

        $bearer = function (string $token): TestResponse {
            // A client of its own: no cookie, no session, the guards of the previous call forgotten.
            $this->flushSession();
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
            $this->app['auth']->shouldUse('web');

            return $this->withToken($token)->getJson('/api/v1/me');
        };

        $bearer($plain)->assertOk()->assertJsonPath('data.id', $user->id);

        $laptop->request('POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();

        $bearer($plain)->assertUnauthorized();
        expect(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0)
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $someoneElse->id)->count())->toBe(1);
        assertSignedIn($laptop);
    });
});

describe('the migration that ends the sessions opened before fingerprints', function (): void {
    it('empties the sessions table, and can be run again', function (): void {
        $migration = require database_path('migrations/2026_10_15_400000_end_sessions_opened_before_fingerprints.php');
        $user = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'a', 'user_id' => $user->id, 'ip_address' => '203.0.113.5', 'user_agent' => 'Firefox', 'payload' => '', 'last_activity' => time()],
            ['id' => 'b', 'user_id' => null, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()],
        ]);

        $migration->up();
        expect(DB::table('sessions')->count())->toBe(0);

        $migration->up();
        expect(DB::table('sessions')->count())->toBe(0)
            ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
    });

    it('ends the sessions of the database, and leaves alone the "remember me" cookies that carry the current fingerprint', function (): void {
        useSessionDriver('database');
        [, $laptop, $phone] = accountOnTwoDevices($this);
        assertSignedIn($laptop);

        (require database_path('migrations/2026_10_15_400000_end_sessions_opened_before_fingerprints.php'))->up();

        // The session rows are gone. A "remember me" cookie issued by this version still carries the right fingerprint
        // and signs its device in again; the ones of the previous version do not (see the test of those above).
        expect(DB::table('sessions')->count())->toBe(0);
        $phone->withoutSession()->visit('/app')->assertOk();
    });
});

describe('the fingerprint of an account', function (): void {
    it('derives from the remember token and from nothing else', function (): void {
        $first = User::factory()->make(['remember_token' => 'token-one']);
        $same = User::factory()->make(['remember_token' => 'token-one']);
        $other = User::factory()->make(['remember_token' => 'token-two']);

        expect($first->getAuthPassword())->toBe($same->getAuthPassword())
            ->and($first->getAuthPassword())->not->toBe($other->getAuthPassword())
            ->and($first->getAuthPassword())->toMatch('/^[0-9a-f]{64}$/')
            ->and($first->getAuthPassword())->not->toContain('token-one');
    });

    it('is empty for an account that has no token, which the framework reads as "no check"', function (): void {
        expect(User::factory()->make(['remember_token' => null])->getAuthPassword())->toBe('')
            ->and(User::factory()->make(['remember_token' => ''])->getAuthPassword())->toBe('');
    });

    it('is given to every account when it is created', function (): void {
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        expect($user->fresh()->remember_token)->toHaveLength(60);
    });

    it('is not reset by the token an account already has', function (): void {
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
        $token = $user->fresh()->remember_token;

        $user->fresh()->update(['name' => 'Ada Lovelace']);

        expect($user->fresh()->remember_token)->toBe($token);
    });
});

describe('the migration for existing accounts', function (): void {
    it('gives a token to the accounts that have none, and only to them', function (): void {
        $migration = require database_path('migrations/2026_10_15_200000_give_every_user_a_remember_token.php');
        $kept = User::factory()->create(['remember_token' => 'kept-token']);
        $now = now();
        DB::table('users')->insert([
            ['name' => 'A', 'email' => 'a@example.test', 'remember_token' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'B', 'email' => 'b@example.test', 'remember_token' => '', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $migration->up();
        $tokens = DB::table('users')->pluck('remember_token', 'email');
        $migration->up();

        expect($tokens['a@example.test'])->toHaveLength(60)
            ->and($tokens['b@example.test'])->toHaveLength(60)
            ->and($tokens['a@example.test'])->not->toBe($tokens['b@example.test'])
            ->and($kept->fresh()->remember_token)->toBe('kept-token')
            ->and(DB::table('users')->pluck('remember_token', 'email')->all())->toBe($tokens->all());
    });
});

describe('the install wizard', function (): void {
    it('is not asked to look anybody up: the check waits for the installation to be over', function (): void {
        $this->bootInstaller(installed: false);
        $auth = Mockery::mock(AuthFactory::class);
        $auth->shouldNotReceive('guard');
        $auth->shouldNotReceive('getDefaultDriver');
        $middleware = new AuthenticateSession($auth, app(InstallationState::class));

        $response = $middleware->handle(Request::create('/install'), fn () => response('wizard'));

        expect($response->getContent())->toBe('wizard');
    });
});
