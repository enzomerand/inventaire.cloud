<?php

declare(strict_types=1);

use App\Inventories\Fields\ChoiceType;
use App\Inventories\Imports\ImportError;
use App\Inventories\Imports\ImportRows;
use App\Inventories\Imports\ImportRunner;
use App\Inventories\Imports\ImportStatus;
use App\Inventories\InventoryFields;
use App\Jobs\ImportItems;
use App\Models\Import;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\ItemRevision;
use App\Workspaces\WorkspaceRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/imports.php';

/*
 * What the job does: reads the rows, converts and checks them, creates the fields the person asked for and the items in one
 * transaction, and records the report. The file is always deleted at the end.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * A mapping entry that sends a column to a field.
 *
 * @return array{column: int, target: string, field: string}
 */
function runToField(int $column, string $ulid): array
{
    return ['column' => $column, 'target' => 'field', 'field' => $ulid];
}

/**
 * A mapping entry that creates a field.
 *
 * @return array{column: int, target: string, type: string, name: string}
 */
function runToNew(int $column, string $type, string $name): array
{
    return ['column' => $column, 'target' => 'new', 'type' => $type, 'name' => $name];
}

/**
 * The fields of an inventory by name, in their order.
 *
 * @return array<string, InventoryField>
 */
function runFields(Inventory $inventory): array
{
    return $inventory->fields()->get()->keyBy('name')->all();
}

describe('adding items to the fields of an inventory', function (): void {
    it('creates the items from a French spreadsheet CSV, one transaction, the file deleted at the end', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $quantity = fieldIn($case['inventory'], 'number', 'Quantity', ['decimals' => 2]);
        $price = fieldIn($case['inventory'], 'money', 'Price', ['currency' => 'EUR']);
        $bought = fieldIn($case['inventory'], 'date', 'Bought');
        $stock = fieldIn($case['inventory'], 'boolean', 'In stock');
        $csv = importFile(mb_convert_encoding(importCsv([
            ['Nom', 'Quantité (kg)', 'Prix', 'Acheté le', 'En stock', 'Identifiant'],
            ['Perceuse', '2,5', '1 234,56 €', '15/03/2024', 'oui', 'x'],
            ['Scie', '1', '9 €', '16/03/2024', 'non', 'y'],
        ], ';'), 'Windows-1252', 'UTF-8'));
        $mapping = [runToField(0, $name->ulid), runToField(1, $quantity->ulid), runToField(2, $price->ulid), runToField(3, $bought->ulid), runToField(4, $stock->ulid)];

        $import = importDone(importAsked($this, $case, $csv, $mapping));

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and([$import->rows_total, $import->rows_imported, $import->rows_failed, $import->fields_created])->toBe([2, 2, 0, 0])
            ->and($import->errors)->toBe([])
            ->and($import->error)->toBeNull()
            ->and($import->path)->toBeNull()
            ->and($import->completed_at)->not->toBeNull()
            ->and($import->expires_at?->isFuture())->toBeTrue()
            ->and($import->expires_at?->lessThanOrEqualTo(now()->addHours(24)->addMinute()))->toBeTrue()
            ->and(importItemsOf($case['inventory']))->toBe([
                ['Bought' => '2024-03-15', 'In stock' => true, 'Name' => 'Perceuse', 'Price' => '1234.56', 'Quantity' => '2.50'],
                ['Bought' => '2024-03-16', 'In stock' => false, 'Name' => 'Scie', 'Price' => '9.00', 'Quantity' => '1.00'],
            ])
            ->and($case['inventory']->refresh()->items_count)->toBe(2)
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->toBe([]);
    });

    it('adds the items the way adding them one by one does: author, search text, history, counter, order', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $tags = fieldIn($case['inventory'], 'tags', 'Tags');
        $csv = importFile(importCsv([['Name', 'Tags'], ['Perceuse Éclair', 'power, cordless'], ['Scie', ''], ['Marteau', 'heavy']]));

        $import = importDone(importAsked($this, $case, $csv, [runToField(0, $name->ulid), runToField(1, $tags->ulid)], as: $case['team']['editor']));
        $items = Item::query()->where('inventory_id', $case['inventory']->id)->orderBy('id')->get();

        expect($import->rows_imported)->toBe(3)
            ->and($items->pluck('values')->all())->toEqual([
                [$name->ulid => 'Perceuse Éclair', $tags->ulid => ['power', 'cordless']],
                [$name->ulid => 'Scie'],
                [$name->ulid => 'Marteau', $tags->ulid => ['heavy']],
            ])
            ->and($items->pluck('created_by')->unique()->all())->toBe([$case['team']['editor']->id])
            ->and($items->pluck('updated_by')->unique()->all())->toBe([$case['team']['editor']->id])
            ->and($items->pluck('search_text')->all())->toBe(["perceuse eclair\npower cordless", 'scie', "marteau\nheavy"])
            ->and($items->pluck('created_at')->unique()->count())->toBe(1);

        $revisions = ItemRevision::query()->where('inventory_id', $case['inventory']->id)->orderBy('item_id')->get();

        expect($revisions)->toHaveCount(3)
            ->and($revisions->pluck('action')->unique()->all())->toBe(['created'])
            ->and($revisions->pluck('user_id')->unique()->all())->toBe([$case['team']['editor']->id])
            ->and($revisions[0]->changes)->toEqual([$name->ulid => ['before' => null, 'after' => 'Perceuse Éclair'], $tags->ulid => ['before' => null, 'after' => ['power', 'cordless']]])
            ->and($revisions->pluck('item_id')->all())->toBe($items->pluck('id')->all());

        // The items are found by the search, like any other.
        $found = inventoryCall($this, $case['team']['owner'], 'GET', "/api/v1/inventories/{$case['inventory']->ulid}/items?q=eclair")->assertOk()->json('data');

        expect($found)->toHaveCount(1);
    });

    it('writes a long file in several statements, and keeps the order of the rows', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $rows = array_map(fn (int $number): array => ["Item {$number}"], range(1, 1203));
        $csv = importFile(importCsv([['Name'], ...$rows]));

        $import = importDone(importAsked($this, $case, $csv, [runToField(0, $name->ulid)]));
        $names = Item::query()->where('inventory_id', $case['inventory']->id)->orderBy('id')->pluck('values')->map(fn (array $values): string => $values[$name->ulid])->all();

        expect($import->rows_imported)->toBe(1203)
            ->and($names)->toBe(array_map(fn (int $number): string => "Item {$number}", range(1, 1203)))
            ->and($case['inventory']->refresh()->items_count)->toBe(1203)
            ->and(ItemRevision::query()->where('inventory_id', $case['inventory']->id)->count())->toBe(1203);
    });

    it('adds to the items the inventory has, and leaves the fields that are not mapped empty', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        fieldIn($case['inventory'], 'number', 'Quantity');
        itemIn($case['inventory'], [$name->ulid => 'Old']);
        $csv = importFile(importCsv([['Name', 'Ignored'], ['New', 'x']]));

        importDone(importAsked($this, $case, $csv, [runToField(0, $name->ulid)]));

        expect(importItemsOf($case['inventory']))->toBe([['Name' => 'Old'], ['Name' => 'New']])
            ->and($case['inventory']->refresh()->items_count)->toBe(2);
    });

    it('reads the numbers and the dates the way the options say', function (): void {
        $case = importCase();
        $quantity = fieldIn($case['inventory'], 'number', 'Quantity', ['decimals' => 2]);
        $bought = fieldIn($case['inventory'], 'date', 'Bought');
        $csv = importFile(importCsv([['Quantity', 'Bought'], ['1.234,5', '03/04/2024']], ';'));
        $mapping = [runToField(0, $quantity->ulid), runToField(1, $bought->ulid)];

        importDone(importAsked($this, $case, $csv, $mapping, ['decimal' => 'comma', 'date_order' => 'mdy']));
        importDone(importAsked($this, $case, $csv, $mapping, ['decimal' => 'comma', 'date_order' => 'dmy']));
        importDone(importAsked($this, $case, importFile(importCsv([['Quantity', 'Bought'], ['1,234.5', '03/04/2024']], ';')), $mapping, ['decimal' => 'point', 'date_order' => 'ymd']));

        $items = importItemsOf($case['inventory']);

        expect($items[0])->toBe(['Bought' => '2024-03-04', 'Quantity' => '1234.50'])
            ->and($items[1])->toBe(['Bought' => '2024-04-03', 'Quantity' => '1234.50'])
            // Year first needs two digits: this one is refused, and the file is imported without it.
            ->and($items)->toHaveCount(2);
    });

    it('reads a spreadsheet with typed cells', function (string $format): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $quantity = fieldIn($case['inventory'], 'number', 'Quantity', ['decimals' => 1]);
        $bought = fieldIn($case['inventory'], 'date', 'Bought');
        $seen = fieldIn($case['inventory'], 'datetime', 'Seen');
        $open = fieldIn($case['inventory'], 'time', 'Open');
        $stock = fieldIn($case['inventory'], 'boolean', 'In stock');
        $sheet = importSpreadsheet($format, [
            ['Name', 'Quantity', 'Bought', 'Seen', 'Open', 'In stock'],
            ['Drill', 2.55, new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-07-01 12:30'), new DateInterval('PT7H5M'), true],
            ['Saw', 3, new DateTimeImmutable('2026-03-02'), new DateTimeImmutable('2026-01-15 08:00'), new DateInterval('PT9H'), false],
        ]);
        $mapping = [runToField(0, $name->ulid), runToField(1, $quantity->ulid), runToField(2, $bought->ulid), runToField(3, $seen->ulid), runToField(4, $open->ulid), runToField(5, $stock->ulid)];

        $import = importDone(importAsked($this, $case, $sheet, $mapping));

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and(importItemsOf($case['inventory']))->toBe([
                ['Bought' => '2026-03-01', 'In stock' => true, 'Name' => 'Drill', 'Open' => '07:05', 'Quantity' => '2.6', 'Seen' => '2026-07-01T10:30:00Z'],
                ['Bought' => '2026-03-02', 'In stock' => false, 'Name' => 'Saw', 'Open' => '09:00', 'Quantity' => '3.0', 'Seen' => '2026-01-15T07:00:00Z'],
            ]);
    })->with(['xlsx', 'ods']);

    it('does not create an item from a row with nothing in it, and counts the rows as the file does', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $number = fieldIn($case['inventory'], 'number', 'Quantity');
        $csv = importFile("Name,Quantity\r\nDrill,1\r\n\r\n,\r\n  ,  \r\nSaw,x\r\nMallet,3\r\n");

        $import = importDone(importAsked($this, $case, $csv, [runToField(0, $name->ulid), runToField(1, $number->ulid)], ['on_error' => 'skip']));

        expect($import->rows_total)->toBe(3)
            ->and($import->rows_imported)->toBe(2)
            ->and($import->rows_failed)->toBe(1)
            // The row of the spreadsheet, not the number of the item: the empty rows are counted.
            ->and($import->errors)->toBe([['row' => 6, 'column' => 'Quantity', 'message' => '"x" is not a number.']]);
    });

    it('imports a file with a header only', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');

        $import = importDone(importAsked($this, $case, importFile("Name\r\n"), [runToField(0, $name->ulid)]));

        expect($import->status)->toBe(ImportStatus::Completed)->and($import->rows_total)->toBe(0)->and($import->rows_imported)->toBe(0);
    });

    it('takes the header to be the first row of the file, and an empty first row to be no header: the data begin on the second row', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $number = fieldIn($case['inventory'], 'number', 'Quantity');
        // The first row with something in it is not the header of a file whose first row is empty.
        $csv = importFile("\r\nDrill,1\r\nSaw,x\r\n");

        $import = importDone(importAsked($this, $case, $csv, [runToField(0, $name->ulid), runToField(1, $number->ulid)], ['on_error' => 'skip']));

        expect($import->rows_total)->toBe(2)
            ->and($import->rows_imported)->toBe(1)
            ->and($import->errors)->toBe([['row' => 3, 'column' => 'Column B', 'message' => '"x" is not a number.']])
            ->and(importItemsOf($case['inventory']))->toBe([['Name' => 'Drill', 'Quantity' => '1']]);
    });

    it('reads a file with no header', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');

        $import = importDone(importAsked($this, $case, importFile("Drill\r\nSaw\r\n"), [runToField(0, $name->ulid)], upload: ['has_header' => 'false']));

        expect($import->rows_imported)->toBe(2)->and(importItemsOf($case['inventory']))->toBe([['Name' => 'Drill'], ['Name' => 'Saw']]);
    });

    it('writes the messages of the report in the language of the person', function (): void {
        $case = importCase();
        $number = fieldIn($case['inventory'], 'number', 'Quantité');
        $csv = importFile(importCsv([['Quantité'], ['abc']]));

        $import = importDone(importAsked($this, $case, $csv, [runToField(0, $number->ulid)], headers: ['Accept-Language' => 'fr']));

        expect($import->locale)->toBe('fr')
            ->and($import->errors[0]['message'])->toBe("« abc » n'est pas un nombre.");
    });

    it('leaves the language of the worker as it found it', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $import = importAsked($this, $case, importFile("Name\r\nx\r\n"), [runToField(0, $name->ulid)]);
        $import->forceFill(['locale' => 'fr'])->save();

        importDone($import);

        expect(app()->getLocale())->toBe('en');
    });
});

describe('creating fields from the columns', function (): void {
    it('creates the fields with the options their values call for, then the items', function (): void {
        $case = importCase();
        $csv = importFile(importCsv([
            ['Name', 'Quantity', 'Discount', 'Price', 'Notes', 'Grade', 'In stock', 'Bought', 'Opening', 'Site'],
            ['Drill', '2,5', '12,25 %', '1 234,5 USD', 'a', '4', 'oui', '15/03/2024', '9h30', 'https://example.com'],
            ['Saw', '3,125', '5 %', '9 USD', str_repeat('n', 400), '7', 'non', '16/03/2024', '14:00', ''],
        ], ';'));
        $mapping = [
            runToNew(0, 'text', 'Name'), runToNew(1, 'number', 'Quantity'), runToNew(2, 'percentage', 'Discount'), runToNew(3, 'money', 'Price'),
            runToNew(4, 'text', 'Notes'), runToNew(5, 'rating', 'Grade'), runToNew(6, 'boolean', 'In stock'), runToNew(7, 'date', 'Bought'),
            runToNew(8, 'time', 'Opening'), runToNew(9, 'url', 'Site'),
        ];

        $import = importDone(importAsked($this, $case, $csv, $mapping, ['decimal' => 'comma', 'date_order' => 'dmy']));
        $fields = runFields($case['inventory']);

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and([$import->rows_total, $import->rows_imported, $import->fields_created])->toBe([2, 2, 10])
            ->and(array_keys($fields))->toBe(['Name', 'Quantity', 'Discount', 'Price', 'Notes', 'Grade', 'In stock', 'Bought', 'Opening', 'Site'])
            ->and(array_map(fn ($field) => $field->type, array_values($fields)))->toBe(['text', 'number', 'percentage', 'money', 'text', 'rating', 'boolean', 'date', 'time', 'url'])
            ->and($fields['Quantity']->options)->toBe(['decimals' => 3])
            ->and($fields['Discount']->options)->toBe(['decimals' => 2])
            ->and($fields['Price']->options)->toBe(['currency' => 'USD', 'decimals' => 2])
            ->and($fields['Notes']->options)->toBe(['max_length' => 400])
            ->and($fields['Grade']->options)->toBe(['max' => 7])
            ->and($fields['Name']->options)->toBe(['max_length' => 255])
            ->and($fields['Name']->required)->toBeFalse()
            ->and(importItemsOf($case['inventory']))->toBe([
                ['Bought' => '2024-03-15', 'Discount' => '12.25', 'Grade' => 4, 'In stock' => true, 'Name' => 'Drill', 'Notes' => 'a', 'Opening' => '09:30', 'Price' => '1234.50', 'Quantity' => '2.500', 'Site' => 'https://example.com'],
                ['Bought' => '2024-03-16', 'Discount' => '5.00', 'Grade' => 7, 'In stock' => false, 'Name' => 'Saw', 'Notes' => str_repeat('n', 400), 'Opening' => '14:00', 'Price' => '9.00', 'Quantity' => '3.125', 'Opening' => '14:00'],
            ]);
    });

    it('puts the new fields after the ones the inventory has, in the order of the columns', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $csv = importFile(importCsv([['Zed', 'Name', 'Alpha'], ['z', 'n', 'a']]));

        importDone(importAsked($this, $case, $csv, [runToNew(0, 'text', 'Zed'), runToField(1, $name->ulid), runToNew(2, 'text', 'Alpha')]));

        expect(array_keys(runFields($case['inventory'])))->toBe(['Name', 'Zed', 'Alpha']);
    });

    it('makes the choices of a choice field from the different values, the case and the accents ignored, the first spelling kept', function (): void {
        $case = importCase();
        $csv = importFile(importCsv([['Condition'], ['Usé'], ['neuf'], ['USE'], ['Neuf'], [' usé '], ['Cassé'], ['']]));

        $import = importDone(importAsked($this, $case, $csv, [runToNew(0, 'select', 'Condition')]));
        $field = runFields($case['inventory'])['Condition'];

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and(array_column(ChoiceType::choicesOf($field->options), 'label'))->toBe(['Usé', 'neuf', 'Cassé'])
            ->and(importItemsOf($case['inventory']))->toBe([['Condition' => 'Usé'], ['Condition' => 'neuf'], ['Condition' => 'Usé'], ['Condition' => 'neuf'], ['Condition' => 'Usé'], ['Condition' => 'Cassé']]);
    });

    it('gives each choice an id of the saved field, not the one it had while it was checked', function (): void {
        $case = importCase();
        $csv = importFile(importCsv([['Condition'], ['A'], ['B'], ['A']]));

        importDone(importAsked($this, $case, $csv, [runToNew(0, 'select', 'Condition')]));
        $field = runFields($case['inventory'])['Condition'];
        $ids = array_column(ChoiceType::choicesOf($field->options), 'id');
        $stored = Item::query()->where('inventory_id', $case['inventory']->id)->orderBy('id')->get()->map(fn ($item) => $item->values[$field->ulid])->all();

        expect($stored)->toBe([$ids[0], $ids[1], $ids[0]]);

        // And through the API: the item shows the label of its choice.
        $item = Item::query()->where('inventory_id', $case['inventory']->id)->orderBy('id')->firstOrFail();
        $shown = inventoryCall($this, $case['team']['owner'], 'GET', "/api/v1/inventories/{$case['inventory']->ulid}/items/{$item->ulid}")->assertOk()->json('data.values');

        expect($shown[$field->ulid])->toBe($ids[0]);
    });

    it('fails when a column has more different values than a list of choices may hold, and writes nothing', function (): void {
        $case = importCase();
        $rows = array_map(fn (int $number): array => ["Choice {$number}"], range(1, 201));
        $csv = importFile(importCsv([['Condition'], ...$rows]));

        $import = importDone(importAsked($this, $case, $csv, [runToNew(0, 'select', 'Condition')]));

        expect($import->status)->toBe(ImportStatus::Failed)
            ->and($import->error)->toBe('too_many_choices')
            ->and(runFields($case['inventory']))->toBe([])
            ->and(Item::query()->count())->toBe(0)
            ->and($import->path)->toBeNull()
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->toBe([]);
    });

    it('accepts exactly as many choices as a list may hold', function (): void {
        $case = importCase();
        $rows = array_map(fn (int $number): array => ["Choice {$number}"], range(1, 200));
        $csv = importFile(importCsv([['Condition'], ...$rows]));

        $import = importDone(importAsked($this, $case, $csv, [runToNew(0, 'select', 'Condition')]));

        expect($import->status)->toBe(ImportStatus::Completed)->and(ChoiceType::choicesOf(runFields($case['inventory'])['Condition']->options))->toHaveCount(200);
    });

    it('fails when a column that becomes a list of choices holds no value', function (): void {
        $case = importCase();

        $import = importDone(importAsked($this, $case, importFile(importCsv([['Condition', 'Name'], ['', 'a']])), [runToNew(0, 'select', 'Condition'), runToNew(1, 'text', 'Name')]));

        expect($import->error)->toBe('no_choices')->and(runFields($case['inventory']))->toBe([]);
    });

    it('fails when the new fields would take the inventory past the number of fields it may have', function (): void {
        $case = importCase();
        $csv = importFile(importCsv([['A', 'B', 'C'], ['1', '2', '3']]));
        $import = importAsked($this, $case, $csv, [runToNew(0, 'text', 'A'), runToNew(1, 'text', 'B'), runToNew(2, 'text', 'C')]);
        // The limit came down since the person started the import.
        config(['inventories.max_fields' => 2]);

        $import = importDone($import);

        expect($import->error)->toBe('field_limit_reached')->and(runFields($case['inventory']))->toBe([])->and(Item::query()->count())->toBe(0);
    });
});

describe('a row that is not valid', function (): void {
    /**
     * An inventory with a required text, a number between 1 and 10 and a date, and a CSV with three rows in error among five.
     *
     * @return array{case: array<string, mixed>, csv: string, mapping: list<array<string, mixed>>}
     */
    function runBadRows(): array
    {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name', required: true);
        $quantity = fieldIn($case['inventory'], 'number', 'Quantity', ['min' => '1', 'max' => '10']);
        $bought = fieldIn($case['inventory'], 'date', 'Bought');
        $csv = importFile(importCsv([
            ['Name', 'Quantity', 'Bought'],
            ['Drill', '2', '15/03/2024'],
            ['', '3', '15/03/2024'],
            ['Saw', '50', '31/02/2024'],
            ['Mallet', 'abc', '16/03/2024'],
            ['Chisel', '5', '17/03/2024'],
        ], ';'));

        return ['case' => $case, 'csv' => $csv, 'mapping' => [runToField(0, $name->ulid), runToField(1, $quantity->ulid), runToField(2, $bought->ulid)]];
    }

    it('cancels everything by default, and says which rows, which columns and why', function (): void {
        ['case' => $case, 'csv' => $csv, 'mapping' => $mapping] = runBadRows();

        $import = importDone(importAsked($this, $case, $csv, $mapping, ['date_order' => 'dmy', 'decimal' => 'point']));

        expect($import->status)->toBe(ImportStatus::Failed)
            ->and($import->error)->toBe('invalid_rows')
            ->and([$import->rows_total, $import->rows_imported, $import->rows_failed, $import->fields_created])->toBe([5, 0, 3, 0])
            // A cell that cannot be read at all is reported alone: the rules of the field judge only what was read.
            ->and($import->errors)->toBe([
                ['row' => 3, 'column' => 'Name', 'message' => 'The Name field is required.'],
                ['row' => 4, 'column' => 'Bought', 'message' => '"31/02/2024" is not a date.'],
                ['row' => 5, 'column' => 'Quantity', 'message' => '"abc" is not a number.'],
            ])
            ->and(Item::query()->count())->toBe(0)
            ->and($case['inventory']->refresh()->items_count)->toBe(0)
            ->and($import->path)->toBeNull()
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->toBe([]);
    });

    it('is told to the person in words', function (): void {
        ['case' => $case, 'csv' => $csv, 'mapping' => $mapping] = runBadRows();
        $import = importDone(importAsked($this, $case, $csv, $mapping));

        $shown = inventoryCall($this, $case['team']['owner'], 'GET', importUri($case['inventory'], $import))->assertOk()->json('data');

        expect($shown['status'])->toBe('failed')
            ->and($shown['error'])->toStartWith('Some rows are not valid, so nothing was imported.')
            ->and($shown['rows_failed'])->toBe(3)
            ->and($shown['errors'])->toHaveCount(3);
    });

    it('skips the rows in error and imports the others when told to', function (): void {
        ['case' => $case, 'csv' => $csv, 'mapping' => $mapping] = runBadRows();

        $import = importDone(importAsked($this, $case, $csv, $mapping, ['on_error' => 'skip', 'date_order' => 'dmy', 'decimal' => 'point']));

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and([$import->rows_total, $import->rows_imported, $import->rows_failed])->toBe([5, 2, 3])
            ->and(array_column($import->errors, 'row'))->toBe([3, 4, 5])
            ->and(importItemsOf($case['inventory']))->toBe([
                ['Bought' => '2024-03-15', 'Name' => 'Drill', 'Quantity' => '2'],
                ['Bought' => '2024-03-17', 'Name' => 'Chisel', 'Quantity' => '5'],
            ])
            ->and($case['inventory']->refresh()->items_count)->toBe(2);
    });

    it('keeps the first hundred errors and counts all the rows in error', function (): void {
        $case = importCase();
        $number = fieldIn($case['inventory'], 'number', 'Quantity');
        $rows = array_map(fn (int $row): array => [$row % 10 === 0 ? '1' : 'bad'], range(1, 150));
        $csv = importFile(importCsv([['Quantity'], ...$rows]));

        $import = importDone(importAsked($this, $case, $csv, [runToField(0, $number->ulid)], ['on_error' => 'skip']));

        expect($import->rows_total)->toBe(150)
            ->and($import->rows_failed)->toBe(135)
            ->and($import->rows_imported)->toBe(15)
            ->and($import->errors)->toHaveCount(100)
            ->and($import->errors[0])->toBe(['row' => 2, 'column' => 'Quantity', 'message' => '"bad" is not a number.'])
            ->and($import->errors[99]['row'])->toBe(112);
    });

    it('reports a row that has something past the last column the person was shown, as any row in error', function (string $onError, string $status, int $imported): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        // The columns are worked out from the first rows; a row far below has a third cell.
        $rows = array_map(fn (int $row): array => ["Item {$row}", (string) $row], range(1, 70));
        $rows[59][] = 'stray';
        $csv = importFile(importCsv([['Name', 'Qty'], ...$rows]));

        $import = importDone(importAsked($this, $case, $csv, [runToField(0, $name->ulid)], ['on_error' => $onError]));

        expect($import->status->value)->toBe($status)
            ->and($import->rows_total)->toBe(70)
            ->and($import->rows_failed)->toBe(1)
            ->and($import->rows_imported)->toBe($imported)
            ->and($import->errors)->toBe([['row' => 61, 'column' => null, 'message' => 'This row has more columns than the header.']])
            ->and(Item::query()->count())->toBe($imported);
    })->with([
        'cancelling everything' => ['abort', 'failed', 0],
        'skipping the rows in error' => ['skip', 'completed', 69],
    ]);

    it('reports a value that breaks the rules of a field it creates too', function (): void {
        $case = importCase();
        $csv = importFile(importCsv([['Code', 'Mail'], ['a', 'ada@example.com'], ['b', 'not an address']]));

        $import = importDone(importAsked($this, $case, $csv, [runToNew(0, 'text', 'Code'), runToNew(1, 'email', 'Mail')]));

        expect($import->error)->toBe('invalid_rows')
            ->and($import->errors)->toHaveCount(1)
            ->and($import->errors[0]['row'])->toBe(3)
            ->and($import->errors[0]['column'])->toBe('Mail')
            ->and($import->errors[0]['message'])->toContain('Mail')
            ->and(runFields($case['inventory']))->toBe([]);
    });
});

describe('the limits', function (): void {
    it('fails a file with more rows than an import takes, the empty rows not counted', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        config(['inventories.import_max_rows' => 3]);
        $ok = importFile("Name\r\na\r\n\r\nb\r\n\r\nc\r\n");
        $tooMany = importFile("Name\r\na\r\nb\r\nc\r\nd\r\n");

        $first = importDone(importAsked($this, $case, $ok, [runToField(0, $name->ulid)]));
        $second = importDone(importAsked($this, $case, $tooMany, [runToField(0, $name->ulid)]));

        expect($first->status)->toBe(ImportStatus::Completed)
            ->and($second->status)->toBe(ImportStatus::Failed)
            ->and($second->error)->toBe('too_many_rows')
            ->and(Item::query()->count())->toBe(3);
    });

    it('fails, and writes nothing, when the items would take the inventory past the number of items it may hold', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        itemIn($case['inventory'], [$name->ulid => 'Old']);
        config(['inventories.max_items' => 3]);
        $csv = importFile(importCsv([['Name'], ['a'], ['b'], ['c']]));

        $import = importDone(importAsked($this, $case, $csv, [runToField(0, $name->ulid)]));

        expect($import->error)->toBe('item_limit_reached')
            ->and([$import->rows_total, $import->rows_imported])->toBe([3, 0])
            ->and(Item::query()->count())->toBe(1)
            ->and($case['inventory']->refresh()->items_count)->toBe(1);
    });

    it('fills the inventory exactly to its limit', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        itemIn($case['inventory'], [$name->ulid => 'Old']);
        config(['inventories.max_items' => 3]);

        $import = importDone(importAsked($this, $case, importFile(importCsv([['Name'], ['a'], ['b']])), [runToField(0, $name->ulid)]));

        expect($import->status)->toBe(ImportStatus::Completed)->and($case['inventory']->refresh()->items_count)->toBe(3);
    });

    it('counts only the rows that are valid against the quota of items, when it skips the others', function (): void {
        $case = importCase();
        $number = fieldIn($case['inventory'], 'number', 'Quantity');
        config(['inventories.max_items' => 2]);
        $csv = importFile(importCsv([['Quantity'], ['1'], ['x'], ['y'], ['2']]));

        $import = importDone(importAsked($this, $case, $csv, [runToField(0, $number->ulid)], ['on_error' => 'skip']));

        expect($import->status)->toBe(ImportStatus::Completed)->and($import->rows_imported)->toBe(2);
    });
});

describe('what changes while the import waits', function (): void {
    it('fails when a field of the mapping was deleted', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $quantity = fieldIn($case['inventory'], 'number', 'Quantity');
        $import = importAsked($this, $case, importFile(importCsv([['Name', 'Quantity'], ['a', '1']])), [runToField(0, $name->ulid), runToField(1, $quantity->ulid)]);
        app(InventoryFields::class)->delete($case['inventory'], $quantity);

        $import = importDone($import);

        expect($import->error)->toBe('fields_changed')->and(Item::query()->count())->toBe(0)->and($import->path)->toBeNull()
            // The file is gone: starting again means sending it again.
            ->and($import->failure()?->message())->toBe('The fields of the inventory changed while the import was waiting, so nothing was imported. The file was deleted: send it again to start a new import.');
    });

    it('fails when a field to create was created by someone else meanwhile', function (): void {
        $case = importCase();
        $import = importAsked($this, $case, importFile(importCsv([['Name', 'Other'], ['a', 'b']])), [runToNew(0, 'text', 'Name'), runToNew(1, 'text', 'Other')]);
        fieldIn($case['inventory'], 'text', 'Other');

        $import = importDone($import);

        expect($import->error)->toBe('fields_changed')
            ->and(array_keys(runFields($case['inventory'])))->toBe(['Other'])
            ->and(Item::query()->count())->toBe(0);
    });

    it('fails when the inventory was given a required field that no column fills', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $import = importAsked($this, $case, importFile(importCsv([['Name'], ['a']])), [runToField(0, $name->ulid)]);
        fieldIn($case['inventory'], 'text', 'Reference', required: true);

        $import = importDone($import);

        expect($import->error)->toBe('invalid_rows')->and($import->errors[0]['message'])->toBe('The Reference field is required.')->and(Item::query()->count())->toBe(0);
    });

    it('checks the rows again when the fields judge a value differently, and skips or cancels on the rows that no longer hold', function (string $onError, ?string $error, int $imported): void {
        $case = importCase();
        $quantity = fieldIn($case['inventory'], 'number', 'Quantity', ['decimals' => 0]);
        $import = importAsked($this, $case, importFile(importCsv([['Quantity'], ['5'], ['50'], ['7']])), [runToField(0, $quantity->ulid)], ['on_error' => $onError]);
        // The maximum came down since the person started the import: 50 is still fine for the first look, not for the fields as they are.
        $runner = app(ImportRunner::class);
        app(InventoryFields::class)->update($case['inventory'], $quantity, ['options' => ['max' => '40']]);

        $runner->run($import);
        $import->refresh();

        expect($import->error)->toBe($error)->and($import->rows_imported)->toBe($imported)->and(Item::query()->count())->toBe($imported);
    })->with([
        'cancel everything' => ['abort', 'invalid_rows', 0],
        'skip' => ['skip', null, 2],
    ]);

    it('fails when the person may no longer change the items', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $import = importAsked($this, $case, importFile(importCsv([['Name'], ['a']])), [runToField(0, $name->ulid)], as: $case['team']['editor']);
        $case['team']['workspace']->members()->updateExistingPivot($case['team']['editor']->id, ['role' => WorkspaceRole::Viewer->value]);

        $import = importDone($import);

        expect($import->error)->toBe('not_allowed')->and(Item::query()->count())->toBe(0);
    });

    it('fails when the account that started it is gone... its imports go with it, and the job is dropped', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $import = importAsked($this, $case, importFile(importCsv([['Name'], ['a']])), [runToField(0, $name->ulid)], as: $case['team']['editor']);

        $case['team']['editor']->delete();
        app(ImportRunner::class)->run($import);

        expect(Import::query()->count())->toBe(0)->and(Item::query()->count())->toBe(0);
    });
});

describe('the job', function (): void {
    it('claims the import, so that a job handed twice does nothing the second time', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $import = importAsked($this, $case, importFile(importCsv([['Name'], ['a']])), [runToField(0, $name->ulid)]);

        $runner = app(ImportRunner::class);
        $runner->run($import);
        $runner->run($import);

        expect(Item::query()->count())->toBe(1);
    });

    it('does nothing for an import that is not pending', function (): void {
        $case = importCase();
        $import = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id]);

        app(ImportRunner::class)->run($import);

        expect(importStatus($import))->toBe(ImportStatus::Uploaded);
    });

    it('is queued on the queue of the heavy jobs, tried once, for fifteen minutes', function (): void {
        $case = importCase();
        $job = new ImportItems(Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id]));

        expect($job->queue)->toBe('exports')->and($job->tries)->toBe(1)->and($job->timeout)->toBe(900)->and($job->deleteWhenMissingModels)->toBeTrue();
    });

    it('records the failure of a job the queue gave up on, and deletes the file', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $import = importAsked($this, $case, importFile(importCsv([['Name'], ['a']])), [runToField(0, $name->ulid)]);

        (new ImportItems($import))->failed(new RuntimeException('Timed out.'));
        $import->refresh();

        expect($import->status)->toBe(ImportStatus::Failed)
            ->and($import->error)->toBe(ImportError::ImportFailed->value)
            ->and($import->path)->toBeNull()
            ->and($import->expires_at?->isFuture())->toBeTrue()
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->toBe([]);
    });

    it('does not undo an import that completed before a late failure was reported', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $import = importDone(importAsked($this, $case, importFile(importCsv([['Name'], ['a']])), [runToField(0, $name->ulid)]));

        (new ImportItems($import))->failed(new RuntimeException('Late.'));

        expect(importStatus($import))->toBe(ImportStatus::Completed)->and(Item::query()->count())->toBe(1);
    });

    it('records a file it cannot read, as a failure of the import and not of the worker', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $import = importAsked($this, $case, importFile(importCsv([['Name'], ['a']])), [runToField(0, $name->ulid)]);
        Storage::disk(ATTACHMENT_TEST_DISK)->delete((string) $import->path);

        $import = importDone($import);

        expect($import->status)->toBe(ImportStatus::Failed)->and($import->error)->toBe('file_unreadable');
    });

    it('says why when the file turns out to be too large further down than the analysis looked', function (string $format, string $code, string $sentence): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $rows = array_map(fn (int $number): array => ["Item {$number}"], range(1, 60));
        $path = $format === 'ods'
            // A million empty lines after the sixtieth row.
            ? importOdsAfterRow(importSpreadsheet('ods', [['Name'], ...$rows]), 61, '<table:table-row table:number-rows-repeated="'.ImportRows::MAX_LINES.'"><table:table-cell/></table:table-row>')
            // A row of 300 cells after the sixtieth.
            : importFile(importCsv([['Name'], ...$rows, array_fill(0, 300, 'x')]));
        $import = importAsked($this, $case, $path, [runToField(0, $name->ulid)]);

        $import = importDone($import);

        expect($import->status)->toBe(ImportStatus::Failed)
            ->and($import->error)->toBe($code)
            ->and($import->path)->toBeNull()
            ->and(Item::query()->count())->toBe(0)
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->toBe([]);

        inventoryCall($this, $case['team']['owner'], 'GET', importUri($case['inventory'], $import))->assertOk()
            ->assertJsonPath('data.error', fn (string $message): bool => str_contains($message, $sentence));
    })->with([
        'more lines than a spreadsheet holds' => ['ods', 'too_many_lines', 'The file has more than 1,100,000 lines'],
        'a row too wide' => ['csv', 'too_many_columns', 'A row of the file uses more than 200 columns'],
    ]);

    it('is dropped without a fuss when its import was deleted while it waited', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $import = importAsked($this, $case, importFile(importCsv([['Name'], ['a']])), [runToField(0, $name->ulid)]);

        inventoryCall($this, $case['team']['owner'], 'DELETE', importUri($case['inventory'], $import))->assertNoContent();
        app(ImportRunner::class)->run($import);

        expect(Import::query()->count())->toBe(0)->and(Item::query()->count())->toBe(0);
    });

    it('runs through the queue like any job', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $csv = importFile(importCsv([['Name'], ['a'], ['b']]));
        $import = importAsked($this, $case, $csv, [runToField(0, $name->ulid)]);

        Queue::assertPushed(ImportItems::class, 1);
        app()->call([new ImportItems($import), 'handle']);

        expect(importStatus($import))->toBe(ImportStatus::Completed)->and(Item::query()->count())->toBe(2);
    });
});
