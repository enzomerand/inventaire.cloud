<?php

declare(strict_types=1);

use App\Docs\OpenApi;
use App\Docs\RenderedReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/contract.php';
require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/templates.php';

uses(RefreshDatabase::class);

/*
 * The page /docs/api is 700 KB drawn from hundreds of texts, and the same for everybody who reads the same language: the long part of it
 * is kept in the cache, one entry for each language, and the page is revalidated with an ETag rather than sent again.
 */

beforeEach(fn () => templateFixtures());

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/templates-*')) ?: [] as $folder) {
        templateRemoveFolder($folder);
    }
});

/**
 * The page in a language, with the session of the test (so that the CSRF token in the frame is always the same).
 *
 * @param  array<string, string>  $headers
 */
function docsCachedPage(object $test, string $language = 'fr', array $headers = [], ?object $as = null): TestResponse
{
    $test->flushSession();
    $test->withSession(['_token' => 'a-token-for-the-test']);

    if ($as !== null) {
        $test->actingAs($as);
    }

    return $test->get('/docs/api', $headers + ['Accept-Language' => $language]);
}

describe('the reference of the page', function (): void {
    it('is drawn once for a language, then taken from the cache', function (): void {
        $first = docsCachedPage($this)->assertOk();
        $entry = Cache::get('docs:api-reference:fr');

        expect($first->getContent())->toContain('id="createItem"')
            ->and($entry)->toBeArray()
            ->and($entry['html'])->toContain('id="createItem"')
            ->and($entry['version'])->toBe(app(RenderedReference::class)->version('fr'));

        // Put something else where the HTML is kept: a request that draws the reference again would not show it.
        Cache::forever('docs:api-reference:fr', ['version' => $entry['version'], 'html' => '<p id="from-the-cache">kept</p>']);

        $second = docsCachedPage($this)->assertOk();

        expect($second->getContent())->toContain('id="from-the-cache"')->not->toContain('id="createItem"');
    });

    it('is kept for each language apart', function (): void {
        docsCachedPage($this, 'fr')->assertOk();
        docsCachedPage($this, 'en')->assertOk();

        expect(Cache::get('docs:api-reference:fr')['html'])->toContain('Sommaire')
            ->and(Cache::get('docs:api-reference:en')['html'])->toContain('Contents')
            ->and(Cache::get('docs:api-reference:en')['version'])->not->toBe(Cache::get('docs:api-reference:fr')['version']);
    });

    it('is drawn again, and the entry replaced, when the document changes', function (): void {
        docsCachedPage($this)->assertOk();
        $before = Cache::get('docs:api-reference:fr');

        $copy = contractScratchSpec(str_replace('The public API of inventaire.cloud', 'The edited API of inventaire.cloud', (string) file_get_contents(app(OpenApi::class)->path())));
        app()->instance(OpenApi::class, new OpenApi($copy));

        // What a request does, with the document the container now holds (the router keeps the controller it built for the first one).
        $html = app(RenderedReference::class)->html('fr')->toHtml();
        $after = Cache::get('docs:api-reference:fr');

        expect($after['version'])->not->toBe($before['version'])
            ->and($after['html'])->toBe($html)->not->toBe($before['html'])
            ->and($html)->toContain('The edited API of inventaire.cloud');
    });

    it('is drawn again when the address of the API changes, since the page shows it', function (): void {
        docsCachedPage($this)->assertOk();
        $before = Cache::get('docs:api-reference:fr');

        config(['app.url' => 'https://inventory.example.org']);
        $page = docsCachedPage($this)->assertOk();

        expect($page->getContent())->toContain('https://inventory.example.org/api/v1')
            ->and(Cache::get('docs:api-reference:fr')['version'])->not->toBe($before['version']);
    });

    it('is drawn again when a view of the page changes, the components it draws its tables with included', function (): void {
        foreach ([resource_path('views/docs/operation.blade.php'), resource_path('views/components/docs/rows.blade.php')] as $view) {
            $before = app(RenderedReference::class)->version('fr');
            $time = (int) filemtime($view);

            try {
                touch($view, $time + 60);
                clearstatcache(true, $view);

                expect(app(RenderedReference::class)->version('fr'))->not->toBe($before);
            } finally {
                touch($view, $time);
                clearstatcache(true, $view);
            }
        }
    });

    it('is drawn for the request when the cache cannot be reached', function (): void {
        Cache::shouldReceive('get')->andThrow(new RuntimeException('no cache'));
        Cache::shouldReceive('forever')->andThrow(new RuntimeException('no cache'));
        Cache::makePartial();

        expect(app(RenderedReference::class)->html('fr')->toHtml())->toContain('id="createItem"');
    });
});

describe('the revalidation of the page', function (): void {
    it('sends an ETag and asks to be revalidated, not shared', function (): void {
        $response = docsCachedPage($this)->assertOk();

        expect($response->headers->get('ETag'))->toMatch('/^W\/"[0-9a-f]{32}"$/')
            ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')->not->toContain('public')->not->toContain('max-age=');
    });

    it('answers 304 with no body to a request that holds the current one', function (): void {
        $etag = (string) docsCachedPage($this)->assertOk()->headers->get('ETag');

        $again = docsCachedPage($this, headers: ['If-None-Match' => $etag])->assertStatus(304);

        expect($again->getContent())->toBe('')
            ->and($again->headers->get('ETag'))->toBe($etag)
            ->and($again->headers->get('Cache-Control'))->toContain('private');
    });

    it('sends the page again to a request that holds another one', function (): void {
        docsCachedPage($this)->assertOk();

        $response = docsCachedPage($this, headers: ['If-None-Match' => 'W/"0123456789abcdef0123456789abcdef"'])->assertOk();

        expect($response->getContent())->toContain('id="createItem"');
    });

    it('does not tell another language, another person or another session that their copy is current', function (): void {
        $french = (string) docsCachedPage($this, 'fr')->assertOk()->headers->get('ETag');
        $english = (string) docsCachedPage($this, 'en')->assertOk()->headers->get('ETag');
        $signedIn = (string) docsCachedPage($this, 'fr', as: inventoryPerson('Ada'))->assertOk()->headers->get('ETag');

        expect($english)->not->toBe($french)->and($signedIn)->not->toBe($french);

        $this->flushSession();
        $this->withSession(['_token' => 'the-token-of-another-session']);
        $other = (string) $this->get('/docs/api', ['Accept-Language' => 'fr'])->assertOk()->headers->get('ETag');

        expect($other)->not->toBe($french);

        // The French copy is not current for a request in English, nor for the person who is signed in.
        docsCachedPage($this, 'en', ['If-None-Match' => $french])->assertOk();
        docsCachedPage($this, 'fr', ['If-None-Match' => $french], inventoryPerson('Grace'))->assertOk();
    });
});
