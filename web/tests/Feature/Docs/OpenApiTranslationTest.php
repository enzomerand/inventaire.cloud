<?php

declare(strict_types=1);

use App\Docs\OpenApi;
use App\Docs\OpenApiTranslation;
use App\Docs\RenderedReference;
use App\Services\LocaleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../../Support/contract.php';
require_once __DIR__.'/../../Support/templates.php';

uses(RefreshDatabase::class);

/*
 * The texts of the page /docs/api in another language: resources/openapi/v1.<language>.yaml repeats the tree of the texts of v1.yaml
 * (the summaries, descriptions and titles) translated, App\Docs\OpenApi puts them in place of the English ones for the page, and the
 * JSON the API serves stays as the file writes it.
 */

beforeEach(fn () => templateFixtures());

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/templates-*')) ?: [] as $folder) {
        templateRemoveFolder($folder);
    }
});

/**
 * The languages the document is translated into: the `v1.<language>.yaml` files next to it, as a dataset.
 *
 * @return array<string, array{string}>
 */
function translationLanguages(): array
{
    $languages = [];

    foreach (glob(dirname(__DIR__, 3).'/resources/openapi/v1.*.yaml') ?: [] as $file) {
        $language = basename($file, '.yaml');
        $language = substr($language, strlen('v1.'));
        $languages[$language] = [$language];
    }

    return $languages;
}

/**
 * What a text holds that a translation must keep as it is: its code spans (in any order), the number of its headings and the targets of its links.
 *
 * @return array{code: list<string>, headings: int, links: list<string>}
 */
function translationMarks(string $text): array
{
    preg_match_all('/`[^`]+`/', $text, $code);
    preg_match_all('/^#+ /m', $text, $headings);
    preg_match_all('/\]\([^)]+\)/', $text, $links);

    sort($code[0]);
    sort($links[0]);

    return ['code' => $code[0], 'headings' => count($headings[0]), 'links' => $links[0]];
}

/**
 * Compares a translation with the texts of the document, node by node, and lists what is wrong by kind.
 *
 * @param  array<string, list<string>>  $problems
 */
function translationCompare(mixed $english, mixed $translated, string $path, array &$problems): void
{
    if (is_string($english)) {
        if (! is_string($translated) || trim($translated) === '') {
            $problems['empty'][] = $path;

            return;
        }

        // Prose left as it was written: two words made of letters only, which a code, a path or a name never is.
        if ($english === $translated && preg_match('/[a-z]{4,} [a-z]{3,}/', $english) === 1) {
            $problems['untranslated'][] = $path;
        }

        $expected = translationMarks($english);
        $found = translationMarks($translated);

        foreach (['code', 'headings', 'links'] as $kind) {
            if ($expected[$kind] !== $found[$kind]) {
                $problems[$kind][] = $path;
            }
        }

        return;
    }

    if (! is_array($translated)) {
        $problems['tree'][] = $path.': not a map or a list';

        return;
    }

    foreach ((array) $english as $key => $value) {
        if (! array_key_exists($key, $translated)) {
            $problems['tree'][] = $path.'/'.$key.': missing';

            continue;
        }

        translationCompare($value, $translated[$key], $path.'/'.$key, $problems);
    }

    foreach (array_keys($translated) as $key) {
        if (! array_key_exists($key, (array) $english)) {
            $problems['tree'][] = $path.'/'.$key.': not in the document';
        }
    }
}

/**
 * What is wrong with the translation of the document into a language, by kind (each list is empty when all is well).
 *
 * @return array<string, list<string>>
 */
function translationProblems(string $language): array
{
    static $found = [];

    if (! isset($found[$language])) {
        $folder = dirname(__DIR__, 3).'/resources/openapi/';
        $problems = ['tree' => [], 'empty' => [], 'untranslated' => [], 'code' => [], 'headings' => [], 'links' => []];

        translationCompare(OpenApiTranslation::texts(Yaml::parseFile($folder.'v1.yaml')), Yaml::parseFile($folder.'v1.'.$language.'.yaml'), '', $problems);

        $found[$language] = $problems;
    }

    return $found[$language];
}

/**
 * A folder with a copy of the specification and, when given, a translation of it into French next to it; removed when the process ends.
 */
function translationScratch(?string $french = null): string
{
    $folder = sys_get_temp_dir().'/openapi-translation-'.bin2hex(random_bytes(6));
    mkdir($folder);
    file_put_contents($folder.'/v1.yaml', (string) file_get_contents(resource_path('openapi/v1.yaml')));

    if ($french !== null) {
        file_put_contents($folder.'/v1.fr.yaml', $french);
    }

    register_shutdown_function(function () use ($folder): void {
        array_map(unlink(...), glob($folder.'/*') ?: []);
        @rmdir($folder);
    });

    return $folder;
}

/**
 * The page in a language.
 */
function translationPage(object $test, string $language): TestResponse
{
    return $test->get('/docs/api', ['Accept-Language' => $language]);
}

describe('a translation of the document', function (): void {
    it('is named after a language the application supports', function (string $language): void {
        expect(app(LocaleResolver::class)->isSupported($language))->toBeTrue();
    })->with(translationLanguages());

    it('repeats the tree of the texts of the document: none missing, none that the document does not have', function (string $language): void {
        expect(translationProblems($language)['tree'])->toBe([]);
    })->with(translationLanguages());

    it('translates every text: none is empty, none is left as it was written', function (string $language): void {
        expect(translationProblems($language)['empty'])->toBe([])
            ->and(translationProblems($language)['untranslated'])->toBe([]);
    })->with(translationLanguages());

    it('keeps the code spans, the number of headings and the targets of the links of every text', function (string $language): void {
        expect(translationProblems($language)['code'])->toBe([])
            ->and(translationProblems($language)['headings'])->toBe([])
            ->and(translationProblems($language)['links'])->toBe([]);
    })->with(translationLanguages());

    it('is what the document says once applied: the texts are the translation, everything else is the document', function (string $language): void {
        $api = app(OpenApi::class);
        $translation = Yaml::parseFile(resource_path('openapi/v1.'.$language.'.yaml'));

        expect(OpenApiTranslation::texts($api->localized($language)))->toEqual($translation)
            ->and(OpenApiTranslation::apply($api->localized($language), (array) OpenApiTranslation::texts($api->raw())))->toEqual($api->raw());
    })->with(translationLanguages());
});

describe('OpenApiTranslation', function (): void {
    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Title', 'version' => '1', 'description' => 'About'],
        'tags' => [['name' => 'A', 'description' => 'Tag a'], ['name' => 'B']],
        'paths' => ['/a' => ['get' => [
            'operationId' => 'getA',
            'summary' => 'Get A',
            'responses' => ['200' => ['description' => 'Done', 'content' => ['application/json' => ['example' => ['title' => 'Example'], 'schema' => ['properties' => [
                'title' => ['type' => 'string', 'description' => 'The title', 'enum' => ['x'], 'default' => 'x'],
                'default' => ['type' => 'string', 'description' => 'The default one', 'example' => 'y'],
                'enum' => ['type' => 'object', 'properties' => ['value' => ['description' => 'Inside']]],
            ]]]]]],
        ]]],
    ];

    it('lists the texts of a document, with the tree around them and a placeholder for a list item that has none', function () use ($document): void {
        expect(OpenApiTranslation::texts($document))->toBe([
            'info' => ['title' => 'Title', 'description' => 'About'],
            'tags' => [['description' => 'Tag a'], []],
            'paths' => ['/a' => ['get' => [
                'summary' => 'Get A',
                'responses' => ['200' => ['description' => 'Done', 'content' => ['application/json' => ['schema' => ['properties' => [
                    'title' => ['description' => 'The title'],
                    'default' => ['description' => 'The default one'],
                    'enum' => ['properties' => ['value' => ['description' => 'Inside']]],
                ]]]]]],
            ]]],
        ])->and(OpenApiTranslation::texts(['openapi' => '3.1.0', 'paths' => ['/a' => ['get' => ['operationId' => 'x']]]]))->toBeNull();
    });

    it('takes a text from a translation only where the document has a text', function () use ($document): void {
        $translation = [
            'openapi' => '9',
            'info' => ['title' => 'Titre', 'version' => '2', 'description' => '   '],
            'tags' => [['name' => 'Z', 'description' => 'Étiquette a'], ['name' => 'Y', 'description' => 'Étiquette inventée'], ['description' => 'Troisième']],
            'paths' => [
                '/a' => ['get' => [
                    'operationId' => 'getZ',
                    'summary' => ['not' => 'a text'],
                    'responses' => ['200' => ['description' => 'Fait', 'content' => ['application/json' => ['example' => ['title' => 'Exemple'], 'schema' => ['properties' => [
                        'title' => ['description' => 'Le titre', 'enum' => ['y'], 'default' => 'y'],
                        'default' => ['description' => 'Celui par défaut', 'example' => 'z'],
                        'enum' => ['properties' => ['value' => ['description' => 'Dedans']]],
                    ]]]]]],
                ]],
                '/b' => ['get' => ['summary' => 'Orpheline']],
            ],
        ];

        $applied = OpenApiTranslation::apply($document, $translation);

        expect($applied['openapi'])->toBe('3.1.0')
            ->and($applied['info'])->toBe(['title' => 'Titre', 'version' => '1', 'description' => 'About'])
            ->and($applied['tags'])->toBe([['name' => 'A', 'description' => 'Étiquette a'], ['name' => 'B']])
            ->and(array_keys($applied['paths']))->toBe(['/a'])
            ->and($applied['paths']['/a']['get']['operationId'])->toBe('getA')
            ->and($applied['paths']['/a']['get']['summary'])->toBe('Get A');

        $response = $applied['paths']['/a']['get']['responses']['200'];

        expect($response['description'])->toBe('Fait')
            ->and($response['content']['application/json']['example'])->toBe(['title' => 'Example'])
            ->and($response['content']['application/json']['schema']['properties'])->toBe([
                'title' => ['type' => 'string', 'description' => 'Le titre', 'enum' => ['x'], 'default' => 'x'],
                // A property can be called as a keyword is: its text is a text, and what the keywords of its schema hold is still data.
                'default' => ['type' => 'string', 'description' => 'Celui par défaut', 'example' => 'y'],
                'enum' => ['type' => 'object', 'properties' => ['value' => ['description' => 'Dedans']]],
            ]);
    });
});

describe('the document in a language', function (): void {
    it('has the texts of the translation, and the rest of the document as it is', function (): void {
        $api = app(OpenApi::class);

        expect($api->localized('fr')['paths']['/workspaces']['post']['summary'])->toBe('Créer un espace de travail')
            ->and($api->localized('fr')['paths']['/workspaces']['post']['operationId'])->toBe('createWorkspace')
            ->and(array_keys($api->localized('fr')['components']['schemas']))->toBe(array_keys($api->raw()['components']['schemas']))
            ->and($api->localized('fr')['paths']['/workspaces']['post']['responses'])->toHaveKeys(array_keys($api->raw()['paths']['/workspaces']['post']['responses']))
            ->and($api->raw()['paths']['/workspaces']['post']['summary'])->toBe('Create a workspace');
    });

    it('is the document itself for the language of the file, for a language with no translation and for what is no language', function (string $language): void {
        $api = app(OpenApi::class);

        expect($api->localized($language))->toBe($api->raw())
            ->and($api->localizedHash($language))->toBe($api->hash())
            ->and($api->forLocale($language)->language())->toBe('en')
            ->and($api->translationPath($language) === null || ! is_file($api->translationPath($language)))->toBeTrue();
    })->with(['en', 'de', 'fr-CA', '../openapi/v1', 'fr/../fr', '', 'FR ', "fr\n"]);

    it('leaves the document the API serves in English, and the shared instance as it was', function (): void {
        $api = app(OpenApi::class);
        $api->forLocale('fr');

        expect($api->document()['paths']['/workspaces']['post']['summary'])->toBe('Create a workspace')
            ->and($api->language())->toBe('en')
            ->and($api->forLocale('fr')->language())->toBe('fr')
            ->and($api->forLocale('fr')->operations()[0]['summary'])->not->toBe($api->operations()[0]['summary'])
            ->and($api->forLocale('fr')->tags()[0]['description'])->not->toBe($api->tags()[0]['description']);

        $json = (string) $this->getJson('/api/v1/openapi.json', ['Accept-Language' => 'fr'])->assertOk()->getContent();

        expect($json)->toContain('Create a workspace')->not->toContain('Créer un espace de travail');
    });

    it('is named next to the specification, with the language before the extension', function (): void {
        $api = app(OpenApi::class);

        expect($api->translationPath('fr'))->toBe(resource_path('openapi/v1.fr.yaml'))
            ->and((new OpenApi('/some/folder/spec.yaml'))->translationPath('pt-BR'))->toBe('/some/folder/spec.pt-BR.yaml')
            ->and((new OpenApi('/some/folder/spec'))->translationPath('fr'))->toBe('/some/folder/spec.fr')
            ->and($api->translationPath('../fr'))->toBeNull()
            ->and($api->translationPath("fr\n"))->toBeNull();
    });

    it('is kept in the cache under a key of its own, with the hash of both files, and replaced when either changes', function (): void {
        $english = (string) file_get_contents(resource_path('openapi/v1.yaml'));
        $french = (string) file_get_contents(resource_path('openapi/v1.fr.yaml'));
        $folder = translationScratch($french);

        $api = new OpenApi($folder.'/v1.yaml');
        $localized = $api->localized('fr');

        expect(Cache::get('openapi:v1:fr')['hash'])->toBe(hash('xxh128', OpenApiTranslation::VERSION.'|'.hash('xxh128', $english).'|'.$french))
            ->and($api->localizedHash('fr'))->toBe(Cache::get('openapi:v1:fr')['hash'])
            ->and(Cache::get('openapi:v1')['hash'])->toBe(hash('xxh128', $english))
            ->and($api->localizedHash('fr'))->not->toBe($api->hash());

        // What the cache holds for this content is taken as it is, not built again.
        Cache::forever('openapi:v1:fr', ['hash' => $api->localizedHash('fr'), 'document' => ['from' => 'the cache']]);

        expect((new OpenApi($folder.'/v1.yaml'))->localized('fr'))->toBe(['from' => 'the cache']);

        // An entry made under another version of the rule (a change of OpenApiTranslation that bumped it) is not this one's, whatever the files.
        Cache::forever('openapi:v1:fr', ['hash' => hash('xxh128', (OpenApiTranslation::VERSION + 1).'|'.hash('xxh128', $english).'|'.$french), 'document' => ['from' => 'another version']]);

        expect((new OpenApi($folder.'/v1.yaml'))->localized('fr')['paths']['/workspaces']['post']['summary'])->toBe('Créer un espace de travail')
            ->and(Cache::get('openapi:v1:fr')['hash'])->toBe($api->localizedHash('fr'));

        // Another content takes the place of the entry.
        file_put_contents($folder.'/v1.fr.yaml', str_replace('Créer un espace de travail', 'Ouvrir un espace de travail', $french));
        $edited = new OpenApi($folder.'/v1.yaml');
        $summary = $edited->localized('fr')['paths']['/workspaces']['post']['summary'];

        $keys = array_values(array_filter(array_keys(Cache::getStore()->all()), fn (string $key): bool => str_starts_with($key, 'openapi')));
        sort($keys);

        expect($summary)->toBe('Ouvrir un espace de travail')
            ->and($edited->localizedHash('fr'))->not->toBe($api->localizedHash('fr'))
            ->and(Cache::get('openapi:v1:fr')['hash'])->toBe($edited->localizedHash('fr'))
            ->and($keys)->toBe(['openapi:v1', 'openapi:v1:fr'])
            ->and($localized['paths']['/workspaces']['post']['summary'])->toBe('Créer un espace de travail');
    });

    it('is built for this process when the cache cannot be reached', function (): void {
        Cache::shouldReceive('get')->andThrow(new RuntimeException('no cache'));
        Cache::shouldReceive('forever')->andThrow(new RuntimeException('no cache'));
        Cache::makePartial();

        expect((new OpenApi)->localized('fr')['paths']['/workspaces']['post']['summary'])->toBe('Créer un espace de travail');
    });

    it('is the English document, with a warning in the log, when the translation is malformed', function (string $content): void {
        Log::spy();
        $api = new OpenApi(translationScratch($content).'/v1.yaml');

        expect($api->localized('fr'))->toBe($api->raw())
            ->and($api->localizedHash('fr'))->toBe($api->hash())
            ->and($api->forLocale('fr')->language())->toBe('en');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'translation of the OpenAPI document into fr cannot be used'))->once();
    })->with(['not a mapping' => 'plain text', 'not YAML' => "summary: [unclosed\n  other: {"]);

    it('is the English document, with a warning in the log, when the translation cannot be read', function (): void {
        Log::spy();
        $folder = translationScratch('info: {title: Titre}');
        chmod($folder.'/v1.fr.yaml', 0);

        try {
            $api = new OpenApi($folder.'/v1.yaml');

            expect($api->localized('fr'))->toBe($api->raw())->and($api->forLocale('fr')->language())->toBe('en');
        } finally {
            chmod($folder.'/v1.fr.yaml', 0644);
        }

        Log::shouldHaveReceived('warning')->once();
    })->skip(function_exists('posix_geteuid') && posix_geteuid() === 0, 'The superuser reads any file');
});

describe('the page /docs/api', function (): void {
    it('shows the texts of the document in French, and says that they are', function (): void {
        $page = templateDom(translationPage($this, 'fr')->assertOk());

        expect(templateTexts($page, '//*[@id="createWorkspace-title"]'))->toBe(['Créer un espace de travail'])
            ->and(templateAttributes($page, '//*[@id="createWorkspace-title"]', 'lang'))->toBe(['fr'])
            ->and(templateTexts($page, '//nav//a[@href="#createWorkspace"]'))->toBe(['Créer un espace de travail'])
            ->and(templateTexts($page, '//h2[@id="intro-authentication"]'))->toBe(['Authentification'])
            ->and(templateAttributes($page, '//*[@id="intro-authentication"]/ancestor::*[@lang][1]', 'lang'))->toBe(['fr']);
    });

    it('shows the texts of the document in English in English', function (): void {
        $page = templateDom(translationPage($this, 'en')->assertOk());

        expect(templateTexts($page, '//*[@id="createWorkspace-title"]'))->toBe(['Create a workspace'])
            ->and(templateAttributes($page, '//*[@id="createWorkspace-title"]', 'lang'))->toBe(['en'])
            ->and(templateTexts($page, '//h2[@id="intro-authentication"]'))->toBe(['Authentication']);
    });

    it('gives the same anchors to the sections of the introduction in every language', function (): void {
        $anchors = fn (string $language): array => array_values(array_filter(
            templateAttributes(templateDom(translationPage($this, $language)->assertOk()), '//*[@id]', 'id'),
            fn (string $id): bool => str_starts_with($id, 'intro-'),
        ));

        expect($anchors('fr'))->toBe($anchors('en'))->and($anchors('en'))->toContain('intro-authentication', 'intro-rate-limits');
    });

    it('says in each element which language its text is in: the one of its translation, English where there is none', function (): void {
        $french = app(RenderedReference::class)->html('fr')->toHtml();
        $english = app(RenderedReference::class)->html('en')->toHtml();
        $other = app(RenderedReference::class)->html('de')->toHtml();

        expect($french)->toContain('lang="fr"')->not->toContain('lang="en"')
            ->and($english)->toContain('lang="en"')->not->toContain('lang="fr"')
            ->and($other)->toContain('lang="en"')->toContain('Create a workspace')->not->toContain('lang="de"')->not->toContain('Créer un espace de travail');
    });

    it('is drawn in English, with a warning in the log, when the translation of its language cannot be used', function (): void {
        Log::spy();
        app()->instance(OpenApi::class, new OpenApi(translationScratch("summary: [unclosed\n  other: {").'/v1.yaml'));

        $page = templateDom(translationPage($this, 'fr')->assertOk());

        expect(templateTexts($page, '//*[@id="createWorkspace-title"]'))->toBe(['Create a workspace'])
            ->and(templateAttributes($page, '//*[@id="createWorkspace-title"]', 'lang'))->toBe(['en'])
            ->and(templateTexts($page, '//h1'))->toBe(["Documentation de l'API"]);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'cannot be used'))->once();
    });

    it('is drawn again, and the entry replaced, when the translation changes', function (): void {
        $french = (string) file_get_contents(resource_path('openapi/v1.fr.yaml'));
        $folder = translationScratch($french);
        app()->instance(OpenApi::class, new OpenApi($folder.'/v1.yaml'));

        $first = app(RenderedReference::class)->html('fr')->toHtml();
        app(RenderedReference::class)->html('en');
        $before = Cache::get('docs:api-reference:fr');
        $englishBefore = Cache::get('docs:api-reference:en');

        expect($first)->toContain('Créer un espace de travail')
            ->and($before['version'])->toBe(app(RenderedReference::class)->version('fr'));

        // What a request does once the file has changed: a new process, which reads the files again.
        file_put_contents($folder.'/v1.fr.yaml', str_replace('Créer un espace de travail', 'Ouvrir un espace de travail', $french));
        app()->instance(OpenApi::class, new OpenApi($folder.'/v1.yaml'));

        $html = app(RenderedReference::class)->html('fr')->toHtml();
        app(RenderedReference::class)->html('en');
        $after = Cache::get('docs:api-reference:fr');

        expect($after['version'])->not->toBe($before['version'])
            ->and($after['html'])->toBe($html)
            ->and($html)->toContain('Ouvrir un espace de travail')->not->toContain('Créer un espace de travail')
            ->and(Cache::get('docs:api-reference:en'))->toBe($englishBefore);
    });
});
