<?php

declare(strict_types=1);

use App\Http\Middleware\RefuseApiTokens;
use App\Http\Middleware\RequireTokenAbility;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../../Support/tokens.php';

uses(RefreshDatabase::class);

/*
 * The guard-rail of the lot: what an API token may reach is decided route by route in routes/api.php, and a route that nobody
 * classified would be open to a token (or closed to it) by accident. Every route behind `auth:sanctum` carries either a token
 * ability or the refusal of tokens, never both and never neither.
 */

/**
 * The names of the routes of the API behind `auth:sanctum`.
 *
 * @return list<string>
 */
function tokenAuthenticatedRoutes(): array
{
    return array_keys(array_filter(
        apiRoutes(),
        fn ($route): bool => in_array(Authenticate::class.':sanctum', apiRouteMiddleware($route), true),
    ));
}

describe('the routes of the API', function (): void {
    it('are each either reachable with a token, with the ability it needs, or for the web front only', function (): void {
        $unclassified = [];

        foreach (apiRoutes() as $name => $route) {
            if (! in_array($name, tokenAuthenticatedRoutes(), true)) {
                continue;
            }

            $middleware = apiRouteMiddleware($route);
            $abilities = array_filter($middleware, fn (string $name): bool => str_starts_with($name, RequireTokenAbility::class.':'));
            $refuses = in_array(RefuseApiTokens::class, $middleware, true);

            if (count($abilities) + ($refuses ? 1 : 0) !== 1) {
                $unclassified[] = $name;
            }
        }

        expect($unclassified)->toBe([], 'Classify these routes in routes/api.php (token:read, token:write, token:manage or RefuseApiTokens, one of them): '.implode(', ', $unclassified));
    });

    it('know the routes the check looks at', function (): void {
        $authenticated = tokenAuthenticatedRoutes();

        expect(count($authenticated))->toBeGreaterThan(40)
            ->and($authenticated)->toContain('api.v1.me', 'api.v1.inventories.items.store', 'api.v1.admin.users.index', 'api.v1.auth.logout');
    });

    it('ask for the ability each of them is meant to need', function (): void {
        $found = [];

        foreach (apiRoutes() as $name => $route) {
            foreach (apiRouteMiddleware($route) as $middleware) {
                if (str_starts_with($middleware, RequireTokenAbility::class.':')) {
                    $found[$name] = substr($middleware, strlen(RequireTokenAbility::class) + 1);
                }
            }
        }

        ksort($found);
        $expected = tokenRouteClassification();
        ksort($expected);

        expect($found)->toBe($expected);
    });

    it('refuse a token on every route that belongs to a person at their browser', function (): void {
        foreach (tokenSessionOnlyRoutes() as $name) {
            $route = apiRoutes()[$name] ?? null;

            expect($route)->not->toBeNull("{$name} does not exist")
                ->and(apiRouteMiddleware($route))->toContain(RefuseApiTokens::class);
        }
    });

    it('have no account-less route but the sign-in, the description of the API and the catalogue of templates', function (): void {
        $open = array_diff(array_keys(apiRoutes()), tokenAuthenticatedRoutes());
        sort($open);

        expect($open)->toBe(['api.v1.auth.code', 'api.v1.auth.verify', 'api.v1.openapi', 'api.v1.templates.index', 'api.v1.templates.show']);
    });

    it('classify every route behind auth:sanctum once, in one list or the other', function (): void {
        $listed = [...array_keys(tokenRouteClassification()), ...tokenSessionOnlyRoutes()];
        $authenticated = tokenAuthenticatedRoutes();

        expect(array_diff($authenticated, $listed))->toBe([])
            ->and(array_diff($listed, [...$authenticated, 'api.v1.auth.code', 'api.v1.auth.verify']))->toBe([])
            ->and($listed)->toHaveCount(count(array_unique($listed)));
    });
});
