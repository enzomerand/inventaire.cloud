<?php

declare(strict_types=1);

use App\Http\Middleware\PaceApiTokens;
use App\Http\Middleware\RefuseApiTokens;
use App\Http\Middleware\RequireStatefulRequest;
use App\Http\Middleware\RequireTokenAbility;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../../Support/inventories.php';
require_once __DIR__.'/../../../Support/tokens.php';

uses(RefreshDatabase::class);

/*
 * The limits of the endpoints (an export, a workspace, a token to make...) are a person's own: they count what the person did, and a
 * request that is refused before it does anything (a token that may not, a route a token cannot reach) did nothing. If it counted, a
 * leaked token that can only read would use up what its owner may ask for in an hour, and lock the owner out of their own account.
 * So the refusals come first, then the limits of the endpoints; the pace of a token (what a token asks for, whatever it is) counts
 * every request it makes, refused or not, and comes first of all.
 */

/**
 * Where a middleware stands in the list of a route: the indexes of the entries that are the class, or start with `class:`.
 *
 * @param  list<string>  $middleware
 * @return list<int>
 */
function tokenMiddlewarePositions(array $middleware, string $class): array
{
    $found = [];

    foreach ($middleware as $index => $name) {
        if ($name === $class || str_starts_with($name, $class.':')) {
            $found[] = $index;
        }
    }

    return $found;
}

describe('the middleware of the routes of the API', function (): void {
    it('are in the order: authentication, pace of the token, refusals, limits of the endpoints, bindings, authorization', function (): void {
        $order = [Authenticate::class, PaceApiTokens::class, RefuseApiTokens::class, RequireStatefulRequest::class, RequireTokenAbility::class, ThrottleRequests::class, SubstituteBindings::class, Authorize::class];
        $wrong = [];

        foreach (apiRoutes() as $name => $route) {
            $middleware = apiRouteMiddleware($route);
            $previousEnd = -1;

            foreach ($order as $class) {
                $positions = tokenMiddlewarePositions($middleware, $class);

                if ($positions === []) {
                    continue;
                }

                if (min($positions) < $previousEnd) {
                    $wrong[$name] = implode(' > ', $middleware);
                }

                $previousEnd = max($positions);
            }
        }

        expect($wrong)->toBe([]);
    });

    it('count the pace of a token on every route behind the authentication, and nowhere else', function (): void {
        $paced = array_keys(array_filter(apiRoutes(), fn ($route): bool => tokenMiddlewarePositions(apiRouteMiddleware($route), PaceApiTokens::class) !== []));
        $behind = array_keys(array_filter(apiRoutes(), fn ($route): bool => tokenMiddlewarePositions(apiRouteMiddleware($route), Authenticate::class) !== []));

        // The sign-out is behind the authentication but is the person's own act at their browser, as it was before.
        expect(array_values(array_diff($behind, $paced)))->toBe(['api.v1.auth.logout'])->and(array_diff($paced, $behind))->toBe([]);
    });

    it('put the limit of an endpoint of a token route after the ability, and the pace of the token right after the authentication', function (): void {
        $middleware = apiRouteMiddleware(apiRoutes()['api.v1.inventories.exports.store']);
        $first = fn (string $class): int => min(tokenMiddlewarePositions($middleware, $class) ?: [PHP_INT_MAX]);

        expect($first(PaceApiTokens::class))->toBeGreaterThan($first(Authenticate::class))
            ->and($first(RequireTokenAbility::class))->toBeGreaterThan($first(PaceApiTokens::class))
            ->and($first(ThrottleRequests::class))->toBeGreaterThan($first(RequireTokenAbility::class))
            ->and($first(SubstituteBindings::class))->toBeGreaterThan($first(ThrottleRequests::class))
            ->and($middleware)->toContain(ThrottleRequests::class.':exports')->not->toContain(ThrottleRequests::class.':api-tokens');
    });

    it('put the limit of an endpoint of a session route after the refusal of tokens', function (): void {
        $middleware = apiRouteMiddleware(apiRoutes()['api.v1.me.tokens.store']);
        $first = fn (string $class): int => min(tokenMiddlewarePositions($middleware, $class) ?: [PHP_INT_MAX]);

        expect($first(PaceApiTokens::class))->toBeGreaterThan($first(Authenticate::class))
            ->and($first(RefuseApiTokens::class))->toBeGreaterThan($first(PaceApiTokens::class))
            ->and($first(ThrottleRequests::class))->toBeGreaterThan($first(RefuseApiTokens::class))
            ->and($first(ThrottleRequests::class))->toBeGreaterThan($first(RequireStatefulRequest::class))
            ->and($first(SubstituteBindings::class))->toBeGreaterThan($first(ThrottleRequests::class))
            ->and($middleware)->toContain(ThrottleRequests::class.':api-token-creation');
    });
});

describe('a request that is refused', function (): void {
    it('does not use up the tokens a person may make, whoever asked: a token cannot lock its owner out', function (): void {
        $user = inventoryPerson('Ada');
        $token = tokenFor($user, ['read']);

        foreach (range(1, 12) as $ignored) {
            tokenCall($this, $token, 'POST', '/api/v1/me/tokens', ['name' => 'Another', 'abilities' => ['read']])
                ->assertForbidden()
                ->assertJsonPath('code', 'session_required');
        }

        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Mine', 'abilities' => ['read'], 'expires_in_days' => 30])->assertCreated();
    });

    it('does not use up the exports a person may ask for, when the token may not write', function (): void {
        Queue::fake();
        config(['inventories.exports_per_minute' => 2]);
        $world = inventoryTeam();
        $inventory = inventoryIn($world['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/exports";
        $token = tokenFor($world['owner'], ['read']);

        foreach (range(1, 6) as $ignored) {
            tokenCall($this, $token, 'POST', $uri, ['format' => 'csv'])->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        }

        inventoryCall($this, $world['owner'], 'POST', $uri, ['format' => 'csv'])->assertStatus(202);
        inventoryCall($this, $world['owner'], 'POST', $uri, ['format' => 'csv'])->assertStatus(202);
        inventoryCall($this, $world['owner'], 'POST', $uri, ['format' => 'csv'])->assertTooManyRequests();
    });

    it('does not use up the workspaces a person may make, when the token may not manage', function (): void {
        config(['workspaces.create_per_hour' => 1]);
        $user = inventoryPerson('Ada');
        $token = tokenFor($user, ['read', 'write']);

        foreach (range(1, 4) as $ignored) {
            tokenCall($this, $token, 'POST', '/api/v1/workspaces', ['name' => 'Garage'])->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        }

        inventoryCall($this, $user, 'POST', '/api/v1/workspaces', ['name' => 'Garage'])->assertCreated();
        inventoryCall($this, $user, 'POST', '/api/v1/workspaces', ['name' => 'Cellar'])->assertTooManyRequests();
    });

    it('does not use up the exports of the account a person may ask for, when a token asks', function (): void {
        config(['auth.account_export' => ['minutes' => 60, 'attempts' => 1]]);
        $user = inventoryPerson('Ada');
        $token = tokenFor($user, ['*']);

        foreach (range(1, 3) as $ignored) {
            tokenCall($this, $token, 'GET', '/api/v1/me/export')->assertForbidden()->assertJsonPath('code', 'session_required');
        }

        inventoryCall($this, $user, 'GET', '/api/v1/me/export')->assertOk();
    });

    it('is still counted by the pace of the token that made it', function (): void {
        config(['inventories.api_token_requests_per_minute' => 3]);
        $user = inventoryPerson('Ada');
        $token = tokenFor($user, ['read']);

        tokenCall($this, $token, 'POST', '/api/v1/me/tokens', ['name' => 'Another', 'abilities' => ['read']])->assertForbidden()->assertJsonPath('code', 'session_required');
        tokenCall($this, $token, 'POST', '/api/v1/workspaces', ['name' => 'Garage'])->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
        tokenCall($this, $token, 'GET', '/api/v1/me')->assertTooManyRequests()->assertJsonPath('code', 'too_many_requests');
    });
});
