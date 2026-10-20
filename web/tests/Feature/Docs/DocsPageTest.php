<?php

declare(strict_types=1);

use App\Docs\ApiReference;
use App\Docs\CurlExample;
use App\Docs\OpenApi;
use App\Docs\SchemaDescriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/contract.php';
require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/templates.php';

uses(RefreshDatabase::class);

/*
 * The page /docs/api: public, drawn by the server from the document, with the head a public page has (title, description,
 * canonical address, robots), the chrome in the language of the request, an anchor for every operation and every schema, and
 * nothing loaded from another host.
 */

beforeEach(fn () => templateFixtures());

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/templates-*')) ?: [] as $folder) {
        templateRemoveFolder($folder);
    }
});

/**
 * The page in French, which is the language of the instance, whatever the browser of the test client says.
 *
 * @param  array<string, string>  $headers
 */
function docsPage(object $test, array $headers = []): TestResponse
{
    return $test->get('/docs/api', $headers + ['Accept-Language' => '']);
}

/**
 * The ids of every element of a page.
 *
 * @return list<string>
 */
function docsIds(DOMXPath $page): array
{
    return templateAttributes($page, '//*[@id]', 'id');
}

describe('the page', function (): void {
    it('is public and drawn by the server', function (): void {
        $response = docsPage($this)->assertOk();

        expect($response->headers->get('Content-Type'))->toStartWith('text/html')
            ->and($response->getContent())->toContain('<main')
            ->and(route('docs.api', absolute: false))->toBe('/docs/api');
    });

    it('is in French by default: the title, the heading and the labels', function (): void {
        $page = templateDom(docsPage($this)->assertOk());

        expect(templateTexts($page, '//head/title'))->toBe(["Documentation de l'API · ".config('app.name')])
            ->and(templateTexts($page, '//h1'))->toBe(["Documentation de l'API"])
            ->and(templateAttributes($page, '/html', 'lang'))->toBe(['fr'])
            ->and(templateTexts($page, '//h2[@id="schemas"]'))->toBe(['Schémas'])
            ->and(templateTexts($page, '//h4'))->toContain('Réponses', 'Exemple', 'Paramètres');
    });

    it('is in the language of the request otherwise', function (): void {
        $page = templateDom(docsPage($this, ['Accept-Language' => 'en'])->assertOk());

        expect(templateTexts($page, '//h1'))->toBe(['API documentation'])
            ->and(templateTexts($page, '//h2[@id="schemas"]'))->toBe(['Schemas'])
            ->and(templateTexts($page, '//h4'))->toContain('Responses', 'Example');
    });

    it('says in which language the text of the document is written: the one of the page, since the document is translated into it', function (): void {
        $french = templateDom(docsPage($this)->assertOk());
        $english = templateDom(docsPage($this, ['Accept-Language' => 'en'])->assertOk());

        expect(templateAttributes($french, '//*[@id="createWorkspace-title"]', 'lang'))->toBe(['fr'])
            ->and(templateTexts($french, '//*[@id="createWorkspace-title"]'))->toBe(['Créer un espace de travail'])
            ->and(templateAttributes($english, '//*[@id="createWorkspace-title"]', 'lang'))->toBe(['en'])
            ->and(templateTexts($english, '//*[@id="createWorkspace-title"]'))->toBe(['Create a workspace']);
    });

    it('has an anchor for every operation of the document, and one for every schema', function (): void {
        $ids = docsIds(templateDom(docsPage($this)->assertOk()));

        foreach (array_keys(contractOperations()) as $operationId) {
            expect($ids)->toContain($operationId);
        }

        foreach (array_keys(contractSpec()['components']['schemas']) as $schema) {
            expect($ids)->toContain('schema-'.$schema);
        }

        expect($ids)->toContain('schemas', 'tag-account', 'tag-items', 'intro-authentication', 'intro-rate-limits');
    });

    it('has no anchor twice, and every link to an anchor of the page leads to one', function (): void {
        $page = templateDom(docsPage($this)->assertOk());
        $ids = docsIds($page);
        $links = array_filter(templateAttributes($page, '//a[@href]', 'href'), fn (string $href): bool => str_starts_with($href, '#'));

        expect($ids)->toHaveCount(count(array_unique($ids)));

        foreach (array_unique($links) as $link) {
            expect($ids)->toContain(substr($link, 1));
        }

        expect(count($links))->toBeGreaterThan(200);
    });

    it('lists what each operation takes and answers: the method and path, the ability, the parameters, the body, the codes and a command', function (): void {
        $page = templateDom(docsPage($this)->assertOk());
        $operation = fn (string $id, string $query): array => templateTexts($page, '//article[@id="'.$id.'"]'.$query);

        expect($operation('createItem', '//div[contains(@class, "card-header")]//span[contains(@class, "bg-green-lt")]'))->toBe(['POST'])
            ->and($operation('createItem', '//div[contains(@class, "card-header")]//code'))->toBe(['/inventories/{inventory}/items'])
            ->and($operation('createItem', '//span[contains(@class, "bg-azure-lt")]'))->toBe(['Permission du jeton requise : write'])
            ->and($operation('listTemplates', '//div[contains(@class, "card-header")]//span[contains(@class, "bg-green-lt")]'))->toBe(['Aucun jeton requis'])
            ->and($operation('listItems', '//table//th[@scope="row"]'))->toContain('inventory', 'page', 'q', 'sort')
            ->and($operation('createExport', '//table//th[@scope="row"]'))->toContain('format', 'scope', 'export_limit_reached', 'zip_without_files', 'too_many_requests', 'token_ability_missing')
            ->and($operation('createWorkspace', '//div[contains(@class, "card-body")]//span[contains(@class, "badge")]'))->toBe(['201', '401', '403', '409', '422', '429'])
            ->and($operation('getInventory', '//pre'))->toBe(['curl -H "Authorization: Bearer inv_…" http://localhost/api/v1/inventories/INVENTORY_ID']);
    });

    it('links the types to the schemas', function (): void {
        $page = templateDom(docsPage($this)->assertOk());

        expect(templateAttributes($page, '//article[@id="listWorkspaces"]//a', 'href'))->toContain('#schema-Workspace', '#schema-Error')
            ->and(templateAttributes($page, '//article[@id="schema-Template"]//a', 'href'))->toContain('#schema-TemplateBase');
    });

    it('gives the base address, a way to get the document and a pointer to the tokens', function (): void {
        $page = templateDom(docsPage($this)->assertOk());

        expect(templateTexts($page, '//dd/code'))->toContain('http://localhost/api/v1')
            ->and(templateAttributes($page, '//a[contains(., "spécification OpenAPI")]', 'href'))->toBe(['/api/v1/openapi.json'])
            ->and(templateTexts($page, '//a[contains(., "spécification OpenAPI")]'))->toBe(['Télécharger la spécification OpenAPI'])
            ->and(templateAttributes($page, '//a[contains(., "jeton") and not(starts-with(@href, "#"))]', 'href'))->toBe(['/app/account']);
    });

    it('shows the introduction of the document, its Markdown rendered, in the language of the page', function (): void {
        $english = templateDom(docsPage($this, ['Accept-Language' => 'en'])->assertOk());
        $french = templateDom(docsPage($this, ['Accept-Language' => 'fr'])->assertOk());

        expect(templateTexts($english, '//h2[starts-with(@id, "intro-")]'))->toContain('Authentication', 'Abilities', 'Rate limits', 'Errors', 'Pagination')
            ->and(templateTexts($english, '//section[@aria-label="Introduction"]//pre'))->toContain('Authorization: Bearer inv_…')
            ->and(templateTexts($french, '//h2[starts-with(@id, "intro-")]'))->toContain('Authentification', 'Permissions', 'Limites de débit', 'Erreurs', 'Pagination')
            ->and(templateTexts($french, '//section[@aria-label="Introduction"]//pre'))->toContain('Authorization: Bearer inv_…');
    });

    it('keeps its tables and its commands inside their own container, so that the page itself never scrolls sideways', function (): void {
        $page = templateDom(docsPage($this)->assertOk());
        $tables = $page->query('//table') ?: [];
        $loose = [];

        foreach ($tables as $table) {
            $scrolls = $page->query('ancestor::div[contains(concat(" ", normalize-space(@class), " "), " table-responsive ")]', $table);

            if ($scrolls === false || $scrolls->length === 0) {
                $loose[] = $table->getNodePath();
            }
        }

        expect($tables->length)->toBeGreaterThan(150)
            ->and($loose)->toBe([])
            ->and(array_unique(templateAttributes($page, '//div[contains(@class, "table-responsive")]', 'tabindex')))->toBe(['0']);

        // The commands are focusable and scroll; the code of the introduction is a block Bootstrap lets scroll too.
        $commands = $page->query('//article//pre') ?: [];

        expect($commands->length)->toBe(47);

        foreach ($commands as $pre) {
            /** @var DOMElement $pre */
            expect($pre->getAttribute('class'))->toContain('overflow-auto')->and($pre->getAttribute('tabindex'))->toBe('0');
        }
    });

    it('loads nothing from another host', function (): void {
        $page = templateDom(docsPage($this)->assertOk());
        $loaded = [
            ...templateAttributes($page, '//script[@src]', 'src'),
            ...templateAttributes($page, '//link[@rel="stylesheet" or @rel="preload" or @rel="modulepreload"]', 'href'),
            ...templateAttributes($page, '//img[@src] | //iframe[@src] | //source[@src]', 'src'),
        ];

        foreach ($loaded as $address) {
            expect(preg_match('#^(https?:)?//(?!localhost[:/])#', $address))->toBe(0, $address);
        }

        expect(templateTexts($page, '//style'))->toBe([]);
    });

    it('is limited in pace with the other public pages', function (): void {
        config(['inventories.public_pages_per_minute' => 1]);

        docsPage($this)->assertOk();
        docsPage($this)->assertTooManyRequests();
    });

    it('answers the same for a visitor who is signed in, and shows who they are in the header only', function (): void {
        $page = templateDom($this->actingAs(inventoryPerson('Ada'))->get('/docs/api', ['Accept-Language' => ''])->assertOk());

        expect(templateTexts($page, '//h1'))->toBe(["Documentation de l'API"])
            ->and(docsIds($page))->toContain('createWorkspace');
    });
});

describe('the head of the page', function (): void {
    it('asks not to be indexed when the instance does not allow it, which is the default', function (): void {
        $response = docsPage($this)->assertOk();
        $page = templateDom($response);

        expect(config('app.indexable'))->toBeFalse()
            ->and(templateAttributes($page, '//head/meta[@name="robots"]', 'content'))->toBe(['noindex, nofollow'])
            ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex');
    });

    it('asks to be indexed when the instance allows it', function (): void {
        config(['app.indexable' => true]);

        $response = docsPage($this)->assertOk();
        $page = templateDom($response);

        expect(templateAttributes($page, '//head/meta[@name="robots"]', 'content'))->toBe(['index, follow'])
            ->and($response->headers->get('X-Robots-Tag'))->toBeNull();
    });

    it('describes itself: title, description, one canonical address and Open Graph', function (): void {
        $page = templateDom(docsPage($this)->assertOk());

        expect(templateAttributes($page, '//head/link[@rel="canonical"]', 'href'))->toBe(['http://localhost/docs/api'])
            ->and(templateAttributes($page, '//head/meta[@name="description"]', 'content')[0])->toStartWith('Référence de l\'API JSON')
            ->and(templateAttributes($page, '//head/meta[@property="og:title"]', 'content'))->toBe(["Documentation de l'API"])
            ->and(templateAttributes($page, '//head/meta[@property="og:url"]', 'content'))->toBe(['http://localhost/docs/api'])
            ->and(templateAttributes($page, '//head/link[@rel="alternate"]', 'href'))->toBe([]);
    });

    it('builds its canonical address from APP_URL, never from the Host header', function (): void {
        config(['app.url' => 'https://inventaire.example/']);

        $page = templateDom(docsPage($this, ['Host' => 'evil.example'])->assertOk());

        expect(templateAttributes($page, '//head/link[@rel="canonical"]', 'href'))->toBe(['https://inventaire.example/docs/api'])
            ->and(templateAttributes($page, '//head/meta[@property="og:url"]', 'content'))->toBe(['https://inventaire.example/docs/api']);
    });

    it('has structured data for the page and its breadcrumb', function (): void {
        $page = templateDom(docsPage($this)->assertOk());
        $data = array_map(fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), templateTexts($page, '//script[@type="application/ld+json"]'));

        expect($data)->toHaveCount(2)
            ->and($data[0])->toMatchArray(['@type' => 'WebPage', 'url' => 'http://localhost/docs/api', 'inLanguage' => 'fr'])
            ->and($data[1]['@type'])->toBe('BreadcrumbList')
            ->and(array_column($data[1]['itemListElement'], 'item'))->toBe(['http://localhost/', 'http://localhost/docs/api']);
    });

    it('varies on the language of the browser and the cookie of the language switcher, and is kept by no shared cache', function (): void {
        $response = docsPage($this)->assertOk();

        expect($response->headers->all('vary'))->toContain('Accept-Language, Cookie')
            ->and($response->headers->hasCacheControlDirective('private'))->toBeTrue()
            ->and($response->headers->hasCacheControlDirective('no-cache'))->toBeTrue();
    });
});

describe('the links to the page', function (): void {
    it('is in the footer of the public pages and of the application', function (string $path): void {
        $page = templateDom($this->actingAs(inventoryPerson())->get($path, ['Accept-Language' => ''])->assertOk());

        expect(templateAttributes($page, '//footer//a[normalize-space(.)="API"]', 'href'))->toBe(['/docs/api']);
    })->with(['/', '/templates', '/app/workspaces', '/docs/api']);

    it('is in the footer for a visitor who is not signed in', function (): void {
        $page = templateDom($this->get('/', ['Accept-Language' => ''])->assertOk());

        expect(templateAttributes($page, '//footer//a[normalize-space(.)="API"]', 'href'))->toBe(['/docs/api']);
    });

    it('is in the sitemap when the instance allows indexing, once and with no other language', function (): void {
        config(['app.indexable' => true]);

        $document = new DOMDocument;
        $document->loadXML((string) $this->get('/sitemap.xml')->assertOk()->getContent());
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        expect($xpath->query('//s:url[s:loc="http://localhost/docs/api"]'))->toHaveCount(1)
            ->and($xpath->query('//s:url[s:loc="http://localhost/docs/api"]/*'))->toHaveCount(1);
    });

    it('is not in the sitemap of an instance that does not allow indexing, which has none', function (): void {
        $this->get('/sitemap.xml')->assertNotFound();
    });

    it('is not closed to robots by robots.txt, while the API itself is', function (): void {
        config(['app.indexable' => true]);

        $rules = array_values(array_filter(explode("\n", (string) $this->get('/robots.txt')->getContent()), fn (string $line): bool => str_starts_with($line, 'Disallow:')));
        $closed = fn (string $path): bool => array_filter($rules, fn (string $rule): bool => str_starts_with($path, trim(substr($rule, 9)))) !== [];

        expect($closed('/docs/api'))->toBeFalse()->and($closed('/api/v1/openapi.json'))->toBeTrue();
    });
});

describe('the texts of the document', function (): void {
    /**
     * The page of a document written here, as the page would draw it.
     *
     * @return array<string, mixed>
     */
    function docsReferenceOf(string $yaml): array
    {
        $api = new OpenApi(contractScratchSpec($yaml));

        $schemas = new SchemaDescriber($api);

        return (new ApiReference($api, $schemas, new CurlExample($api, $schemas)))->page();
    }

    it('are rendered as Markdown with the HTML in them removed and the unsafe links refused', function (): void {
        $reference = docsReferenceOf(<<<'YAML'
            openapi: 3.1.0
            info:
              title: T
              version: '1'
              description: |
                ## A section

                Text with `code`, <script>alert(1)</script> and [a bad link](javascript:alert(1)) and [a good one](https://example.com/a).

                <img src=x onerror=alert(1)>
            tags:
              - name: Things
                description: Some **things** <b onclick="x()">here</b>.
            paths:
              /things:
                get:
                  tags: [Things]
                  operationId: listThings
                  summary: List
                  description: |
                    See <iframe src="https://evil.example"></iframe> [this](data:text/html;base64,AAAA).
                  responses:
                    '200':
                      description: The things <script>x()</script>.
            YAML);

        $html = (string) $reference['intro']->toHtml().$reference['tags'][0]['description']->toHtml().$reference['tags'][0]['operations'][0]['description']->toHtml().$reference['tags'][0]['operations'][0]['responses'][0]['description']->toHtml();

        expect($html)->toContain('<code>code</code>')->toContain('href="https://example.com/a"')->toContain('<strong>things</strong>')
            ->and($html)->not->toContain('<script')->not->toContain('javascript:')->not->toContain('onerror')->not->toContain('onclick')
            ->not->toContain('<iframe')->not->toContain('<img')->not->toContain('data:text')
            ->and($reference['introHeadings'])->toBe([['id' => 'intro-a-section', 'text' => 'A section']]);
    });

    it('are escaped when they are not Markdown: the summaries, the paths, the names and the codes', function (): void {
        $reference = docsReferenceOf(<<<'YAML'
            openapi: 3.1.0
            info:
              title: T
              version: '1'
            tags:
              - name: Things
            paths:
              /things/<b>:
                get:
                  tags: [Things]
                  operationId: listThings
                  summary: <script>alert(1)</script> List
                  parameters:
                    - name: '"><i>q</i>'
                      in: query
                      schema:
                        type: string
                        enum: ['<u>x</u>']
                  responses:
                    '404':
                      description: Nothing
                      x-codes:
                        - code: '<em>gone</em>'
                          description: Gone
            YAML);

        $html = view('docs.operation', ['op' => $reference['tags'][0]['operations'][0]])->render();

        expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; List')->toContain('/things/&lt;b&gt;')->toContain('&lt;em&gt;gone&lt;/em&gt;')
            ->toContain('&lt;u&gt;x&lt;/u&gt;')->toContain('&quot;&gt;&lt;i&gt;q&lt;/i&gt;')
            ->and($html)->not->toContain('<script')->not->toContain('<em>')->not->toContain('<u>')->not->toContain('<i>q');
    });
});
