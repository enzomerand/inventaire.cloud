<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/templates.php';

uses(RefreshDatabase::class);

/*
 * What a shared cache may do with the answers: a public page whose language is the one of its address varies on the cookie
 * (it shows whether somebody is signed in) and not on Accept-Language (which decides nothing there); the other answers
 * vary on both; and nothing the application draws is kept by a shared cache, whatever the page.
 */

beforeEach(fn () => templateFixtures());

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/templates-*')) ?: [] as $folder) {
        templateRemoveFolder($folder);
    }
});

/**
 * The headers a response varies on, whatever the number of Vary lines it has.
 *
 * @return list<string>
 */
function varyOf(TestResponse $response): array
{
    return array_values(array_filter(preg_split('/[\s,]+/', implode(', ', $response->headers->all('vary'))) ?: []));
}

describe('the Vary header', function (): void {
    it('is the cookie only for a page whose language is the one of its address', function (string $path): void {
        $response = $this->get($path, ['Accept-Language' => 'en']);

        expect(varyOf($response))->toBe(['Cookie']);
    })->with(['/templates', '/en/templates', '/templates/sample-tools', '/en/templates/sample-tools', '/templates/nothing', '/fr/templates', '/fr/templates/sample-tools']);

    it('is the same whatever the browser and the cookie of the language say', function (): void {
        $first = $this->get('/templates', ['Accept-Language' => 'en']);
        $second = $this->withUnencryptedCookie('locale', 'en')->get('/templates', ['Accept-Language' => 'de']);

        expect(varyOf($first))->toBe(varyOf($second))->and($first->getContent())->toBe($second->getContent());
    });

    it('stays Accept-Language and Cookie for the answers whose language the visitor decides', function (string $path): void {
        $response = $this->get($path);

        expect(varyOf($response))->toContain('Accept-Language')->toContain('Cookie');
    })->with(['/', '/login', '/nothing-here', '/de/templates', '/api/v1/templates']);
});

describe('the Cache-Control header', function (): void {
    it('keeps the pages away from shared caches', function (string $path): void {
        $response = $this->get($path);
        $cache = $response->headers->get('Cache-Control');

        expect($response->headers->hasCacheControlDirective('private'))->toBeTrue($path)
            ->and($response->headers->hasCacheControlDirective('no-cache'))->toBeTrue($path)
            ->and($cache)->not->toContain('public')->not->toContain('max-age')->not->toContain('s-maxage');
    })->with([
        'the home page' => '/',
        'the gallery' => '/templates',
        'the gallery in English' => '/en/templates',
        'a template' => '/templates/sample-tools',
        'a template in English' => '/en/templates/sample-tools',
        'a template that does not exist' => '/templates/nothing',
        'a page that does not exist' => '/nothing-here',
        'the redirect to the address without prefix' => '/fr/templates',
        'the login page' => '/login',
        'the sitemap' => '/sitemap.xml',
        'robots.txt' => '/robots.txt',
        'the API' => '/api/v1/templates',
    ]);

    it('keeps the pages of a signed-in person away from them too', function (): void {
        $response = $this->actingAs(inventoryPerson())->get('/app/workspaces');

        expect($response->headers->hasCacheControlDirective('private'))->toBeTrue()
            ->and($response->headers->hasCacheControlDirective('no-cache'))->toBeTrue();
    });

    it('leaves an answer that decides its own lifetime alone', function (): void {
        $this->get('/csrf-token')->assertHeader('Cache-Control', 'no-store, private');
    });
});
