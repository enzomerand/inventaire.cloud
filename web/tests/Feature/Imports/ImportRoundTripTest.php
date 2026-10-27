<?php

declare(strict_types=1);

use App\Inventories\Exports\ExportFormat;
use App\Inventories\Fields\ChoiceType;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\UploadedFilesType;
use App\Models\Import;
use App\Models\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/imports.php';

/*
 * What an export gives back to an import: the values of the items come out of a spreadsheet or a CSV as they went in, whatever the
 * format, the separator and the language the file was written in.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * An inventory with the importable fields of another one, the same names, types and options (the choices are made anew).
 */
function roundTripCopy(Inventory $source, string $name = 'Copy'): Inventory
{
    $copy = inventoryIn($source->workspace()->firstOrFail(), $name);

    foreach ($source->fields()->get() as $field) {
        if (app(FieldTypes::class)->get($field->type) instanceof UploadedFilesType) {
            continue;
        }

        $options = $field->options ?? [];

        if (isset($options['choices'])) {
            $options['choices'] = array_map(fn (array $choice): array => ['label' => $choice['label']], ChoiceType::choicesOf($options));
        }

        fieldIn($copy, $field->type, $field->name, $options, $field->required);
    }

    return $copy;
}

/**
 * The items in an order that does not depend on the order of the file (the exports list the newest first).
 *
 * @param  list<array<string, mixed>>  $items
 * @return list<array<string, mixed>>
 */
function roundTripSorted(array $items): array
{
    usort($items, fn (array $one, array $other): int => strcmp((string) json_encode($one), (string) json_encode($other)));

    return $items;
}

/**
 * Sends the file of an export to an inventory, ignoring the columns of the fields that hold files, starts the import and does it.
 *
 * @param  array<string, mixed>  $case  As {@see exportFixture()} gives it.
 * @param  array<string, string>  $headers
 */
function roundTripImport(object $test, array $case, Inventory $target, string $path, array $headers = [], ?string $name = null): Import
{
    Queue::fake();
    $owner = $case['team']['owner'];
    $sent = importPost($test, $owner, $target, $path, [], $name, $headers)->assertCreated();
    $mapping = array_map(
        fn (array $entry): array => in_array($sent->json("data.columns.{$entry['column']}.header"), ['Photos', 'Docs'], true) ? ['column' => $entry['column'], 'target' => 'ignore'] : $entry,
        $sent->json('data.mapping'),
    );
    $import = Import::query()->where('ulid', $sent->json('data.id'))->firstOrFail();

    inventoryCall($test, $owner, 'POST', importUri($target, $import, '/start'), ['mapping' => $mapping] + [], $headers)->assertStatus(202);

    return importDone($import->refresh());
}

describe('an export, imported again into an inventory with the same fields', function (): void {
    it('gives the same items back', function (ExportFormat $format, array $attributes, array $headers, string $extension): void {
        $case = exportFixture();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], $format, $attributes));
        $target = roundTripCopy($case['inventory']);

        $import = roundTripImport($this, $case, $target, exportLocalPath($export), $headers, "export.{$extension}");

        expect($import->status->value)->toBe('completed')
            ->and($import->errors)->toBe([])
            ->and([$import->rows_total, $import->rows_imported, $import->rows_failed, $import->fields_created])->toBe([3, 3, 0, 0])
            ->and(roundTripSorted(importItemsOf($target)))->toEqual(roundTripSorted(importItemsOf($case['inventory'])))
            // The item with a value everywhere: the 18 fields that are not files.
            ->and(max(array_map('count', importItemsOf($target))))->toBe(18);
    })->with([
        'a spreadsheet' => [ExportFormat::Xlsx, [], [], 'xlsx'],
        'an OpenDocument spreadsheet' => [ExportFormat::Ods, [], [], 'ods'],
        'a CSV with commas' => [ExportFormat::Csv, ['options' => ['delimiter' => 'comma']], [], 'csv'],
        'a CSV with semicolons, in French' => [ExportFormat::Csv, ['options' => ['delimiter' => 'semicolon'], 'locale' => 'fr'], ['Accept-Language' => 'fr'], 'csv'],
        'a CSV with semicolons, in French, read by an English reader' => [ExportFormat::Csv, ['options' => ['delimiter' => 'semicolon'], 'locale' => 'fr'], [], 'csv'],
        'a CSV with tabs' => [ExportFormat::Csv, ['options' => ['delimiter' => 'tab']], [], 'tsv'],
    ]);

    it('gives the same items back from a CSV that a French spreadsheet saved again (Windows-1252)', function (): void {
        $case = exportFixture();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv, ['options' => ['delimiter' => 'semicolon'], 'locale' => 'fr']));
        // A spreadsheet that saves Windows-1252 writes no byte order mark (which has no character in that encoding).
        $windows = importFile(mb_convert_encoding(substr((string) file_get_contents(exportLocalPath($export)), 3), 'Windows-1252', 'UTF-8'));
        $target = roundTripCopy($case['inventory']);

        $import = roundTripImport($this, $case, $target, $windows, ['Accept-Language' => 'fr']);

        expect($import->status->value)->toBe('completed')->and(roundTripSorted(importItemsOf($target)))->toEqual(roundTripSorted(importItemsOf($case['inventory'])));
    });

    it('sends the column of moments back to its field, whatever brackets the name of the field ends with', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $seen = fieldIn($case['inventory'], 'datetime', 'Seen (visit)');
        itemIn($case['inventory'], [$name->ulid => 'Drill', $seen->ulid => '2026-07-01T10:30:00Z'], $case['team']['owner']);
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv, ['options' => ['delimiter' => 'comma']]));
        $target = roundTripCopy($case['inventory']);

        $import = roundTripImport($this, $case, $target, exportLocalPath($export), [], 'export.csv');

        // The header of the column is "Seen (visit) (Europe/Paris)": no new field "Seen (visit) 2".
        expect($import->status->value)->toBe('completed')
            ->and($import->fields_created)->toBe(0)
            ->and($target->fields()->count())->toBe(2)
            ->and(importItemsOf($target))->toEqual(importItemsOf($case['inventory']));
    });
});

describe('an export, imported into an inventory with no field', function (): void {
    it('creates the fields the values look like, and the items', function (): void {
        $case = exportFixture();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv, ['options' => ['delimiter' => 'comma']]));
        $target = inventoryIn($case['team']['workspace'], 'Empty');
        Queue::fake();

        $sent = importPost($this, $case['team']['owner'], $target, exportLocalPath($export), [], 'export.csv')->assertCreated();
        $mapping = array_map(
            fn (array $entry): array => in_array($sent->json("data.columns.{$entry['column']}.header"), ['Photos', 'Docs'], true) ? ['column' => $entry['column'], 'target' => 'ignore'] : $entry,
            $sent->json('data.mapping'),
        );
        $import = Import::query()->where('ulid', $sent->json('data.id'))->firstOrFail();
        inventoryCall($this, $case['team']['owner'], 'POST', importUri($target, $import, '/start'), ['mapping' => $mapping])->assertStatus(202);
        $import = importDone($import->refresh());
        $fields = $target->fields()->get();

        expect($import->status->value)->toBe('completed')
            ->and($import->fields_created)->toBe(18)
            ->and($fields->pluck('type', 'name')->all())->toBe([
                'Name' => 'text',
                'Notes' => 'textarea',
                'Quantity (kg)' => 'number',
                'Price (EUR)' => 'number',
                'Discount (%)' => 'number',
                'In stock' => 'boolean',
                'Bought' => 'date',
                'Seen' => 'datetime',
                'Opening' => 'time',
                'Condition' => 'text',
                'Labels' => 'text',
                'Keywords' => 'text',
                'Website' => 'url',
                'Contact' => 'email',
                'Phone' => 'text',
                'Grade' => 'number',
                'Color' => 'text',
                'Barcode' => 'text',
            ])
            ->and($target->refresh()->items_count)->toBe(3);
    });
});
