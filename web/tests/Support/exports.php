<?php

declare(strict_types=1);

// Helpers shared by the tests of exports. Each test file loads them with require_once, so a file can be run alone. Their names all
// start with "export" so that they never collide with the helpers of tests/Pest.php or of other test files.

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Exports\ExportFormat;
use App\Inventories\Exports\ExportGenerator;
use App\Inventories\Exports\ExportStatus;
use App\Inventories\Fields\ChoiceType;
use App\Models\Export;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\CSV\Options as CsvReaderOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

require_once __DIR__.'/attachments.php';

/**
 * A request for an export, as the application stores it (pending, nothing written yet), on the fake disk of the test.
 *
 * @param  array<string, mixed>  $attributes  Over the defaults of the factory: a spreadsheet of the whole inventory in English.
 */
function exportRow(Inventory $inventory, User $user, array $attributes = []): Export
{
    return Export::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $user->id, 'disk' => ATTACHMENT_TEST_DISK] + $attributes);
}

/**
 * The folder an export is stored in, as the application works it out.
 */
function exportFolder(Export $export): string
{
    $inventory = $export->inventory()->firstOrFail();

    return AttachmentStorage::exportDirectory($inventory->workspace()->firstOrFail()->ulid, $inventory->ulid, $export->ulid);
}

/**
 * Writes the file of an export where the application would, and marks the export completed with it.
 */
function exportStored(Export $export, string $name = 'export.xlsx', string $contents = 'contents'): Export
{
    $path = exportFolder($export).'/'.$name;
    Storage::disk(ATTACHMENT_TEST_DISK)->put($path, $contents);

    $export->forceFill([
        'status' => ExportStatus::Completed,
        'path' => $path,
        'file_name' => $name,
        'mime' => $export->format->mime(),
        'size' => strlen($contents),
        'items_count' => 0,
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
        'expires_at' => now()->addDay(),
    ])->save();

    return $export;
}

/**
 * An inventory with a field of every type (20 in all) and three items: one with a value everywhere, one with texts that
 * spreadsheets would run as formulas, and one with nothing.
 *
 * @return array{team: array<string, mixed>, inventory: Inventory, fields: array<string, InventoryField>, items: array<string, Item>, choices: array<string, string>}
 */
function exportFixture(string $name = 'Workshop'): array
{
    attachmentDisk();
    config(['app.display_timezone' => 'Europe/Paris']);

    $team = inventoryTeam();
    $inventory = inventoryIn($team['workspace'], $name);
    $fields = [
        'name' => fieldIn($inventory, 'text', 'Name'),
        'notes' => fieldIn($inventory, 'textarea', 'Notes'),
        'quantity' => fieldIn($inventory, 'number', 'Quantity', ['decimals' => 2, 'unit' => 'kg']),
        'price' => fieldIn($inventory, 'money', 'Price', ['currency' => 'EUR']),
        'discount' => fieldIn($inventory, 'percentage', 'Discount', ['decimals' => 1]),
        'stock' => fieldIn($inventory, 'boolean', 'In stock'),
        'bought' => fieldIn($inventory, 'date', 'Bought'),
        'seen' => fieldIn($inventory, 'datetime', 'Seen'),
        'opening' => fieldIn($inventory, 'time', 'Opening'),
        'condition' => fieldIn($inventory, 'select', 'Condition', ['choices' => [['label' => 'New'], ['label' => 'Used']]]),
        'labels' => fieldIn($inventory, 'multiselect', 'Labels', ['choices' => [['label' => 'Fragile'], ['label' => 'Heavy']]]),
        'keywords' => fieldIn($inventory, 'tags', 'Keywords'),
        'website' => fieldIn($inventory, 'url', 'Website'),
        'contact' => fieldIn($inventory, 'email', 'Contact'),
        'phone' => fieldIn($inventory, 'phone', 'Phone'),
        'grade' => fieldIn($inventory, 'rating', 'Grade', ['max' => 5]),
        'color' => fieldIn($inventory, 'color', 'Color'),
        'barcode' => fieldIn($inventory, 'barcode', 'Barcode'),
        'photos' => fieldIn($inventory, 'photo', 'Photos', ['max_files' => 3]),
        'docs' => fieldIn($inventory, 'file', 'Docs', ['max_files' => 3]),
    ];
    $choices = [];

    foreach (['condition', 'labels'] as $key) {
        foreach (ChoiceType::choicesOf($fields[$key]->options) as $choice) {
            $choices[$choice['label']] = $choice['id'];
        }
    }

    $drill = itemIn($inventory, [
        $fields['name']->ulid => 'Drill 18V',
        $fields['notes']->ulid => "Two lines\nof notes",
        $fields['quantity']->ulid => '2.5',
        $fields['price']->ulid => '1234.5',
        $fields['discount']->ulid => '12.5',
        $fields['stock']->ulid => true,
        $fields['bought']->ulid => '2026-03-01',
        $fields['seen']->ulid => '2026-07-01T10:30:00Z',
        $fields['opening']->ulid => '07:05',
        $fields['condition']->ulid => $choices['Used'],
        $fields['labels']->ulid => [$choices['Heavy'], $choices['Fragile']],
        $fields['keywords']->ulid => ['power', 'cordless'],
        $fields['website']->ulid => 'https://example.com/drill',
        $fields['contact']->ulid => 'ada@example.com',
        $fields['phone']->ulid => '+33612345678',
        $fields['grade']->ulid => 4,
        $fields['color']->ulid => '#FF8800',
        $fields['barcode']->ulid => '0012345678905',
    ], $team['owner']);
    $formulas = itemIn($inventory, [
        $fields['name']->ulid => '=1+1',
        $fields['notes']->ulid => '@SUM(A1:A2)',
        $fields['quantity']->ulid => '-3',
        $fields['stock']->ulid => false,
        $fields['keywords']->ulid => ['+cmd'],
        $fields['phone']->ulid => '+33 1 23 45 67 89',
    ], $team['owner']);
    $empty = itemIn($inventory, [], $team['owner']);

    return ['team' => $team, 'inventory' => $inventory, 'fields' => $fields, 'items' => ['drill' => $drill, 'formulas' => $formulas, 'empty' => $empty], 'choices' => $choices];
}

/**
 * Asks for an export the way the API does, without the HTTP: the row is created pending.
 *
 * @param  array<string, mixed>  $attributes
 */
function exportAsked(Inventory $inventory, User $user, ExportFormat $format, array $attributes = []): Export
{
    return exportRow($inventory, $user, ['format' => $format] + $attributes);
}

/**
 * Writes an export now, in the process, as the queue would.
 */
function exportRun(Export $export): Export
{
    app(ExportGenerator::class)->run($export);

    return $export->fresh() ?? $export;
}

/**
 * The path on this machine of the file of a completed export.
 */
function exportLocalPath(Export $export): string
{
    expect($export->path)->not->toBeNull();

    return Storage::disk(ATTACHMENT_TEST_DISK)->path((string) $export->path);
}

/**
 * A spreadsheet or a CSV read back by the library that reads them: the name of its sheet, its rows as values, and as cells.
 *
 * @return array{name: string, rows: list<list<mixed>>, cells: list<list<Cell>>}
 */
function exportSheet(Export $export): array
{
    $path = exportLocalPath($export);
    $reader = match ($export->format) {
        ExportFormat::Xlsx => new XlsxReader,
        ExportFormat::Ods => new OdsReader,
        ExportFormat::Csv => new CsvReader(new CsvReaderOptions(FIELD_DELIMITER: match ($export->option('delimiter')) {
            'semicolon' => ';',
            'tab' => "\t",
            'comma' => ',',
            default => $export->locale === 'fr' ? ';' : ',',
        })),
        default => throw new InvalidArgumentException('Not a spreadsheet.'),
    };
    $reader->open($path);
    $rows = [];
    $cells = [];
    $name = '';

    foreach ($reader->getSheetIterator() as $sheet) {
        $name = $sheet->getName();

        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
            $cells[] = $row->cells;
        }

        break;
    }

    $reader->close();

    return ['name' => $name, 'rows' => $rows, 'cells' => $cells];
}

/**
 * The header and the rows of a spreadsheet as rows keyed by the name of the column, without the identifier and the dates.
 *
 * @return list<array<string, mixed>>
 */
function exportRecords(Export $export): array
{
    $sheet = exportSheet($export);
    $header = array_shift($sheet['rows']);

    return array_map(fn (array $row): array => array_combine($header, array_pad($row, count($header), null)), $sheet['rows']);
}

/**
 * The text of a file inside a zip file (the XML of a workbook, say).
 */
function exportZipEntry(string $path, string $entry): string
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $contents = $zip->getFromName($entry);
    $zip->close();

    expect($contents)->toBeString();

    return (string) $contents;
}
