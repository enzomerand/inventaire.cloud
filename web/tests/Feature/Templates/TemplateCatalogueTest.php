<?php

declare(strict_types=1);

use App\Inventories\Templates\Template;
use App\Inventories\Templates\TemplateCatalogue;
use App\Inventories\Templates\TemplateCategories;
use App\Inventories\Templates\TemplateValidator;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/templates.php';

/*
 * The catalogue reads the template files, leaves out the ones that cannot be used (with a warning in the log), and gives
 * the templates in the language asked for. The fixtures of tests/Fixtures/templates are its folder here.
 */

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/templates-*')) ?: [] as $folder) {
        templateRemoveFolder($folder);
    }
});

describe('the catalogue', function (): void {
    beforeEach(fn () => templateFixtures());

    it('lists the templates by slug', function (): void {
        $slugs = array_map(fn (Template $template): string => $template->slug, app(TemplateCatalogue::class)->all());

        expect($slugs)->toBe(['sample-home', 'sample-records', 'sample-shop', 'sample-tools']);
    });

    it('finds a template by its slug, and only by that', function (): void {
        $catalogue = app(TemplateCatalogue::class);

        expect($catalogue->find('sample-shop'))->toBeInstanceOf(Template::class)
            ->and($catalogue->find('sample-shop')?->category)->toBe('business')
            ->and($catalogue->find('nothing'))->toBeNull()
            ->and($catalogue->find('../sample-shop'))->toBeNull()
            ->and($catalogue->find(''))->toBeNull();
    });

    it('gives the templates by category, in the order of the categories, each sorted by name in the language', function (): void {
        $names = fn (string $locale): array => array_map(
            fn (array $templates): array => array_map(fn (Template $template): string => $template->name($locale), $templates),
            app(TemplateCatalogue::class)->grouped($locale),
        );

        expect(array_keys(app(TemplateCatalogue::class)->grouped('fr')))->toBe(['home', 'business', 'hobby'])
            ->and($names('fr'))->toBe([
                'home' => ['Inventaire du logement'],
                'business' => ['Stock de boutique'],
                'hobby' => ['Outils', 'Vinyles'],
            ])
            ->and($names('en'))->toBe([
                'home' => ['Home inventory'],
                'business' => ['Shop stock'],
                'hobby' => ['Records', 'Tools'],
            ]);
    });

    it('only uses categories that exist', function (): void {
        foreach (app(TemplateCatalogue::class)->all() as $template) {
            expect(TemplateCategories::exists($template->category))->toBeTrue();
        }
    });

    it('is read once, and again after a flush or when the folder changes', function (): void {
        $folder = templateFolder();
        copy(base_path('tests/Fixtures/templates/sample-shop.json'), $folder.'/sample-shop.json');
        templateCatalogueFrom($folder);
        $catalogue = app(TemplateCatalogue::class);

        expect($catalogue->all())->toHaveCount(1);

        copy(base_path('tests/Fixtures/templates/sample-tools.json'), $folder.'/sample-tools.json');

        expect($catalogue->all())->toHaveCount(1);

        $catalogue->flush();

        expect($catalogue->all())->toHaveCount(2);

        templateFixtures();

        expect($catalogue->all())->toHaveCount(4);
    });
});

describe('a template', function (): void {
    beforeEach(fn () => templateFixtures());

    it('gives its texts in the language asked for', function (): void {
        $template = app(TemplateCatalogue::class)->find('sample-shop');

        expect($template->name('fr'))->toBe('Stock de boutique')
            ->and($template->name('en'))->toBe('Shop stock')
            ->and($template->summary('en'))->toStartWith('Track your products')
            ->and($template->description('fr'))->toHaveCount(2)
            ->and($template->examples('en'))->toHaveCount(3)
            ->and($template->locales())->toBe(['fr', 'en'])
            ->and($template->fieldsCount())->toBe(8);
    });

    it('falls back on the language of the instance, then on English, for a language it is not written in', function (): void {
        $template = app(TemplateCatalogue::class)->find('sample-shop');

        expect($template->name('de'))->toBe('Stock de boutique')
            ->and($template->localeFor('de'))->toBe('fr');

        config(['app.default_locale' => 'en']);

        expect($template->name('de'))->toBe('Shop stock');

        config(['app.default_locale' => 'es']);

        expect($template->name('de'))->toBe('Shop stock')
            ->and($template->description('de'))->toBe($template->description('en'));
    });

    it('builds its fields in a language, with the choices as the creation of a field takes them', function (): void {
        $fields = app(TemplateCatalogue::class)->find('sample-shop')->fields('en');

        expect(array_column($fields, 'key'))->toBe(['barcode', 'name', 'quantity', 'price', 'vat', 'status', 'supplier_site', 'expires_on'])
            ->and($fields[0]->name)->toBe('Barcode')
            ->and($fields[0]->options)->toBe(['format' => 'ean13'])
            ->and($fields[1]->required)->toBeTrue()
            ->and($fields[0]->required)->toBeFalse()
            ->and($fields[5]->type)->toBe('select')
            ->and($fields[5]->creationOptions())->toBe(['choices' => [
                ['label' => 'In stock', 'color' => 'green'],
                ['label' => 'Low stock', 'color' => 'orange'],
                ['label' => 'Out of stock', 'color' => 'red'],
            ]])
            ->and($fields[5]->publicOptions()['choices'][1])->toBe(['key' => 'low', 'label' => 'Low stock', 'color' => 'orange'])
            ->and(app(TemplateCatalogue::class)->find('sample-home')->fields('fr')[1]->creationOptions()['choices'][3])->toBe(['label' => 'Garage']);
    });
});

describe('a file that cannot be used', function (): void {
    it('is left out and logged, without affecting the others', function (): void {
        $folder = templateFolder();
        copy(base_path('tests/Fixtures/templates/sample-shop.json'), $folder.'/sample-shop.json');
        file_put_contents($folder.'/broken.json', '{"category": ');
        file_put_contents($folder.'/not-an-object.json', '"text"');
        file_put_contents($folder.'/Capitals.json', (string) file_get_contents(base_path('tests/Fixtures/templates/sample-tools.json')));
        file_put_contents($folder.'/wrong-category.json', json_encode(templateWith(templateData(), 'category', 'garden')));
        file_put_contents($folder.'/huge.json', str_repeat(' ', TemplateCatalogue::MAX_FILE_SIZE + 1));
        file_put_contents($folder.'/notes.txt', 'Not a template.');
        mkdir($folder.'/folder.json');

        Log::spy();
        templateCatalogueFrom($folder);
        $catalogue = app(TemplateCatalogue::class);

        expect(array_map(fn (Template $template): string => $template->slug, $catalogue->all()))->toBe(['sample-shop']);

        // Once for each file that was left out, and not again when the catalogue is asked again.
        $catalogue->all();
        $catalogue->find('broken');

        Log::shouldHaveReceived('warning')->times(5);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_ends_with($context['file'] ?? '', 'wrong-category.json') && str_contains(implode(' ', $context['errors'] ?? []), 'category'))->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_ends_with($context['file'] ?? '', 'broken.json') && str_contains($message, 'not valid JSON'))->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_ends_with($context['file'] ?? '', 'huge.json'))->once();

        rmdir($folder.'/folder.json');
    });

    it('is left out, with a message that says so, when it is written in a newer format of the files', function (): void {
        $folder = templateFolder();
        copy(base_path('tests/Fixtures/templates/sample-shop.json'), $folder.'/sample-shop.json');
        file_put_contents($folder.'/from-the-future.json', json_encode(templateWith(templateWith(templateData(), 'format', TemplateValidator::FORMAT + 1), 'something-new', ['a' => 1])));
        file_put_contents($folder.'/current-format.json', json_encode(templateWith(templateData(), 'format', TemplateValidator::FORMAT)));

        Log::spy();
        templateCatalogueFrom($folder);
        $catalogue = app(TemplateCatalogue::class);

        expect(array_map(fn (Template $template): string => $template->slug, $catalogue->all()))->toBe(['current-format', 'sample-shop']);

        Log::shouldHaveReceived('warning')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_ends_with($context['file'] ?? '', 'from-the-future.json') && ($context['errors'] ?? null) === ['format: this file needs a newer inventaire.cloud (format 2; this version reads up to format 1).'])->once();
    });

    it('is not looked at when its name starts with an underscore or a dot, nor is a file that is not JSON', function (): void {
        $folder = templateFolder();
        copy(base_path('tests/Fixtures/templates/sample-shop.json'), $folder.'/sample-shop.json');
        // Drafts and notes, valid or not: neither listed, nor judged, nor logged.
        copy(base_path('tests/Fixtures/templates/sample-tools.json'), $folder.'/_sample-tools.json');
        file_put_contents($folder.'/_draft.json', '{"half": ');
        file_put_contents($folder.'/.hidden.json', '"text"');
        file_put_contents($folder.'/.json', '[]');
        file_put_contents($folder.'/_.json', '[]');
        file_put_contents($folder.'/README.md', 'Notes.');

        Log::spy();
        templateCatalogueFrom($folder);
        $catalogue = app(TemplateCatalogue::class);

        expect(array_map(fn (Template $template): string => $template->slug, $catalogue->all()))->toBe(['sample-shop'])
            ->and($catalogue->find('_sample-tools'))->toBeNull()
            ->and(TemplateCatalogue::slugOf('sample-shop.json'))->toBe('sample-shop')
            ->and(TemplateCatalogue::slugOf('_draft.json'))->toBeNull()
            ->and(TemplateCatalogue::slugOf('.hidden.json'))->toBeNull()
            ->and(TemplateCatalogue::slugOf('notes.txt'))->toBeNull();

        Log::shouldNotHaveReceived('warning');
    });

    it('keeps a $schema and x- keys of the file without a complaint', function (): void {
        $folder = templateFolder();
        file_put_contents($folder.'/annotated.json', json_encode(['$schema' => 'https://example.test/schema.json', 'x-notes' => 'for the reviewers'] + templateData()));

        Log::spy();
        templateCatalogueFrom($folder);

        expect(app(TemplateCatalogue::class)->find('annotated')?->name('en'))->toBe('Shop stock');
        Log::shouldNotHaveReceived('warning');
    });

    it('gives an empty catalogue, and one warning, for a folder that does not exist', function (): void {
        Log::spy();
        templateCatalogueFrom(storage_path('framework/testing/no-such-folder'));
        $catalogue = app(TemplateCatalogue::class);

        expect($catalogue->all())->toBe([])
            ->and($catalogue->find('anything'))->toBeNull()
            ->and($catalogue->grouped('fr'))->toBe([]);

        Log::shouldHaveReceived('warning')->once();
    });
});
