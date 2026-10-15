<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateSession;
use App\Installer\InstallationState;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
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
    it('is carried over when the session is still alive: it is given its fingerprint at its next request, and follows the others from then on', function (string $driver): void {
        useSessionDriver($driver);
        [, $laptop, $phone] = accountOnTwoDevices($this);

        // A session opened by the previous version has no fingerprint in it: take it out of the phone's.
        $store = app('session')->driver();
        $store->flush();
        $store->setId((string) $phone->sessionId());
        $store->start();
        $store->forget('password_hash_web');
        $store->save();
        $store->flush();

        assertSignedIn($phone);

        $store->flush();
        $store->setId((string) $phone->sessionId());
        $store->start();
        expect($store->has('password_hash_web'))->toBeTrue();
        $store->flush();

        $laptop->request('POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();

        assertSignedOut($phone);
    })->with(['array', 'database']);

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
