<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../../Support/inventories.php';
require_once __DIR__.'/../../../Support/tokens.php';

uses(RefreshDatabase::class);

/*
 * What belongs to a person at their browser is not for a token, whatever its abilities. Sanctum treats a request that sends the
 * `Origin` of the application as the front's and starts a session for it, and it falls back to the token when the session has
 * nobody in it: the `Origin` is a header anybody can write, so a script holding a token could pass for the front. These routes
 * look at the token, not at where the request says it comes from.
 */

dataset('session routes', [
    'sign-in code' => ['POST', '/api/v1/auth/code', ['email' => 'ada@example.com']],
    'sign-in verification' => ['POST', '/api/v1/auth/verify', ['email' => 'ada@example.com', 'code' => '123456']],
    'sign out' => ['POST', '/api/v1/auth/logout', []],
    'profile update' => ['PATCH', '/api/v1/me', ['name' => 'Hacked']],
    'account export' => ['GET', '/api/v1/me/export', []],
    'current workspace' => ['PUT', '/api/v1/me/current-workspace', ['workspace' => '01j0000000000000000000000x']],
    'sign out everywhere' => ['POST', '/api/v1/me/sign-out-everywhere', []],
    'account deletion code' => ['POST', '/api/v1/me/deletion-code', []],
    'account deletion' => ['DELETE', '/api/v1/me', ['code' => '123456']],
]);

/**
 * What a call to a session route must leave as it was.
 *
 * @return array{name: string, tokens: int, mails: int, users: int}
 */
function tokenAccountState(User $user): array
{
    return [
        'name' => (string) $user->fresh()?->name,
        'tokens' => DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count(),
        'mails' => count(sentMails()),
        'users' => User::query()->count(),
    ];
}

describe('a route for the web front only', function (): void {
    it('refuses a token that sends the Origin of the application, whatever the token may do', function (string $method, string $uri, array $data): void {
        $user = inventoryPerson('Ada');
        $token = tokenFor($user, ['*']);
        $before = tokenAccountState($user);

        tokenCall($this, $token, $method, $uri, $data, frontend())
            ->assertForbidden()
            ->assertExactJson(['message' => 'This is only available from the web interface, not with an API token.', 'code' => 'session_required']);

        expect(tokenAccountState($user))->toBe($before);
    })->with('session routes');

    it('refuses a token from anywhere else too, with the same reason', function (string $method, string $uri, array $data): void {
        $user = inventoryPerson('Ada');
        $token = tokenFor($user, ['*']);
        $before = tokenAccountState($user);

        tokenCall($this, $token, $method, $uri, $data)->assertForbidden()->assertJsonPath('code', 'session_required');

        expect(tokenAccountState($user))->toBe($before);
    })->with('session routes');

    it('answers a made-up token with a 401, or with the refusal on the routes nobody is signed in for', function (string $method, string $uri, array $data): void {
        $user = inventoryPerson('Ada');
        $before = tokenAccountState($user);

        // Not even a real token: on the sign-in routes, where nobody has to be signed in, the header alone is refused.
        tokenCall($this, '1|not-a-real-token', $method, $uri, $data, frontend())->assertStatus(in_array($uri, ['/api/v1/auth/code', '/api/v1/auth/verify'], true) ? 403 : 401);

        expect(tokenAccountState($user))->toBe($before);
    })->with('session routes');

    it('still serves the web front, which has a session and no token, even for a person who holds tokens', function (): void {
        $user = inventoryPerson('Ada');
        tokenFor($user, ['*']);

        inventoryCall($this, $user, 'PATCH', '/api/v1/me', ['name' => 'Ada Lovelace'])->assertOk()->assertJsonPath('data.name', 'Ada Lovelace');
        inventoryCall($this, $user, 'GET', '/api/v1/me/export')->assertOk();

        expect($user->fresh()?->name)->toBe('Ada Lovelace');
    });
});

describe('the administration', function (): void {
    it('refuses a token of an administrator that sends the Origin of the application', function (): void {
        $admin = User::factory()->admin()->withPersonalWorkspace()->create();
        $victim = User::factory()->withPersonalWorkspace()->create();
        $token = tokenFor($admin, ['*']);

        tokenCall($this, $token, 'GET', '/api/v1/admin/users', headers: frontend())->assertForbidden()->assertJsonPath('code', 'session_required');
        tokenCall($this, $token, 'PUT', '/api/v1/admin/settings', ['registration_open' => false], frontend())->assertForbidden()->assertJsonPath('code', 'session_required');
        tokenCall($this, $token, 'DELETE', '/api/v1/admin/users/'.$victim->id, headers: frontend())->assertForbidden()->assertJsonPath('code', 'session_required');

        expect(User::query()->count())->toBe(2);
    });
});
