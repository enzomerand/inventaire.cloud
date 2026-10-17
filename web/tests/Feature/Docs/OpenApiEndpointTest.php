<?php

declare(strict_types=1);

use App\Docs\OpenApi;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/contract.php';
require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/tokens.php';

uses(RefreshDatabase::class);

/*
 * GET /api/v1/openapi.json: the document as JSON, for anyone, from a cache's point of view the same for everybody.
 */

describe('GET /api/v1/openapi.json', function (): void {
    it('answers anyone with the document as JSON', function (): void {
        $response = $this->getJson('/api/v1/openapi.json')->assertOk();
        $document = $response->json();

        expect($response->headers->get('Content-Type'))->toBe('application/json')
            ->and($document['openapi'])->toBe('3.1.0')
            ->and($document['info']['title'])->toBeString()
            ->and(array_keys($document['paths']))->toContain('/me', '/inventories/{inventory}/items', '/templates')
            ->and($document['components']['schemas'])->toHaveKey('Workspace');
    });

    it('is the document of the file with the address of the instance as its server', function (): void {
        $document = $this->getJson('/api/v1/openapi.json')->json();
        $written = contractSpec();

        expect($document['servers'])->toBe([['url' => 'http://localhost/api/v1', 'description' => $written['servers'][0]['description']]]);

        unset($document['servers'], $written['servers']);

        // What is not the servers is the same, once read back (the JSON of the file has no distinction between a list and a map of numbers).
        expect(json_decode((string) json_encode($document), true))->toBe(json_decode((string) json_encode($written), true));
    });

    it('builds the server from APP_URL and never from the Host header of the request', function (): void {
        config(['app.url' => 'https://inventaire.example/']);

        $response = $this->getJson('/api/v1/openapi.json', ['Host' => 'evil.example']);

        expect($response->json('servers.0.url'))->toBe('https://inventaire.example/api/v1')
            ->and($response->getContent())->not->toContain('evil.example');
    });

    it('may be kept by any cache for an hour, and says so', function (): void {
        $response = $this->getJson('/api/v1/openapi.json');

        expect($response->headers->hasCacheControlDirective('public'))->toBeTrue()
            ->and($response->headers->getCacheControlDirective('max-age'))->toBe('3600')
            ->and($response->headers->hasCacheControlDirective('private'))->toBeFalse()
            ->and($response->headers->getCookies())->toBe([]);
    });

    it('needs no account, no token, and ignores one that is wrong', function (): void {
        $this->getJson('/api/v1/openapi.json')->assertOk();
        $this->withToken('inv_not-a-token')->getJson('/api/v1/openapi.json')->assertOk();
        $this->withToken(tokenFor(inventoryPerson(), ['read']))->getJson('/api/v1/openapi.json')->assertOk();
    });

    it('leaves slashes and accents as they are in the text', function (): void {
        $body = (string) $this->getJson('/api/v1/openapi.json')->getContent();

        expect($body)->toContain('"/inventories/{inventory}/items"')->toContain('inv_…')
            ->and($body)->not->toContain('\\/')->not->toContain('\\u2026');
    });

    it('is the same in every language', function (): void {
        $french = $this->getJson('/api/v1/openapi.json', ['Accept-Language' => 'fr'])->getContent();
        $english = $this->getJson('/api/v1/openapi.json', ['Accept-Language' => 'en'])->getContent();

        expect($french)->toBe($english);
    });

    it('is limited in pace with the public pages, in JSON like the rest of the API', function (): void {
        config(['inventories.public_pages_per_minute' => 1]);

        $this->getJson('/api/v1/openapi.json')->assertOk();
        $refused = $this->getJson('/api/v1/openapi.json')->assertTooManyRequests();

        expect($refused->json('code'))->toBe('too_many_requests')->and($refused->headers->has('Retry-After'))->toBeTrue();
    });

    it('asks not to be indexed, like every answer that is not a public page', function (): void {
        expect($this->getJson('/api/v1/openapi.json')->headers->get('X-Robots-Tag'))->toBe('noindex');
    });

    it('is not a route a token needs an ability for, nor one that refuses a token', function (): void {
        $route = apiRoutes()['api.v1.openapi'];
        $middleware = apiRouteMiddleware($route);

        expect(array_filter($middleware, fn (string $name): bool => str_contains($name, 'RequireTokenAbility') || str_contains($name, 'RefuseApiTokens') || str_contains($name, 'Authenticate')))->toBe([])
            ->and($middleware)->toContain('Illuminate\\Routing\\Middleware\\ThrottleRequests:public-pages');
    });

    it('is served from the cache once parsed: the file is not parsed for each request', function (): void {
        $this->getJson('/api/v1/openapi.json')->assertOk();

        $key = 'openapi:v1:'.hash('xxh128', (string) file_get_contents(app(OpenApi::class)->path()));

        expect(cache()->has($key))->toBeTrue();
    });
});
