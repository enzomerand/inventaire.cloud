<?php

declare(strict_types=1);

use App\Inventories\Templates\Template;
use App\Inventories\Templates\TemplateCatalogue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/templates.php';

/*
 * The catalogue keeps its verdict on the template files (the decoded templates that were accepted) in the application cache
 * for a day, under a key made of the content of the files, the version of the rules and the languages. The templates are
 * rebuilt from the arrays that were kept, and the files that were left out are logged when the verdict is made, not when it
 * is read.
 */

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/templates-*')) ?: [] as $folder) {
        templateRemoveFolder($folder);
    }
});

/**
 * The entries of the cache that hold a verdict on the template files, by key.
 *
 * @return array<string, array{value: mixed, expiresAt: float}>
 */
function templateCacheEntries(): array
{
    $store = Cache::getStore();

    return array_filter($store->all(), fn (string $key): bool => str_starts_with($key, 'inventory-templates:'), ARRAY_FILTER_USE_KEY);
}

/**
 * A folder with a template and a file that cannot be used, ready to be read by the catalogue.
 */
function templateFolderWithOneBrokenFile(): string
{
    $folder = templateFolder();
    copy(base_path('tests/Fixtures/templates/sample-shop.json'), $folder.'/sample-shop.json');
    file_put_contents($folder.'/broken.json', '{"category": ');
    templateCatalogueFrom($folder);

    return $folder;
}

/**
 * A new process: the catalogue is asked again, with what it had read forgotten but not the cache.
 */
function templateNewProcess(): TemplateCatalogue
{
    $catalogue = app(TemplateCatalogue::class);
    $catalogue->flush();

    return $catalogue;
}

describe('the verdict', function (): void {
    it('is kept as plain arrays for a day: the templates that were accepted, while the file that was left out is logged', function (): void {
        templateFolderWithOneBrokenFile();
        Log::spy();
        app(TemplateCatalogue::class)->all();

        $entries = templateCacheEntries();
        $entry = array_values($entries)[0];

        expect($entries)->toHaveCount(1)
            ->and(array_keys($entries)[0])->toMatch('/^inventory-templates:v2:[0-9a-f]{32}$/D')
            ->and($entry['expiresAt'] - microtime(true))->toBeGreaterThan(TemplateCatalogue::CACHE_TTL - 60)->toBeLessThan(TemplateCatalogue::CACHE_TTL + 1)
            ->and(TemplateCatalogue::CACHE_TTL)->toBe(24 * 3600)
            ->and(array_keys($entry['value']))->toBe(['sample-shop'])
            ->and($entry['value']['sample-shop'])->toBeArray()->and($entry['value']['sample-shop']['category'])->toBe('business');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'not valid JSON') && str_ends_with($context['file'] ?? '', 'broken.json'))->once();
    });

    it('is read from the cache by another process, which rebuilds the templates from the arrays', function (): void {
        templateFolderWithOneBrokenFile();
        $first = app(TemplateCatalogue::class)->find('sample-shop');
        $second = templateNewProcess()->find('sample-shop');

        expect($second)->toBeInstanceOf(Template::class)
            ->and($second)->not->toBe($first)
            ->and($second)->toEqual($first)
            ->and($second->name('fr'))->toBe('Stock de boutique')
            ->and($second->fields('en'))->toHaveCount(8)
            ->and(templateCacheEntries())->toHaveCount(1);
    });

    it('is not judged again by the process that has it, nor by another one', function (): void {
        $folder = templateFolderWithOneBrokenFile();
        Log::spy();
        $catalogue = app(TemplateCatalogue::class);

        $catalogue->all();
        $catalogue->find('broken');
        templateNewProcess()->all();
        templateNewProcess()->grouped('fr');

        // Written when the verdict was made, and not when it was read.
        Log::shouldHaveReceived('warning')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_ends_with($context['file'] ?? '', 'broken.json'))->once();
        expect(file_exists($folder.'/broken.json'))->toBeTrue();
    });

    it('is not kept for a folder that cannot be read', function (): void {
        templateCatalogueFrom(storage_path('framework/testing/no-such-folder'));

        expect(app(TemplateCatalogue::class)->all())->toBe([])
            ->and(templateCacheEntries())->toBe([]);
    });
});

describe('the key of the cache entry', function (): void {
    it('changes with the content of a file, so that an edited template shows at once', function (): void {
        $folder = templateFolderWithOneBrokenFile();
        expect(app(TemplateCatalogue::class)->find('sample-shop')?->name('en'))->toBe('Shop stock');

        $data = templateWith(templateData(), 'name.en', 'Shop');
        file_put_contents($folder.'/sample-shop.json', json_encode($data));

        expect(templateNewProcess()->find('sample-shop')?->name('en'))->toBe('Shop')
            ->and(templateCacheEntries())->toHaveCount(2);
    });

    it('changes when a file is added or removed', function (): void {
        $folder = templateFolderWithOneBrokenFile();
        expect(app(TemplateCatalogue::class)->all())->toHaveCount(1);

        copy(base_path('tests/Fixtures/templates/sample-tools.json'), $folder.'/sample-tools.json');

        expect(templateNewProcess()->all())->toHaveCount(2);

        unlink($folder.'/sample-tools.json');

        // Back to the first folder: its entry is still there.
        expect(templateNewProcess()->all())->toHaveCount(1)
            ->and(templateCacheEntries())->toHaveCount(2);
    });

    it('does not change with a file that is not a template, nor with the name of a file moved to another', function (): void {
        $folder = templateFolderWithOneBrokenFile();
        app(TemplateCatalogue::class)->all();

        file_put_contents($folder.'/_draft.json', '{"half": ');
        file_put_contents($folder.'/.hidden.json', '{}');
        file_put_contents($folder.'/notes.txt', 'Not a template.');
        templateNewProcess()->all();

        expect(templateCacheEntries())->toHaveCount(1);

        // The same content under another name is not the same verdict: the name is the slug.
        rename($folder.'/sample-shop.json', $folder.'/sample-store.json');
        templateNewProcess()->all();

        expect(templateCacheEntries())->toHaveCount(2);
    });

    it('changes with the languages of the application, which the validator judges the files by', function (): void {
        templateFolderWithOneBrokenFile();
        app(TemplateCatalogue::class)->all();

        config(['app.locale_names' => [...config('app.locale_names'), 'de' => 'Deutsch']]);
        templateNewProcess()->all();

        expect(templateCacheEntries())->toHaveCount(2);
    });

    it('does not change with the settings that do not change what the validator says', function (): void {
        templateFolderWithOneBrokenFile();
        Log::spy();
        app(TemplateCatalogue::class)->all();

        config(['inventories.max_fields' => 7, 'app.default_locale' => 'en']);
        templateNewProcess()->all();

        expect(templateCacheEntries())->toHaveCount(1);
        Log::shouldHaveReceived('warning')->once();
    });
});

describe('forgetting the verdict', function (): void {
    it('makes the next question judge the files again, and only the entry of the files as they are', function (): void {
        $folder = templateFolderWithOneBrokenFile();
        Log::spy();
        $catalogue = app(TemplateCatalogue::class);
        $catalogue->all();

        // Another folder, with its own entry.
        $other = templateFolder();
        copy(base_path('tests/Fixtures/templates/sample-tools.json'), $other.'/sample-tools.json');
        templateCatalogueFrom($other);
        $catalogue->all();
        templateCatalogueFrom($folder);

        expect(templateCacheEntries())->toHaveCount(2);

        $catalogue->forget();

        expect(templateCacheEntries())->toHaveCount(1);

        $catalogue->all();

        // The broken file is judged, and logged, again.
        Log::shouldHaveReceived('warning')->twice();
        expect(templateCacheEntries())->toHaveCount(2);
    });

    it('does nothing for a folder that cannot be read', function (): void {
        templateCatalogueFrom(storage_path('framework/testing/no-such-folder'));

        app(TemplateCatalogue::class)->forget();

        expect(templateCacheEntries())->toBe([]);
    });
});
