<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('JSON errors', function (): void {
    it('answers an unauthenticated call in JSON even without an Accept header', function (): void {
        $response = $this->call('GET', '/api/v1/me');

        $response->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        expect($response->json())->toHaveKey('message');
    });

    it('answers an unknown API URL in JSON, not with the HTML 404 page', function (): void {
        $response = $this->call('GET', '/api/v1/nothing-here');

        $response->assertNotFound()->assertHeader('Content-Type', 'application/json');
    });

    it('answers a wrong method in JSON', function (): void {
        $this->call('DELETE', '/api/v1/me')->assertStatus(405)->assertHeader('Content-Type', 'application/json');
    });

    it('still answers web URLs with HTML', function (): void {
        $this->call('GET', '/nothing-here')->assertNotFound();

        expect($this->call('GET', '/nothing-here')->headers->get('Content-Type'))->toContain('text/html');
    });

    it('tells an API client that the instance is not installed, in JSON, without an Accept header', function (): void {
        $this->bootInstaller(installed: false);

        $this->call('GET', '/api/v1/me')->assertStatus(503)->assertJson(['code' => 'not_installed']);
    });
});

describe('the current user', function (): void {
    it('is returned to a signed-in session', function (): void {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $user->id, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'locale' => null, 'is_admin' => false]]);
    });
});

describe('stateful requests', function (): void {
    it('lists the usual development hosts and the host of APP_URL by default', function (): void {
        config(['app.url' => 'https://inventory.example.test:8443']);

        // The list is computed when the file is evaluated, from APP_URL: evaluate it again with another one.
        $sanctum = require config_path('sanctum.php');

        expect($sanctum['stateful'])->toContain('localhost')->toContain('127.0.0.1:8000')->toContain('inventory.example.test:8443');
    });

    it('honours SANCTUM_STATEFUL_DOMAINS for other hosts', function (): void {
        $previous = getenv('SANCTUM_STATEFUL_DOMAINS');
        putenv('SANCTUM_STATEFUL_DOMAINS=a.example.test,b.example.test:8443');

        try {
            $sanctum = require config_path('sanctum.php');
        } finally {
            putenv($previous === false ? 'SANCTUM_STATEFUL_DOMAINS' : 'SANCTUM_STATEFUL_DOMAINS='.$previous);
        }

        expect($sanctum['stateful'])->toBe(['a.example.test', 'b.example.test:8443']);
    });
});
