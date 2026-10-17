<?php

declare(strict_types=1);

use App\Http\Middleware\RefuseApiTokens;
use App\Http\Middleware\RequireTokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;

require_once __DIR__.'/../../Support/contract.php';
require_once __DIR__.'/../../Support/tokens.php';

uses(RefreshDatabase::class);

/*
 * The document and the routes say the same thing: every route a token can reach is documented once, with the ability it needs, and
 * every documented operation is a route. What only a person at their browser may do is not documented. Together with the contract
 * (ApiContractTest.php) this is what lets the document be written by hand.
 */

/**
 * The method and the path of a route of the API as the document writes them (`get /inventories/{inventory}`).
 */
function contractRouteKey(Route $route): string
{
    $method = strtolower(array_values(array_diff($route->methods(), ['HEAD']))[0]);

    return $method.' '.substr('/'.$route->uri(), strlen('/api/v1'));
}

/**
 * The routes of the API that a token can reach, by the key of {@see contractRouteKey()}: those that carry an ability.
 *
 * @return array<string, array{name: string, ability: string}>
 */
function contractTokenRoutes(): array
{
    $routes = [];

    foreach (apiRoutes() as $name => $route) {
        foreach (apiRouteMiddleware($route) as $middleware) {
            if (str_starts_with($middleware, RequireTokenAbility::class.':')) {
                $routes[contractRouteKey($route)] = ['name' => $name, 'ability' => substr($middleware, strlen(RequireTokenAbility::class) + 1)];
            }
        }
    }

    return $routes;
}

/**
 * The operations of the document, by the key of {@see contractRouteKey()}.
 *
 * @return array<string, array{operationId: string, ability: string|null, operation: array<string, mixed>}>
 */
function contractOperationsByRoute(): array
{
    $operations = [];

    foreach (contractOperations() as $operation) {
        $operations[$operation['method'].' '.$operation['path']] = $operation;
    }

    return $operations;
}

describe('the routes a token can reach', function (): void {
    it('are each documented once, with the ability they need', function (): void {
        $documented = contractOperationsByRoute();
        $wrong = [];

        foreach (contractTokenRoutes() as $key => $route) {
            $operation = $documented[$key] ?? null;

            if ($operation === null) {
                $wrong[] = "{$route['name']} ({$key}) is not documented";
            } elseif ($operation['ability'] !== $route['ability']) {
                $wrong[] = "{$route['name']} needs token:{$route['ability']} and the document says ".($operation['ability'] ?? 'nothing');
            }
        }

        expect($wrong)->toBe([], 'Update resources/openapi/v1.yaml: '.implode('; ', $wrong));
    });

    it('are as many as the operations that name an ability', function (): void {
        $withAbility = array_filter(contractOperations(), fn (array $operation): bool => $operation['ability'] !== null);

        expect(count(contractTokenRoutes()))->toBe(count($withAbility))->and(count($withAbility))->toBeGreaterThan(30);
    });

    it('have an operation each: the same method and path never come twice', function (): void {
        $keys = array_map(fn (array $operation): string => $operation['method'].' '.$operation['path'], array_values(contractOperations()));

        expect($keys)->toHaveCount(count(array_unique($keys)));
    });
});

describe('the operations of the document', function (): void {
    it('are each a route of the API', function (): void {
        $routes = [];

        foreach (apiRoutes() as $route) {
            $routes[contractRouteKey($route)] = $route;
        }

        $missing = array_diff(array_keys(contractOperationsByRoute()), array_keys($routes));

        expect($missing)->toBe([], 'The document describes routes that do not exist: '.implode(', ', $missing));
    });

    it('name the ability of the route they describe, and only the public ones name none', function (): void {
        $public = array_map('contractRouteKey', array_filter(apiRoutes(), fn ($route, string $name): bool => in_array($name, tokenPublicRoutes(), true) && $name !== 'api.v1.openapi', ARRAY_FILTER_USE_BOTH));
        $abilities = array_column(array_values(contractTokenRoutes()), 'ability');

        foreach (contractOperationsByRoute() as $key => $operation) {
            if (isset(contractTokenRoutes()[$key])) {
                expect($operation['ability'])->toBe(contractTokenRoutes()[$key]['ability'], $operation['operationId']);
                expect($operation['operation']['security'] ?? null)->toBeNull($operation['operationId'].' is secured by the default requirement');
            } else {
                expect($public)->toContain($key);
                expect($operation['ability'])->toBeNull($operation['operationId']);
                expect($operation['operation']['security'] ?? null)->toBe([], $operation['operationId'].' needs no token');
            }
        }

        $abilities = array_values(array_unique($abilities));
        sort($abilities);

        expect($abilities)->toBe(['manage', 'read', 'write']);
    });

    it('are the templates of the catalogue for the public ones', function (): void {
        $templates = array_keys(array_filter(contractOperationsByRoute(), fn (array $operation): bool => $operation['ability'] === null));
        sort($templates);

        expect($templates)->toBe(['get /templates', 'get /templates/{slug}']);
    });

    it('leave out what only a person at their browser may do', function (): void {
        $documented = array_keys(contractOperationsByRoute());
        $sessionOnly = [];

        foreach (apiRoutes() as $name => $route) {
            if (in_array(RefuseApiTokens::class, apiRouteMiddleware($route), true)) {
                $sessionOnly[$name] = contractRouteKey($route);
            }
        }

        $names = array_keys($sessionOnly);
        $expected = tokenSessionOnlyRoutes();
        sort($names);
        sort($expected);

        expect($names)->toBe($expected)
            ->and(array_intersect($sessionOnly, $documented))->toBe([])
            // The document itself is a route of the API, and is not part of what it describes.
            ->and($documented)->not->toContain('get /openapi.json');
    });
});
