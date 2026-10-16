<?php

declare(strict_types=1);

// Helpers shared by the tests of imports. Each test file loads them with require_once, so a file can be run alone. Their names all
// start with "import" so that they never collide with the helpers of tests/Pest.php or of other test files.

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Imports\ImportCell;
use App\Inventories\Imports\ImportContext;
use App\Inventories\Imports\ImportFormat;
use App\Inventories\Imports\ImportReader;
use App\Inventories\Imports\ImportStatus;
use App\Models\Import;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateIntervalCell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

require_once __DIR__.'/exports.php';

/** The files the tests wrote, deleted when the process ends. */
const IMPORT_TEST_FILES = 'import-test-files';

/**
 * A scratch file with these bytes, deleted when the tests are over.
 */
function importFile(string $bytes, string $extension = 'csv'): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'import-test-');
    $named = $path.'.'.$extension;
    rename($path, $named);
    file_put_contents($named, $bytes);

    static $registered = false;

    if (! $registered) {
        $registered = true;
        register_shutdown_function(function (): void {
            foreach ($GLOBALS[IMPORT_TEST_FILES] ?? [] as $file) {
                @unlink($file);
            }
        });
    }

    $GLOBALS[IMPORT_TEST_FILES][] = $named;

    return $named;
}

/**
 * A spreadsheet written by the library the exports use: each row a list of values (a number, a string, a bool, a date as a
 * `DateTimeImmutable`, a time as a `DateInterval`, or a ready cell), the first sheet only.
 *
 * @param  'xlsx'|'ods'  $format
 * @param  list<list<mixed>>  $rows
 * @param  list<list<list<mixed>>>  $more  The rows of more sheets, after the first.
 */
function importSpreadsheet(string $format, array $rows, array $more = [], ?int $active = null): string
{
    $path = importFile('', $format);
    $writer = $format === 'xlsx' ? new XlsxWriter : new OdsWriter;
    $writer->openToFile($path);
    $sheets = [$rows, ...$more];

    foreach ($sheets as $index => $sheetRows) {
        if ($index > 0) {
            $writer->addNewSheetAndMakeItCurrent();
        }

        foreach ($sheetRows as $row) {
            $writer->addRow(new Row(array_map(fn (mixed $value): Cell => importCell($value), $row)));
        }
    }

    if ($active !== null) {
        $writer->setCurrentSheet($writer->getSheets()[$active]);
    }

    $writer->close();

    return $path;
}

/**
 * A cell of the library for a value: dates and times get the format a spreadsheet needs to show them as such.
 */
function importCell(mixed $value): Cell
{
    return match (true) {
        $value instanceof Cell => $value,
        $value instanceof DateTimeImmutable => new DateTimeCell($value, new Style(format: $value->format('H:i:s') === '00:00:00' ? 'mm-dd-yy' : 'm/d/yy h:mm')),
        $value instanceof DateInterval => new DateIntervalCell($value, new Style(format: 'h:mm')),
        default => Cell::fromValue($value),
    };
}

/**
 * What a file holds as the import reads it: the raw value of each cell of each row, by row number.
 *
 * @return array{rows: array<int, list<mixed>>, delimiter: string|null, encoding: string|null}
 */
function importRead(string $path, ImportFormat $format, ?string $delimiter = null, ?string $encoding = null): array
{
    $rows = app(ImportReader::class)->open($path, $format, $delimiter, $encoding);
    $read = [];

    foreach ($rows->rows() as $number => $cells) {
        $read[$number] = array_map(fn ($cell) => $cell->value, $cells);
    }

    $found = ['rows' => $read, 'delimiter' => $rows->delimiter, 'encoding' => $rows->encoding];
    $rows->close();

    return $found;
}

/**
 * An uploaded file as the browser sends one.
 */
function importUploaded(string $path, ?string $name = null): UploadedFile
{
    return new UploadedFile($path, $name ?? basename($path), null, null, true);
}

/**
 * A team, an inventory and the fake disk of the files, ready for an import.
 *
 * @return array{team: array<string, mixed>, inventory: Inventory}
 */
function importCase(string $name = 'Stock'): array
{
    attachmentDisk();
    config(['app.display_timezone' => 'Europe/Paris']);

    $team = inventoryTeam();

    return ['team' => $team, 'inventory' => inventoryIn($team['workspace'], $name)];
}

/**
 * The address of the imports of an inventory, or of one of them.
 */
function importUri(Inventory $inventory, ?Import $import = null, string $suffix = ''): string
{
    return "/api/v1/inventories/{$inventory->ulid}/imports".($import === null ? '' : "/{$import->ulid}").$suffix;
}

/**
 * Sends a file the way the web front does.
 *
 * @param  array<string, mixed>  $data  More fields of the form.
 */
function importPost(object $test, ?User $as, Inventory $inventory, string $path, array $data = [], ?string $name = null): TestResponse
{
    return inventoryCall($test, $as, 'POST', importUri($inventory), ['file' => importUploaded($path, $name)] + $data);
}

/**
 * The CSV of rows of texts, written the way a spreadsheet does (comma or semicolon, quotes only where needed).
 *
 * @param  list<list<string>>  $rows
 */
function importCsv(array $rows, string $delimiter = ',', string $eol = "\r\n"): string
{
    return implode($eol, array_map(fn (array $row): string => implode($delimiter, array_map(
        fn (string $text): string => preg_match('/["\r\n'.preg_quote($delimiter, '/').']/', $text) === 1 ? '"'.str_replace('"', '""', $text).'"' : $text,
        $row,
    )), $rows)).$eol;
}

/**
 * Stores bytes as the source of an import, where the application stores one, and points the import at it.
 */
function importStored(Import $import, string $bytes): Import
{
    $inventory = $import->inventory()->firstOrFail();
    $path = AttachmentStorage::importDirectory($inventory->workspace()->firstOrFail()->ulid, $inventory->ulid, $import->ulid).'/source.'.$import->format->storedExtension();
    Storage::disk(ATTACHMENT_TEST_DISK)->put($path, $bytes);
    $import->forceFill(['path' => $path, 'disk' => ATTACHMENT_TEST_DISK, 'size' => strlen($bytes)])->save();

    return $import;
}

/**
 * The status of an import, read again.
 */
function importStatus(Import $import): ImportStatus
{
    return $import->refresh()->status;
}

/**
 * A field as the import builds one for a column it creates: not saved, with the options of its type in canonical form.
 *
 * @param  array<string, mixed>  $options
 */
function importField(string $type, array $options = [], bool $required = false): InventoryField
{
    $types = FieldTypes::standard();
    $normalized = $types->get($type)->normalizeOptions($options, null);
    $field = new InventoryField(['name' => ucfirst($type), 'type' => $type, 'options' => $normalized === [] ? null : $normalized, 'required' => $required]);
    $field->ulid = strtolower((string) Str::ulid());

    return $field;
}

/**
 * What a field of a type reads from a cell value, with the settings of an import.
 */
function importConvert(InventoryField|string $field, mixed $value, string $decimal = 'point', string $order = 'dmy', string $zone = 'Europe/Paris'): mixed
{
    $field = is_string($field) ? importField($field) : $field;
    $context = new ImportContext($decimal, $order, $zone, 'en');
    // A text starting with `@` stands for a date and time cell (a data set cannot hold an object built later).
    $value = is_string($value) && str_starts_with($value, '@') ? new DateTimeImmutable(substr($value, 1), new DateTimeZone('UTC')) : $value;

    return FieldTypes::standard()->get($field->type)->importValue(new ImportCell($value, is_string($value)), $field, $context);
}
