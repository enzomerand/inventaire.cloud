<?php

declare(strict_types=1);

use App\Inventories\Exports\ExportError;
use App\Inventories\Exports\ExportFormat;
use App\Inventories\Exports\ExportStatus;
use App\Models\Export;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/exports.php';

/*
 * The spreadsheets an export writes, read back with the library that reads them: the cells keep their types, nothing is ever a
 * formula, the header is bold and frozen with a filter, and the sheet is named after the inventory.
 */

uses(RefreshDatabase::class);

/**
 * An export of a fixture inventory in a spreadsheet format, written and completed.
 *
 * @return array{0: Export, 1: array<string, mixed>}
 */
function spreadsheetOf(ExportFormat $format, ?array $case = null): array
{
    $case ??= exportFixture();
    $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], $format));

    expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error);

    return [$export, $case];
}

describe('the cells', function (): void {
    it('keep their types in a workbook', function (ExportFormat $format): void {
        [$export, $case] = spreadsheetOf($format);
        $records = collect(exportRecords($export))->keyBy('Name');
        $drill = $records['Drill 18V'];

        expect($drill['Quantity (kg)'])->toBe(2.5)
            ->and($drill['Price (EUR)'])->toBe(1234.5)
            ->and($drill['Discount (%)'])->toBe(12.5)
            ->and($drill['In stock'])->toBeTrue()
            ->and($drill['Grade'])->toBe(4)
            ->and($drill['Bought'])->toBeInstanceOf(DateTimeInterface::class)
            ->and($drill['Bought']->format('Y-m-d H:i'))->toBe('2026-03-01 00:00')
            // 10:30 UTC, in the timezone of the instance (Paris, in summer).
            ->and($drill['Seen']->format('Y-m-d H:i'))->toBe('2026-07-01 12:30')
            ->and($drill['Name'])->toBe('Drill 18V')
            ->and($drill['Notes'])->toBe("Two lines\nof notes")
            ->and($drill['Condition'])->toBe('Used')
            ->and($drill['Labels'])->toBe('Heavy, Fragile')
            ->and($drill['Keywords'])->toBe('power, cordless')
            ->and($drill['Website'])->toBe('https://example.com/drill')
            ->and($drill['Contact'])->toBe('ada@example.com')
            // Text stays text: the leading zeros and the plus sign survive.
            ->and($drill['Phone'])->toBe('+33612345678')
            ->and($drill['Barcode'])->toBe('0012345678905')
            ->and($drill['Color'])->toBe('#ff8800')
            ->and($drill['Identifier'])->toBe($case['items']['drill']->ulid);

        $second = $records['=1+1'];

        expect($second['Quantity (kg)'])->toBe(-3)
            ->and($second['In stock'])->toBeFalse();
    })->with([ExportFormat::Xlsx, ExportFormat::Ods]);

    it('write a time as a time of the day', function (): void {
        [$xlsx, $case] = spreadsheetOf(ExportFormat::Xlsx);
        [$ods] = spreadsheetOf(ExportFormat::Ods, $case);

        $opening = collect(exportRecords($xlsx))->firstWhere('Name', 'Drill 18V')['Opening'];
        $interval = collect(exportRecords($ods))->firstWhere('Name', 'Drill 18V')['Opening'];

        expect($opening)->toBeInstanceOf(DateTimeInterface::class)
            ->and($opening->format('H:i'))->toBe('07:05')
            ->and($interval)->toBeInstanceOf(DateInterval::class)
            ->and([$interval->h, $interval->i])->toBe([7, 5]);
    });

    it('leave what is empty empty', function (ExportFormat $format): void {
        [$export, $case] = spreadsheetOf($format);
        $row = collect(exportRecords($export))->firstWhere('Identifier', $case['items']['empty']->ulid);

        expect(array_filter($row, fn (mixed $value, string $header): bool => ! in_array($header, ['Identifier', 'Created on', 'Modified on'], true) && $value !== '' && $value !== null, ARRAY_FILTER_USE_BOTH))->toBe([]);
    })->with([ExportFormat::Xlsx, ExportFormat::Ods]);

    it('give the dates the item was created and changed, in the timezone of the instance', function (): void {
        [$export, $case] = spreadsheetOf(ExportFormat::Xlsx);
        $created = collect(exportRecords($export))->firstWhere('Identifier', $case['items']['drill']->ulid)['Created on'];

        expect($created)->toBeInstanceOf(DateTimeInterface::class)
            ->and($created->format('Y-m-d H:i'))->toBe($case['items']['drill']->created_at->copy()->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
    });

    it('put the unit or the currency in the header, after the name of the field', function (): void {
        [$export] = spreadsheetOf(ExportFormat::Xlsx);

        expect(exportSheet($export)['rows'][0])->toBe([
            'Identifier', 'Name', 'Notes', 'Quantity (kg)', 'Price (EUR)', 'Discount (%)', 'In stock', 'Bought', 'Seen', 'Opening', 'Condition',
            'Labels', 'Keywords', 'Website', 'Contact', 'Phone', 'Grade', 'Color', 'Barcode', 'Photos', 'Docs', 'Created on', 'Modified on',
        ]);
    });

    it('write the items newest first, as the grid shows them', function (): void {
        [$export, $case] = spreadsheetOf(ExportFormat::Xlsx);

        expect(array_column(exportRecords($export), 'Identifier'))->toBe([$case['items']['empty']->ulid, $case['items']['formulas']->ulid, $case['items']['drill']->ulid]);
    });
});

describe('a text that looks like a formula', function (): void {
    it('is a text in the workbook, never a formula', function (ExportFormat $format, string $sheet, string $formula): void {
        [$export] = spreadsheetOf($format);
        $xml = exportZipEntry(exportLocalPath($export), $sheet);

        expect($xml)->not->toContain($formula)
            ->and(collect(exportRecords($export))->pluck('Name')->all())->toContain('=1+1')
            ->and(collect(exportRecords($export))->pluck('Notes')->all())->toContain('@SUM(A1:A2)')
            ->and(collect(exportRecords($export))->pluck('Keywords')->all())->toContain('+cmd');
    })->with([
        'xlsx' => [ExportFormat::Xlsx, 'xl/worksheets/sheet1.xml', '<f>'],
        'ods' => [ExportFormat::Ods, 'content.xml', 'table:formula'],
    ]);

    it('is a text even in the name of a field', function (): void {
        $case = exportFixture();
        fieldIn($case['inventory'], 'text', '=HYPERLINK("http://example.com")');
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Xlsx));

        expect(exportZipEntry(exportLocalPath($export), 'xl/worksheets/sheet1.xml'))->not->toContain('<f>')
            ->and(exportSheet($export)['rows'][0])->toContain('=HYPERLINK("http://example.com")');
    });
});

describe('the sheet', function (): void {
    it('is named after the inventory, with a header that is bold, frozen and filtered', function (): void {
        [$export] = spreadsheetOf(ExportFormat::Xlsx);
        $path = exportLocalPath($export);
        $sheet = exportZipEntry($path, 'xl/worksheets/sheet1.xml');

        expect(exportSheet($export)['name'])->toBe('Workshop')
            // The first row frozen, a filter on the whole table (23 columns, a header and three items), real widths.
            ->and($sheet)->toContain('<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>')
            ->and($sheet)->toContain('<autoFilter ref="A1:W4"/>')
            ->and($sheet)->toContain('<col min="1" max="1" width="28" customWidth="true"/>')
            ->and(exportZipEntry($path, 'xl/styles.xml'))->toContain('<b/>');
    });

    it('is the same in an ODS workbook', function (): void {
        [$export] = spreadsheetOf(ExportFormat::Ods);
        $path = exportLocalPath($export);

        expect(exportSheet($export)['name'])->toBe('Workshop')
            ->and(exportZipEntry($path, 'content.xml'))->toContain('table:target-range-address="\'Workshop\'.A1:\'Workshop\'.W4"')
            ->and(exportZipEntry($path, 'content.xml'))->toContain('fo:font-weight="bold"')
            ->and(exportZipEntry($path, 'settings.xml'))->toContain('"VerticalSplitPosition" config:type="int">1<');
    });

    it('has the number formats of the fields: their decimals, the thousands of an amount, the built-in formats of dates', function (): void {
        [$export] = spreadsheetOf(ExportFormat::Xlsx);
        $styles = exportZipEntry(exportLocalPath($export), 'xl/styles.xml');

        // 2 = 0.00 (quantity), 4 = #,##0.00 (price), 1 = 0 (grade), 14 = date, 20 = time, 22 = date and time; 0.0 (discount) is custom.
        expect($styles)->toContain('formatCode="0.0"')
            ->and($styles)->toContain('numFmtId="2"')
            ->and($styles)->toContain('numFmtId="4"')
            ->and($styles)->toContain('numFmtId="1"')
            ->and($styles)->toContain('numFmtId="14"')
            ->and($styles)->toContain('numFmtId="20"')
            ->and($styles)->toContain('numFmtId="22"');
    });

    it('uses one font for the plain and the styled cells', function (): void {
        [$export] = spreadsheetOf(ExportFormat::Xlsx);

        expect(substr_count(exportZipEntry(exportLocalPath($export), 'xl/styles.xml'), '<name val="Arial"/>'))->toBeGreaterThan(5)
            ->and(exportZipEntry(exportLocalPath($export), 'xl/styles.xml'))->not->toContain('Calibri');
    });

    it('has a name that both formats accept whatever the name of the inventory', function (string $name, string $expected): void {
        $case = exportFixture($name);

        foreach ([ExportFormat::Xlsx, ExportFormat::Ods] as $format) {
            $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], $format));

            expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error)
                ->and(exportSheet($export)['name'])->toBe($expected);
        }
    })->with([
        'forbidden characters' => ['Q1: [Tools]/Shop\\*?', 'Q1 Tools Shop'],
        'quotes and ampersand' => ["Tom & Jerry's <tools>", 'Tom + Jerry’s tools'],
        'longer than 31' => ['An inventory with a very long name indeed', 'An inventory with a very long n'],
        'accents and emoji' => ['Matériel électrique ⚡', 'Matériel électrique ⚡'],
    ]);

    it('has a header and no row for an inventory without items', function (ExportFormat $format): void {
        $case = exportFixture();
        $empty = inventoryIn($case['team']['workspace'], 'Nothing');
        fieldIn($empty, 'text', 'Name');
        $export = exportRun(exportAsked($empty, $case['team']['owner'], $format));

        expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error)
            ->and(exportSheet($export)['rows'])->toHaveCount(1)
            ->and($export->items_count)->toBe(0)
            ->and(exportSheet($export)['rows'][0])->toBe(['Identifier', 'Name', 'Created on', 'Modified on']);
    })->with([ExportFormat::Xlsx, ExportFormat::Ods]);
});

describe('the numbers and the dates a spreadsheet cannot keep', function (): void {
    it('are written as text when they would lose digits or do not exist', function (ExportFormat $format): void {
        $case = exportFixture();
        $inventory = inventoryIn($case['team']['workspace'], 'Limits');
        $big = fieldIn($inventory, 'number', 'Big', ['decimals' => 6]);
        $day = fieldIn($inventory, 'date', 'Day');
        $moment = fieldIn($inventory, 'datetime', 'Moment');

        itemIn($inventory, [$big->ulid => '1234567890.123456', $day->ulid => '1850-05-17', $moment->ulid => '1850-05-17T10:00:00Z']);
        itemIn($inventory, [$big->ulid => '999999999.999999', $day->ulid => '1900-01-01']);

        $export = exportRun(exportAsked($inventory, $case['team']['owner'], $format));
        [$small, $large] = exportRecords($export);

        // 16 significant digits: a spreadsheet keeps 15, so the number is written whole as text.
        expect($large['Big'])->toBe('1234567890.123456')
            ->and($large['Day'])->toBe('1850-05-17')
            ->and($large['Moment'])->toBe((new DateTimeImmutable('1850-05-17T10:00:00Z'))->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'))
            // 15 digits: exact as a number.
            ->and($small['Big'])->toBe(999999999.999999)
            ->and($small['Day'])->toBeInstanceOf(DateTimeInterface::class);
    })->with([ExportFormat::Xlsx, ExportFormat::Ods]);

    it('keep up to 15 digits exactly', function (): void {
        $case = exportFixture();
        $inventory = inventoryIn($case['team']['workspace'], 'Exact');
        $amount = fieldIn($inventory, 'number', 'Amount', ['decimals' => 3]);
        $whole = fieldIn($inventory, 'number', 'Whole');

        itemIn($inventory, [$amount->ulid => '123456789012.345', $whole->ulid => '999999999999999']);
        itemIn($inventory, [$amount->ulid => '0.001', $whole->ulid => '-12']);

        $export = exportRun(exportAsked($inventory, $case['team']['owner'], ExportFormat::Xlsx));
        [$small, $large] = exportRecords($export);

        expect($large['Amount'])->toBe(123456789012.345)
            ->and($large['Whole'])->toBe(999999999999999)
            ->and($small['Amount'])->toBe(0.001)
            ->and($small['Whole'])->toBe(-12);
    });
});

describe('a workbook that cannot be completed', function (): void {
    it('is failed, with the first error in the log, and the library leaves no scratch file or open file behind', function (ExportFormat $format): void {
        $case = exportFixture();
        $scratch = fn (): array => glob(storage_path('framework/exports/*')) ?: [];
        $before = $scratch();
        $seen = 0;
        // The library has opened its file, written the header and its scratch files when the third item cannot be read.
        Event::listen('eloquent.retrieved: '.Item::class, function () use (&$seen): void {
            if (++$seen === 3) {
                throw new RuntimeException('The disk is on fire.');
            }
        });
        Log::spy();

        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], $format));

        expect($export->status)->toBe(ExportStatus::Failed)
            ->and($export->error)->toBe(ExportError::GenerationFailed->value)
            ->and($export->path)->toBeNull()
            ->and($scratch())->toBe($before);

        // The error that counts is the first, not what the library says when it is closed in a hurry.
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['exception']->getMessage() === 'The disk is on fire.')->once();
    })->with([ExportFormat::Xlsx, ExportFormat::Ods]);
});
