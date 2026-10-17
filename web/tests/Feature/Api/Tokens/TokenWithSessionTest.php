<?php

declare(strict_types=1);

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../../Support/inventories.php';
require_once __DIR__.'/../../../Support/tokens.php';

uses(RefreshDatabase::class);

/*
 * Sanctum looks at the session before it looks at the bearer token: a request that carries both (the cookie of the front and an
 * `Authorization` header) is signed in as the person at their browser, with a transient token that can do anything, and the
 * abilities of the token the caller chose to send would never be looked at. Such a request is refused, whatever the token can do.
 */

describe('a request with a session cookie and an API token', function (): void {
    it('does not escape the abilities of the token: a read-only token cannot write through the session', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'text', 'Name');
        $token = tokenFor($team['owner'], ['read']);

        tokenWithSessionCall($this, $team['owner'], $token, 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => [$field->ulid => 'Saw']], ['Accept-Language' => 'en'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'Send either a session cookie or an API token, not both.', 'code' => 'token_with_session']);

        expect(Item::query()->count())->toBe(0);
    });

    it('says so in the language of the request', function (): void {
        $user = inventoryPerson('Ada');

        tokenWithSessionCall($this, $user, tokenFor($user, ['read']), 'GET', '/api/v1/me', headers: ['Accept-Language' => 'fr'])
            ->assertForbidden()
            ->assertExactJson(['message' => "Envoyez soit un cookie de session, soit un jeton d'API, pas les deux.", 'code' => 'token_with_session']);
    });

    it('is refused for a read too, and whatever the token can do: sending both is ambiguous, not allowed', function (): void {
        $user = inventoryPerson('Ada');

        tokenWithSessionCall($this, $user, tokenFor($user, ['read']), 'GET', '/api/v1/me')->assertForbidden()->assertJsonPath('code', 'token_with_session');
        tokenWithSessionCall($this, $user, tokenFor($user, ['*']), 'GET', '/api/v1/me')->assertForbidden()->assertJsonPath('code', 'token_with_session');
        tokenWithSessionCall($this, $user, '1|not-a-real-token', 'GET', '/api/v1/me')->assertForbidden()->assertJsonPath('code', 'token_with_session');
    });

    it('is refused on every route that asks for an ability', function (): void {
        $user = inventoryPerson('Ada');
        $token = tokenFor($user, ['*']);
        $failures = [];

        foreach (tokenRouteClassification() as $name => $ability) {
            $route = apiRoutes()[$name];
            $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $response = tokenWithSessionCall($this, $user, $token, $method, tokenRouteUri($route));

            if ($response->status() !== 403 || $response->json('code') !== 'token_with_session') {
                $failures[] = "{$name}: got {$response->status()}";
            }
        }

        expect($failures)->toBe([]);
    });

    it('leaves the ways that are not ambiguous as they were: a session alone, a token alone, a token with the headers of the front', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'text', 'Name');
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";
        $data = ['values' => [$field->ulid => 'Saw']];
        $token = tokenFor($team['owner'], ['write']);

        inventoryCall($this, $team['owner'], 'POST', $uri, $data)->assertCreated();
        tokenCall($this, $token, 'POST', $uri, $data)->assertCreated();
        // No cookie: Sanctum starts a session for the Origin of the application, finds nobody in it and goes on to the token.
        tokenCall($this, $token, 'POST', $uri, $data, frontend())->assertCreated();
        // The token still has to have the ability, with or without those headers.
        tokenCall($this, tokenFor($team['owner'], ['read']), 'POST', $uri, $data, frontend())->assertForbidden()->assertJsonPath('code', 'token_ability_missing');

        expect(Item::query()->count())->toBe(3);
    });
});
