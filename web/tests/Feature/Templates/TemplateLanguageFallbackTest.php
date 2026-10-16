<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../../Support/templates.php';

/*
 * A language the application supports and a template is not written in: the page is served with the texts of the fallback
 * language (the language switcher links to it), but it is not a page of its own for the search engines. It asks not to be
 * indexed, its canonical address and its language are the ones of the fallback, and it is not an alternate of the
 * template. One written in the language is a page like the others.
 *
 * The routes of the languages are registered when the application boots, with the supported languages in the pattern of
 * `{locale}`: a third language is declared, then the routes are registered again.
 */

beforeEach(function (): void {
    templateFixtures();
    config(['app.indexable' => true]);
    supportAThirdLanguage();
});

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/templates-*')) ?: [] as $folder) {
        templateRemoveFolder($folder);
    }
});

/**
 * Declares German and registers the routes of the application again, so that `/de/templates` exists.
 */
function supportAThirdLanguage(): void
{
    config(['app.locale_names' => [...config('app.locale_names'), 'de' => 'Deutsch']]);

    Route::setRoutes(new RouteCollection);
    Route::middleware('web')->group(base_path('routes/web.php'));
    Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
}

/**
 * The content of the `<meta>` tags with this attribute value, in the head.
 *
 * @return list<string>
 */
function fallbackMeta(DOMXPath $page, string $attribute, string $value): array
{
    return templateAttributes($page, "//head/meta[@{$attribute}=\"{$value}\"]", 'content');
}

/**
 * The structured data of a page, decoded.
 *
 * @return list<array<string, mixed>>
 */
function fallbackJsonLd(DOMXPath $page): array
{
    $found = [];

    foreach ($page->query('//script[@type="application/ld+json"]') ?: [] as $script) {
        $found[] = json_decode($script->textContent, true, flags: JSON_THROW_ON_ERROR);
    }

    return $found;
}

describe('the page of a template in a language it is not written in', function (): void {
    it('is served, with the texts of the language of the instance', function (): void {
        $page = templateDom($this->get('/de/templates/sample-tools')->assertOk());

        expect(templateTexts($page, '//h1'))->toBe(['Outils'])
            ->and(templateAttributes($page, '//html', 'lang'))->toBe(['de']);
    });

    it('asks not to be indexed, in the page and in the header', function (): void {
        $response = $this->get('/de/templates/sample-tools')->assertOk();
        $page = templateDom($response);

        expect(fallbackMeta($page, 'name', 'robots'))->toBe(['noindex, nofollow'])
            ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex');
    });

    it('points its canonical address at the page in the fallback language, and says it is written in it', function (): void {
        $page = templateDom($this->get('/de/templates/sample-tools')->assertOk());
        [$webPage, $breadcrumb] = fallbackJsonLd($page);

        expect(templateAttributes($page, '//head/link[@rel="canonical"]', 'href'))->toBe(['http://localhost/templates/sample-tools'])
            ->and(fallbackMeta($page, 'property', 'og:url'))->toBe(['http://localhost/templates/sample-tools'])
            ->and(fallbackMeta($page, 'property', 'og:locale'))->toBe(['fr_FR'])
            ->and(fallbackMeta($page, 'property', 'og:locale:alternate'))->toBe(['en_US'])
            ->and($webPage['inLanguage'])->toBe('fr')
            ->and($webPage['url'])->toBe('http://localhost/templates/sample-tools')
            ->and($breadcrumb['itemListElement'][1]['item'])->toBe('http://localhost/templates')
            ->and($breadcrumb['itemListElement'][2]['item'])->toBe('http://localhost/templates/sample-tools');
    });

    it('is not one of the alternates of the template, which are the languages it is written in', function (): void {
        $page = templateDom($this->get('/de/templates/sample-tools')->assertOk());

        expect(templateAttributes($page, '//head/link[@rel="alternate"]', 'hreflang'))->toBe(['fr', 'en', 'x-default'])
            ->and(templateAttributes($page, '//head/link[@rel="alternate"]', 'href'))->toBe([
                'http://localhost/templates/sample-tools', 'http://localhost/en/templates/sample-tools', 'http://localhost/templates/sample-tools',
            ]);
    });

    it('is a page of its own, indexed, in a language it is written in', function (string $path, string $canonical, string $locale): void {
        $response = $this->get($path)->assertOk();
        $page = templateDom($response);

        expect(fallbackMeta($page, 'name', 'robots'))->toBe(['index, follow'])
            ->and($response->headers->get('X-Robots-Tag'))->toBeNull()
            ->and(templateAttributes($page, '//head/link[@rel="canonical"]', 'href'))->toBe([$canonical])
            ->and(fallbackJsonLd($page)[0]['inLanguage'])->toBe($locale);
    })->with([
        ['/templates/sample-tools', 'http://localhost/templates/sample-tools', 'fr'],
        ['/en/templates/sample-tools', 'http://localhost/en/templates/sample-tools', 'en'],
    ]);

    it('is written in the language when the file has it, and then is a page of its own', function (): void {
        $folder = templateFolder();
        $data = templateData('sample-tools');
        $data['name']['de'] = 'Werkzeug';
        $data['summary']['de'] = 'Finden Sie jedes Werkzeug wieder.';
        $data['description']['de'] = ['Ein Werkstatt-Inventar.', 'Notieren Sie die Ausleihen.'];
        $data['examples']['de'] = $data['examples']['en'];

        foreach (array_keys($data['fields']) as $position) {
            $data['fields'][$position]['name']['de'] = $data['fields'][$position]['name']['en'];

            foreach (array_keys($data['fields'][$position]['options']['choices'] ?? []) as $choice) {
                $data['fields'][$position]['options']['choices'][$choice]['label']['de'] = $data['fields'][$position]['options']['choices'][$choice]['label']['en'];
            }
        }

        file_put_contents($folder.'/sample-tools.json', json_encode($data));
        templateCatalogueFrom($folder);

        $response = $this->get('/de/templates/sample-tools')->assertOk();
        $page = templateDom($response);

        expect(templateTexts($page, '//h1'))->toBe(['Werkzeug'])
            ->and(fallbackMeta($page, 'name', 'robots'))->toBe(['index, follow'])
            ->and($response->headers->get('X-Robots-Tag'))->toBeNull()
            ->and(templateAttributes($page, '//head/link[@rel="canonical"]', 'href'))->toBe(['http://localhost/de/templates/sample-tools'])
            ->and(templateAttributes($page, '//head/link[@rel="alternate"]', 'hreflang'))->toBe(['fr', 'en', 'de', 'x-default'])
            ->and(fallbackMeta($page, 'property', 'og:locale'))->toBe(['de_DE'])
            ->and(fallbackJsonLd($page)[0]['inLanguage'])->toBe('de');
    });

    it('falls back on English when the instance speaks a language the template is not written in', function (): void {
        config(['app.default_locale' => 'de']);

        $page = templateDom($this->get('/templates/sample-tools')->assertOk());

        expect(templateTexts($page, '//h1'))->toBe(['Tools'])
            ->and(fallbackMeta($page, 'name', 'robots'))->toBe(['noindex, nofollow'])
            ->and(templateAttributes($page, '//head/link[@rel="canonical"]', 'href'))->toBe(['http://localhost/en/templates/sample-tools'])
            ->and(fallbackJsonLd($page)[0]['inLanguage'])->toBe('en');
    });

    it('does not make the gallery in that language a page that asks not to be indexed: it is drawn in the language', function (): void {
        $response = $this->get('/de/templates')->assertOk();

        expect(fallbackMeta(templateDom($response), 'name', 'robots'))->toBe(['index, follow'])
            ->and($response->headers->get('X-Robots-Tag'))->toBeNull();
    });
});
