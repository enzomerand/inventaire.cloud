<?php

declare(strict_types=1);

use App\Support\Seo\PageMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/templates.php';

uses(RefreshDatabase::class);

/*
 * What the public pages say to search engines (title, description, canonical address, languages, Open Graph, structured
 * data), robots.txt and the sitemap, with indexing allowed (APP_INDEXABLE) and without. The pages are parsed, not matched
 * as text.
 */

beforeEach(fn () => templateFixtures());

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/templates-*')) ?: [] as $folder) {
        templateRemoveFolder($folder);
    }
});

/**
 * The values of the `content` of the `<meta>` tags with this attribute value (`name` or `property`).
 *
 * @return list<string>
 */
function seoMeta(DOMXPath $page, string $attribute, string $value): array
{
    return templateAttributes($page, "//head/meta[@{$attribute}=\"{$value}\"]", 'content');
}

/**
 * The structured data of a page, decoded, one entry for each script.
 *
 * @return list<array<string, mixed>>
 */
function seoJsonLd(DOMXPath $page): array
{
    $found = [];

    foreach ($page->query('//script[@type="application/ld+json"]') ?: [] as $script) {
        $found[] = json_decode($script->textContent, true, flags: JSON_THROW_ON_ERROR);
    }

    return $found;
}

describe('with indexing off, which is the default', function (): void {
    it('asks every page not to be indexed', function (string $path): void {
        $page = templateDom($this->get($path)->assertOk());

        expect(config('app.indexable'))->toBeFalse()
            ->and(seoMeta($page, 'name', 'robots'))->toBe(['noindex, nofollow']);
    })->with(['/', '/login', '/templates', '/en/templates', '/templates/sample-tools', '/en/templates/sample-home']);

    it('asks the pages of the application not to be indexed', function (): void {
        $page = templateDom($this->actingAs(inventoryPerson())->get('/app/workspaces')->assertOk());

        expect(seoMeta($page, 'name', 'robots'))->toBe(['noindex, nofollow']);
    });

    it('disallows everything in robots.txt', function (): void {
        $response = $this->get('/robots.txt')->assertOk();

        expect($response->getContent())->toBe("User-agent: *\nDisallow: /\n")
            ->and($response->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8');
    });

    it('has no sitemap', function (): void {
        $this->get('/sitemap.xml')->assertNotFound();
    });

    it('has no static robots.txt in front of the route', function (): void {
        expect(file_exists(public_path('robots.txt')))->toBeFalse();
    });
});

describe('with indexing on', function (): void {
    beforeEach(fn () => config(['app.indexable' => true]));

    it('asks the public pages to be indexed and the others not to be', function (): void {
        foreach (['/', '/templates', '/en/templates', '/templates/sample-tools', '/en/templates/sample-tools'] as $path) {
            expect(seoMeta(templateDom($this->get($path)->assertOk()), 'name', 'robots'))->toBe(['index, follow']);
        }

        expect(seoMeta(templateDom($this->get('/login')->assertOk()), 'name', 'robots'))->toBe(['noindex, nofollow'])
            ->and(seoMeta(templateDom($this->actingAs(inventoryPerson())->get('/app/workspaces')->assertOk()), 'name', 'robots'))->toBe(['noindex, nofollow']);
    });

    it('describes the gallery', function (): void {
        $page = templateDom($this->get('/templates')->assertOk());

        expect(templateTexts($page, '//title'))->toBe(["Modèles d'inventaire gratuits · ".config('app.name')])
            ->and(seoMeta($page, 'name', 'description'))->toBe(["Modèles d'inventaire gratuits : logement, parc informatique, stock, collections et plus encore. Choisissez-en un et démarrez en une minute."])
            ->and(templateAttributes($page, '//head/link[@rel="canonical"]', 'href'))->toBe(['http://localhost/templates'])
            ->and(templateAttributes($page, '//head/link[@rel="alternate"]', 'hreflang'))->toBe(['fr', 'en', 'x-default'])
            ->and(templateAttributes($page, '//head/link[@rel="alternate"]', 'href'))->toBe(['http://localhost/templates', 'http://localhost/en/templates', 'http://localhost/templates'])
            ->and(seoMeta($page, 'property', 'og:title'))->toBe(["Modèles d'inventaire gratuits"])
            ->and(seoMeta($page, 'property', 'og:description'))->toBe(seoMeta($page, 'name', 'description'))
            ->and(seoMeta($page, 'property', 'og:url'))->toBe(['http://localhost/templates'])
            ->and(seoMeta($page, 'property', 'og:type'))->toBe(['website'])
            ->and(seoMeta($page, 'property', 'og:site_name'))->toBe([config('app.name')])
            ->and(seoMeta($page, 'property', 'og:locale'))->toBe(['fr_FR'])
            ->and(seoMeta($page, 'property', 'og:locale:alternate'))->toBe(['en_US']);
    });

    it('describes the gallery in English at its own address', function (): void {
        $page = templateDom($this->get('/en/templates')->assertOk());

        expect(templateAttributes($page, '//head/link[@rel="canonical"]', 'href'))->toBe(['http://localhost/en/templates'])
            ->and(templateAttributes($page, '//head/link[@rel="alternate"]', 'href'))->toBe(['http://localhost/templates', 'http://localhost/en/templates', 'http://localhost/templates'])
            ->and(seoMeta($page, 'property', 'og:url'))->toBe(['http://localhost/en/templates'])
            ->and(seoMeta($page, 'property', 'og:locale'))->toBe(['en_US'])
            ->and(seoMeta($page, 'property', 'og:locale:alternate'))->toBe(['fr_FR'])
            ->and(seoMeta($page, 'name', 'description')[0])->toStartWith('Free inventory templates for home');
    });

    it('describes a template with its summary', function (): void {
        $page = templateDom($this->get('/en/templates/sample-shop')->assertOk());

        expect(seoMeta($page, 'name', 'description'))->toBe(['Track your products, quantities and prices, with the barcode of each product.'])
            ->and(templateAttributes($page, '//head/link[@rel="canonical"]', 'href'))->toBe(['http://localhost/en/templates/sample-shop'])
            ->and(templateAttributes($page, '//head/link[@rel="alternate"]', 'hreflang'))->toBe(['fr', 'en', 'x-default'])
            ->and(templateAttributes($page, '//head/link[@rel="alternate"]', 'href'))->toBe(['http://localhost/templates/sample-shop', 'http://localhost/en/templates/sample-shop', 'http://localhost/templates/sample-shop'])
            ->and(seoMeta($page, 'property', 'og:title'))->toBe(['Shop stock: free inventory template'])
            ->and(seoMeta($page, 'property', 'og:url'))->toBe(['http://localhost/en/templates/sample-shop'])
            ->and(seoMeta($page, 'property', 'og:type'))->toBe(['website'])
            ->and(seoMeta($page, 'property', 'og:locale'))->toBe(['en_US'])
            ->and(seoMeta($page, 'property', 'og:locale:alternate'))->toBe(['fr_FR']);
    });

    it('describes the home page, which exists once', function (): void {
        $page = templateDom($this->get('/', ['Accept-Language' => 'fr'])->assertOk());

        expect(templateTexts($page, '//title'))->toBe(['Des inventaires simples et gratuits · '.config('app.name')])
            ->and(seoMeta($page, 'name', 'description'))->toBe(["Un outil d'inventaire gratuit pour les professionnels et les particuliers."])
            ->and(templateAttributes($page, '//head/link[@rel="canonical"]', 'href'))->toBe(['http://localhost/'])
            ->and($page->query('//head/link[@rel="alternate"]')->length)->toBe(0)
            ->and(seoMeta($page, 'property', 'og:url'))->toBe(['http://localhost/'])
            ->and(seoJsonLd($page))->toBe([]);
    });

    it('builds the addresses from APP_URL, whatever the Host header says', function (): void {
        config(['app.url' => 'https://inventaire.example']);

        $page = templateDom($this->get('/templates/sample-tools', ['Host' => 'evil.example'])->assertOk());

        expect(templateAttributes($page, '//head/link[@rel="canonical"]', 'href'))->toBe(['https://inventaire.example/templates/sample-tools'])
            ->and(implode(' ', templateAttributes($page, '//head/link[@rel="alternate"]', 'href')))->not->toContain('evil.example')
            ->and(seoMeta($page, 'property', 'og:url'))->toBe(['https://inventaire.example/templates/sample-tools']);
    });

    it('escapes what a file says in the tags', function (): void {
        $folder = templateFolder();
        $data = templateData('sample-tools');
        $data['summary']['en'] = 'Find "every" tool under <5 EUR & \'well\'';
        file_put_contents($folder.'/risky.json', json_encode($data));
        templateCatalogueFrom($folder);

        $response = $this->get('/en/templates/risky')->assertOk();
        $page = templateDom($response);

        expect(seoMeta($page, 'name', 'description'))->toBe(['Find "every" tool under <5 EUR & \'well\''])
            ->and($response->getContent())->toContain('content="Find &quot;every&quot; tool under &lt;5 EUR &amp; &#039;well&#039;"');
    });
});

describe('the structured data', function (): void {
    beforeEach(fn () => config(['app.indexable' => true]));

    it('describes the gallery as a collection page with the list of its templates', function (): void {
        $data = seoJsonLd(templateDom($this->get('/en/templates')->assertOk()));

        expect($data)->toHaveCount(1)
            ->and($data[0])->toMatchArray([
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => 'Free inventory templates',
                'url' => 'http://localhost/en/templates',
                'inLanguage' => 'en',
                'isPartOf' => ['@type' => 'WebSite', 'name' => config('app.name'), 'url' => 'http://localhost/'],
            ])
            ->and($data[0]['mainEntity'])->toBe([
                '@type' => 'ItemList',
                'numberOfItems' => 4,
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home inventory', 'url' => 'http://localhost/en/templates/sample-home'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Shop stock', 'url' => 'http://localhost/en/templates/sample-shop'],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => 'Records', 'url' => 'http://localhost/en/templates/sample-records'],
                    ['@type' => 'ListItem', 'position' => 4, 'name' => 'Tools', 'url' => 'http://localhost/en/templates/sample-tools'],
                ],
            ]);
    });

    it('describes a template as a web page with a breadcrumb', function (): void {
        $data = seoJsonLd(templateDom($this->get('/templates/sample-tools')->assertOk()));

        expect($data)->toHaveCount(2)
            ->and($data[0])->toBe([
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => 'Outils : modèle d\'inventaire gratuit',
                'description' => 'Retrouvez chaque outil, son état et la personne à qui vous l\'avez prêté.',
                'url' => 'http://localhost/templates/sample-tools',
                'inLanguage' => 'fr',
                'about' => ['@type' => 'Thing', 'name' => 'Outils', 'description' => 'Retrouvez chaque outil, son état et la personne à qui vous l\'avez prêté.'],
                'isPartOf' => ['@type' => 'WebSite', 'name' => config('app.name'), 'url' => 'http://localhost/'],
            ])
            ->and($data[1])->toBe([
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Accueil', 'item' => 'http://localhost/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Modèles d\'inventaire', 'item' => 'http://localhost/templates'],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => 'Outils', 'item' => 'http://localhost/templates/sample-tools'],
                ],
            ]);
    });

    it('is written so that nothing in it can end the script element', function (): void {
        $hazard = '</script><script>alert(1)</script> & <!-- "quoted" \'text\' é </';
        $meta = new PageMeta('Title', 'Description', 'http://localhost/', 'fr', jsonLd: [['@type' => 'WebPage', 'name' => $hazard]]);

        [$json] = $meta->jsonLdScripts();

        expect($json)->not->toContain('<')->and($json)->not->toContain('>')->and($json)->not->toContain('&')->and($json)->not->toContain('"quoted"')
            ->and(json_decode($json, true)['name'])->toBe($hazard);
    });

    it('survives a page whose texts look like markup', function (): void {
        $folder = templateFolder();
        $data = templateData('sample-tools');
        // Tags are refused by the validator, a lone "</" is not.
        $data['name']['en'] = 'Tools </ & more <3';
        $data['summary']['en'] = 'Find "every" tool </ fast';
        file_put_contents($folder.'/risky.json', json_encode($data));
        templateCatalogueFrom($folder);

        $response = $this->get('/en/templates/risky')->assertOk();
        $scripts = seoJsonLd(templateDom($response));

        expect($scripts[0]['about']['name'])->toBe('Tools </ & more <3')
            ->and($scripts[0]['description'])->toBe('Find "every" tool </ fast')
            ->and($scripts[1]['itemListElement'][2]['name'])->toBe('Tools </ & more <3')
            ->and(substr_count($response->getContent(), '<script type="application/ld+json">'))->toBe(2)
            ->and($response->getContent())->not->toContain('</ & more');
    });

    it('turns the code of a language into its Open Graph locale', function (string $locale, string $og): void {
        expect((new PageMeta('T', 'D', 'http://localhost/', $locale))->ogLocale())->toBe($og);
    })->with([['fr', 'fr_FR'], ['en', 'en_US'], ['pt-BR', 'pt_BR'], ['pt_BR', 'pt_BR'], ['xx', 'xx_XX']]);
});

describe('robots.txt with indexing on', function (): void {
    beforeEach(fn () => config(['app.indexable' => true]));

    it('allows the public pages, keeps robots out of the application and announces the sitemap', function (): void {
        $response = $this->get('/robots.txt')->assertOk();

        expect($response->getContent())->toBe("User-agent: *\nAllow: /\nDisallow: /app\nDisallow: /api\nDisallow: /install\nDisallow: /login\n\nSitemap: http://localhost/sitemap.xml\n")
            ->and($response->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8');
    });

    it('announces the sitemap at APP_URL', function (): void {
        config(['app.url' => 'https://inventaire.example/']);

        expect($this->get('/robots.txt', ['Host' => 'evil.example'])->getContent())->toContain('Sitemap: https://inventaire.example/sitemap.xml');
    });

    it('sets no cookie, here and on the sitemap', function (string $path): void {
        $response = $this->get($path)->assertOk();

        expect($response->headers->getCookies())->toBe([]);
    })->with(['/robots.txt', '/sitemap.xml']);
});

describe('the sitemap', function (): void {
    beforeEach(fn () => config(['app.indexable' => true]));

    /**
     * The sitemap, parsed: the `<url>` entries as ['loc' => ..., 'alternates' => [hreflang => href]].
     *
     * @return list<array{loc: string, alternates: array<string, string>}>
     */
    function sitemapEntries(mixed $test): array
    {
        $response = $test->get('/sitemap.xml')->assertOk();
        $document = new DOMDocument;

        expect($document->loadXML($response->getContent()))->toBeTrue();

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xpath->registerNamespace('x', 'http://www.w3.org/1999/xhtml');
        $entries = [];

        foreach ($xpath->query('/s:urlset/s:url') ?: [] as $url) {
            $alternates = [];

            foreach ($xpath->query('x:link[@rel="alternate"]', $url) ?: [] as $link) {
                /** @var DOMElement $link */
                $alternates[$link->getAttribute('hreflang')] = $link->getAttribute('href');
            }

            $entries[] = ['loc' => (string) $xpath->evaluate('string(s:loc)', $url), 'alternates' => $alternates];
        }

        return $entries;
    }

    it('is XML', function (): void {
        $response = $this->get('/sitemap.xml')->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/xml; charset=UTF-8')
            ->and($response->getContent())->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
            ->and($response->getContent())->toContain('xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"')
            ->and($response->getContent())->toContain('xmlns:xhtml="http://www.w3.org/1999/xhtml"');
    });

    it('lists the home page, the gallery and each template in every language, with their alternates', function (): void {
        $entries = sitemapEntries($this);

        expect(array_column($entries, 'loc'))->toBe([
            'http://localhost/',
            'http://localhost/templates',
            'http://localhost/en/templates',
            'http://localhost/templates/sample-home', 'http://localhost/en/templates/sample-home',
            'http://localhost/templates/sample-records', 'http://localhost/en/templates/sample-records',
            'http://localhost/templates/sample-shop', 'http://localhost/en/templates/sample-shop',
            'http://localhost/templates/sample-tools', 'http://localhost/en/templates/sample-tools',
        ]);

        // The home page exists once.
        expect($entries[0]['alternates'])->toBe([]);

        // The others list every language, themselves included, and an x-default.
        foreach (array_slice($entries, 1) as $entry) {
            $slug = basename($entry['loc']);
            $path = $slug === 'templates' ? '/templates' : "/templates/{$slug}";

            expect($entry['alternates'])->toBe([
                'fr' => 'http://localhost'.$path,
                'en' => 'http://localhost/en'.$path,
                'x-default' => 'http://localhost'.$path,
            ]);
        }
    });

    it('has no lastmod', function (): void {
        expect($this->get('/sitemap.xml')->getContent())->not->toContain('lastmod');
    });

    it('builds the addresses from APP_URL', function (): void {
        config(['app.url' => 'https://inventaire.example/']);

        $entries = sitemapEntries($this);

        expect($entries[0]['loc'])->toBe('https://inventaire.example/')
            ->and($entries[4]['loc'])->toBe('https://inventaire.example/en/templates/sample-home');
    });

    it('lists a template in the languages it is written in only, and the gallery in all', function (): void {
        config(['app.locale_names' => ['fr' => 'Français', 'en' => 'English', 'de' => 'Deutsch']]);

        $entries = sitemapEntries($this);

        expect(array_column($entries, 'loc'))->toContain('http://localhost/de/templates')
            ->and(array_column($entries, 'loc'))->not->toContain('http://localhost/de/templates/sample-tools')
            ->and(array_keys($entries[1]['alternates']))->toBe(['fr', 'en', 'de', 'x-default']);
    });

    it('lists only the templates the catalogue holds', function (): void {
        templateCatalogueFrom(storage_path('framework/testing/no-such-folder'));

        expect(array_column(sitemapEntries($this), 'loc'))->toBe(['http://localhost/', 'http://localhost/templates', 'http://localhost/en/templates']);
    });
});
