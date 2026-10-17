<?php

declare(strict_types=1);

// Helpers shared by the tests of imports. Each test file loads them with require_once, so a file can be run alone. Their names all
// start with "import" so that they never collide with the helpers of tests/Pest.php or of other test files.

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Fields\ChoiceType;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Imports\ImportAnalysis;
use App\Inventories\Imports\ImportAnalyzer;
use App\Inventories\Imports\ImportCell;
use App\Inventories\Imports\ImportContext;
use App\Inventories\Imports\ImportFormat;
use App\Inventories\Imports\ImportOptions;
use App\Inventories\Imports\ImportReader;
use App\Inventories\Imports\ImportRunner;
use App\Inventories\Imports\ImportStatus;
use App\Models\Import;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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
 * Rewrites a part of a spreadsheet (a zip archive) in place with a function of its text: the way to give a file what the library that
 * writes spreadsheets never writes (a cell repeated fifty million times, a sheet that announces thousands of columns...).
 *
 * @param  callable(string): string  $change
 */
function importPatch(string $path, string $part, callable $change): string
{
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->addFromString($part, $change((string) $zip->getFromName($part)));
    $zip->close();

    return $path;
}

/**
 * An Excel workbook whose one sheet has these rows (the XML of the rows, and no dimension): the way to give a file what the library that
 * writes spreadsheets never writes.
 */
function importXlsxRows(string $rows): string
{
    return importPatch(importSpreadsheet('xlsx', [['a']]), 'xl/worksheets/sheet1.xml', fn (string $xml): string => (string) preg_replace(['~<dimension [^>]*/>~', '~<sheetData>.*</sheetData>~s'], ['', '<sheetData>'.$rows.'</sheetData>'], $xml));
}

/**
 * Gives a part of an Excel workbook another name, in the archive and in the relationships of the workbook that point to it: the library
 * follows the relationships, not the names, so the sheet is still the sheet.
 *
 * @param  string|null  $target  What the relationship says, when it is not the name relative to `xl/`.
 */
function importRenamePart(string $path, string $from, string $to, ?string $target = null): string
{
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->renameName($from, $to);
    $zip->close();

    return importPatch($path, 'xl/_rels/workbook.xml.rels', fn (string $xml): string => str_replace('Target="'.substr($from, strlen('xl/')).'"', 'Target="'.($target ?? substr($to, strlen('xl/'))).'"', $xml));
}

/**
 * Puts some XML of an OpenDocument sheet after the n-th row of the first sheet (the first row is 1).
 */
function importOdsAfterRow(string $path, int $row, string $xml): string
{
    return importPatch($path, 'content.xml', function (string $content) use ($row, $xml): string {
        $seen = 0;

        return (string) preg_replace_callback('~</table:table-row>~', function (array $found) use (&$seen, $row, $xml): string {
            return ++$seen === $row ? $found[0].$xml : $found[0];
        }, $content);
    });
}

/**
 * A spreadsheet archive that says its one part weighs `$announced` bytes and unpacks to `$real`: the sizes are rewritten in the headers
 * of the archive, where whoever makes the file can put anything.
 *
 * @param  'xlsx'|'ods'  $format
 */
function importForgedArchive(string $format, int $announced, int $real): string
{
    $path = importFile('', $format);
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString($format === 'xlsx' ? 'xl/workbook.xml' : 'content.xml', str_repeat('a', $real));
    $zip->close();

    $bytes = (string) file_get_contents($path);

    // The central directory (`PK\1\2`) keeps the size at 24 bytes into its entry, the header of the part (`PK\3\4`) at 22.
    foreach (["PK\x01\x02" => 24, "PK\x03\x04" => 22] as $signature => $offset) {
        $position = 0;

        while (($position = strpos($bytes, $signature, $position)) !== false) {
            $bytes = substr_replace($bytes, pack('V', $announced), $position + $offset, 4);
            $position += 4;
        }
    }

    file_put_contents($path, $bytes);

    return $path;
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
 * @param  array<string, string>  $headers
 */
function importPost(object $test, ?User $as, Inventory $inventory, string $path, array $data = [], ?string $name = null, array $headers = []): TestResponse
{
    return attachmentUpload($test, $as, importUri($inventory), importUploaded($path, $name), $data, $headers);
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

/**
 * What the analysis says about a file: the columns, the proposal and the options.
 *
 * @param  list<InventoryField>  $fields
 * @param  array<string, mixed>  $options  Over the defaults of the options (`has_header`, `delimiter`, `encoding`, `decimal`, `date_order`).
 * @param  list<string>  $detect
 */
function importAnalyze(string $path, ImportFormat $format = ImportFormat::Csv, array $fields = [], array $options = [], bool $canManageFields = true, string $locale = 'en', array $detect = ['decimal', 'date_order']): ImportAnalysis
{
    $rows = app(ImportReader::class)->open($path, $format);
    $settings = ImportOptions::fromArray([...$options, 'delimiter' => $rows->delimiter, 'encoding' => $rows->encoding], $locale);

    try {
        return app(ImportAnalyzer::class)->analyze($rows, $settings, collect($fields), $canManageFields, $locale, $detect);
    } finally {
        $rows->close();
    }
}

/**
 * Sends a file and starts its import the way the web front does, with the queue faked so that nothing runs: the import is `pending`.
 *
 * @param  array<string, mixed>  $case  As {@see importCase()} gives it.
 * @param  list<array<string, mixed>>|null  $mapping  Where the columns go; null keeps the proposal of the analysis.
 * @param  array<string, mixed>  $settings  More of the request to start: `decimal`, `date_order`, `on_error`.
 * @param  array<string, mixed>  $upload  More of the request to send the file: `has_header`, `delimiter`, `encoding`.
 * @param  array<string, string>  $headers  Headers of both requests (`Accept-Language`).
 */
function importAsked(object $test, array $case, string $path, ?array $mapping = null, array $settings = [], ?User $as = null, ?string $name = null, array $upload = [], array $headers = []): Import
{
    Queue::fake();
    $as ??= $case['team']['owner'];

    $sent = importPost($test, $as, $case['inventory'], $path, $upload, $name, $headers)->assertCreated();
    $import = Import::query()->where('ulid', $sent->json('data.id'))->firstOrFail();

    inventoryCall($test, $as, 'POST', importUri($case['inventory'], $import, '/start'), ['mapping' => $mapping ?? $sent->json('data.mapping')] + $settings, $headers)->assertStatus(202);

    return $import->refresh();
}

/**
 * Does the work of the job for an import that is pending, and reads the import again.
 */
function importDone(Import $import): Import
{
    app(ImportRunner::class)->run($import);

    return $import->refresh();
}

/**
 * The items of an inventory as plain values by the name of their field (the labels of the choices instead of their ids), in the order they
 * were added.
 *
 * @return list<array<string, mixed>>
 */
function importItemsOf(Inventory $inventory): array
{
    $fields = $inventory->fields()->get();
    $byUlid = $fields->keyBy('ulid');
    $items = [];

    foreach (Item::query()->where('inventory_id', $inventory->id)->orderBy('id')->get() as $item) {
        $values = [];

        foreach ($item->values as $ulid => $value) {
            $field = $byUlid[$ulid];
            $labels = array_column(ChoiceType::choicesOf($field->options), 'label', 'id');
            $values[$field->name] = match ($field->type) {
                'select' => $labels[$value],
                'multiselect' => array_map(fn (string $id): string => $labels[$id], $value),
                default => $value,
            };
        }

        ksort($values);
        $items[] = $values;
    }

    return $items;
}

/**
 * A pending import of a CSV of one column, with its file stored on the local disk (the disk the processes of a concurrency test share).
 *
 * @param  array<string, mixed>  $case  As {@see importCase()} gives it.
 * @param  list<array<string, mixed>>  $mapping
 * @param  list<string>  $values  The values of the column, under the header.
 */
function importPendingOn(array $case, array $mapping, array $values, string $header = 'Name'): Import
{
    $import = Import::factory()->create([
        'inventory_id' => $case['inventory']->id,
        'user_id' => $case['team']['owner']->id,
        'status' => ImportStatus::Pending,
        'disk' => 'local',
        'columns' => [['index' => 0, 'header' => $header, 'samples' => array_slice($values, 0, 3), 'guess' => 'text']],
        'mapping' => $mapping,
        'expires_at' => null,
    ]);
    $inventory = $case['inventory'];
    $path = AttachmentStorage::importDirectory($case['team']['workspace']->ulid, $inventory->ulid, $import->ulid).'/source.csv';
    Storage::disk('local')->put($path, importCsv([[$header], ...array_map(fn (string $value): array => [$value], $values)]));
    $import->forceFill(['path' => $path])->save();

    return $import;
}

/**
 * Runs something once, right after the first statement that matches the pattern has been executed: the way to make something happen at a precise
 * moment of a piece of code, in the same transaction.
 */
function importAfterStatement(string $pattern, Closure $then): void
{
    $done = false;

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($pattern, $then, &$done): void {
        if (! $done && preg_match($pattern, $query->sql) === 1) {
            $done = true;
            $then();
        }
    });
}
