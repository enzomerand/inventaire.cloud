<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../../Support/inventories.php';
require_once __DIR__.'/../../../Support/tokens.php';

uses(RefreshDatabase::class);

/*
 * Two limits can apply to one request: the pace of the token and the limit of an endpoint. The headers of an answer describe the one with
 * the fewest requests left (the introduction of the OpenAPI document says so), and a 429 carries the headers of the limit that was
 * exceeded, with Retry-After, whichever of the two it is.
 */

describe('the rate-limit headers of an answer', function (): void {
    it('describe the pace of the token when no limit of an endpoint is closer', function (): void {
        config(['inventories.api_token_requests_per_minute' => 50]);
        $token = tokenFor(inventoryPerson('Ada'), ['read']);

        tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk()->assertHeader('X-RateLimit-Limit', '50')->assertHeader('X-RateLimit-Remaining', '49');
    });

    it('describe the limit of the endpoint when it is the closer one', function (): void {
        Queue::fake();
        config(['inventories.api_token_requests_per_minute' => 50, 'inventories.exports_per_minute' => 3]);
        $world = inventoryTeam();
        $inventory = inventoryIn($world['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/exports";
        $token = tokenFor($world['owner'], ['write']);

        tokenCall($this, $token, 'POST', $uri, ['format' => 'csv'])->assertStatus(202)->assertHeader('X-RateLimit-Limit', '3')->assertHeader('X-RateLimit-Remaining', '2');
        tokenCall($this, $token, 'POST', $uri, ['format' => 'csv'])->assertStatus(202)->assertHeader('X-RateLimit-Limit', '3')->assertHeader('X-RateLimit-Remaining', '1');
    });

    it('describe the pace of the token once it is the closer one', function (): void {
        Queue::fake();
        config(['inventories.api_token_requests_per_minute' => 2, 'inventories.exports_per_minute' => 30]);
        $world = inventoryTeam();
        $inventory = inventoryIn($world['workspace']);
        $token = tokenFor($world['owner'], ['write']);

        tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory->ulid}/exports", ['format' => 'csv'])
            ->assertStatus(202)->assertHeader('X-RateLimit-Limit', '2')->assertHeader('X-RateLimit-Remaining', '1');
    });

    it('are those of the limit of the endpoint on a 429 that it answers, with Retry-After', function (): void {
        Queue::fake();
        config(['inventories.api_token_requests_per_minute' => 50, 'inventories.exports_per_minute' => 1]);
        $world = inventoryTeam();
        $inventory = inventoryIn($world['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/exports";
        $token = tokenFor($world['owner'], ['write']);

        tokenCall($this, $token, 'POST', $uri, ['format' => 'csv'])->assertStatus(202);
        $refused = tokenCall($this, $token, 'POST', $uri, ['format' => 'csv'])->assertTooManyRequests();

        expect($refused->json('code'))->toBe('too_many_requests')
            ->and($refused->headers->get('X-RateLimit-Limit'))->toBe('1')
            ->and($refused->headers->get('X-RateLimit-Remaining'))->toBe('0')
            ->and((int) $refused->headers->get('Retry-After'))->toBeBetween(1, 60);
    });

    it('are those of the pace of the token on a 429 that it answers, with Retry-After', function (): void {
        config(['inventories.api_token_requests_per_minute' => 1]);
        $token = tokenFor(inventoryPerson('Ada'), ['read']);

        tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk();
        $refused = tokenCall($this, $token, 'GET', '/api/v1/me')->assertTooManyRequests();

        expect($refused->headers->get('X-RateLimit-Limit'))->toBe('1')
            ->and($refused->headers->get('X-RateLimit-Remaining'))->toBe('0')
            ->and((int) $refused->headers->get('Retry-After'))->toBeBetween(1, 60)
            ->and((int) $refused->headers->get('X-RateLimit-Reset'))->toBeGreaterThan(time() - 1);
    });
});
