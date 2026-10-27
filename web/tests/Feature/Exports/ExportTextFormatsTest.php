<?php

declare(strict_types=1);

use App\Inventories\Exports\ExportFormat;
use App\Inventories\Exports\ExportStatus;
use App\Models\Export;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/exports.php';

/*
 * The CSV and the JSON an export writes, read back as plain text: the byte order mark, the separators and decimals of each
 * language, dates and yes/no, the quote in front of a text a spreadsheet would run, and the document of the JSON.
 */

uses(RefreshDatabase::class);

/**
 * A completed CSV export of the fixture inventory.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Export, 1: array<string, mixed>}
 */
function csvOf(array $attributes = [], ?array $case = null): array
{
    $case ??= exportFixture();
    $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv, $attributes));

    expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error);

    return [$export, $case];
}

/**
 * The raw bytes of the file of an export.
 */
function exportBytes(Export $export): string
{
    return (string) file_get_contents(exportLocalPath($export));
}

describe('the CSV', function (): void {
    it('is UTF-8 with a byte order mark and lines that end with CRLF', function (): void {
        [$export] = csvOf();
        $bytes = exportBytes($export);

        expect(substr($bytes, 0, 3))->toBe("\xEF\xBB\xBF")
            ->and(mb_check_encoding($bytes, 'UTF-8'))->toBeTrue()
            ->and(substr_count($bytes, "\r\n"))->toBeGreaterThanOrEqual(4)
            ->and($export->mime)->toBe('text/csv; charset=UTF-8')
            ->and($export->file_name)->toEndWith('.csv');
    });

    it('is separated by semicolons with decimal commas in French, and by commas with decimal points otherwise', function (): void {
        [$french, $case] = csvOf(['locale' => 'fr']);
        $english = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv, ['locale' => 'en']));

        $fr = collect(exportRecords($french))->firstWhere('Name', 'Drill 18V');

        // The French file is read with semicolons by exportSheet(), the English one with commas.
        expect(explode(';', explode("\r\n", exportBytes($french))[0])[1])->toBe('Name')
            ->and($fr['Quantity (kg)'])->toBe('2,50')
            ->and($fr['Price (EUR)'])->toBe('1234,50')
            ->and($fr['Discount (%)'])->toBe('12,5')
            ->and($fr['In stock'])->toBe('Oui')
            ->and(collect(exportRecords($french))->firstWhere('Identifiant', $case['items']['formulas']->ulid)['In stock'])->toBe('Non');

        $en = collect(exportRecords($english))->firstWhere('Name', 'Drill 18V');

        expect(explode(',', explode("\r\n", exportBytes($english))[0])[1])->toBe('Name')
            ->and($en['Quantity (kg)'])->toBe('2.50')
            ->and($en['Price (EUR)'])->toBe('1234.50')
            ->and($en['Discount (%)'])->toBe('12.5')
            ->and($en['In stock'])->toBe('Yes');
    });

    it('takes the separator the person chose, and the decimal comma goes with the semicolon only', function (string $delimiter, string $character, string $decimal): void {
        [$export] = csvOf(['options' => ['delimiter' => $delimiter], 'locale' => 'en']);
        $first = explode("\r\n", exportBytes($export))[0];

        expect(explode($character, ltrim($first, "\xEF\xBB\xBF"))[1])->toBe('Name')
            ->and(collect(exportRecords($export))->firstWhere('Name', 'Drill 18V')['Price (EUR)'])->toBe('1234'.$decimal.'50');
    })->with([
        'comma' => ['comma', ',', '.'],
        'semicolon' => ['semicolon', ';', ','],
        'tab' => ['tab', "\t", '.'],
    ]);

    it('writes ISO dates, the days and the moments in the timezone of the instance, and plain times', function (): void {
        [$export, $case] = csvOf();
        $drill = collect(exportRecords($export))->firstWhere('Name', 'Drill 18V');

        expect($drill['Bought'])->toBe('2026-03-01')
            ->and($drill['Seen (Europe/Paris)'])->toBe('2026-07-01 12:30')
            ->and($drill['Opening'])->toBe('07:05')
            ->and($drill['Created on (Europe/Paris)'])->toBe($case['items']['drill']->created_at->copy()->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
    });

    it('writes the choices by label, the lists joined, the files by name and the colors in lowercase', function (): void {
        $case = exportFixture();
        $item = $case['items']['drill'];
        attachmentTo($case['inventory'], $item, attachmentImage('front.png'), $case['team']['owner'], $case['fields']['photos']);
        attachmentTo($case['inventory'], $item, attachmentImage('back.png'), $case['team']['owner'], $case['fields']['photos']);
        attachmentTo($case['inventory'], $item, attachmentPdf('manual.pdf'), $case['team']['owner'], $case['fields']['docs']);

        [$export] = csvOf([], $case);
        $drill = collect(exportRecords($export))->firstWhere('Name', 'Drill 18V');

        expect($drill['Condition'])->toBe('Used')
            ->and($drill['Labels'])->toBe('Heavy, Fragile')
            ->and($drill['Photos'])->toBe('front.png, back.png')
            ->and($drill['Docs'])->toBe('manual.pdf')
            ->and($drill['Color'])->toBe('#ff8800')
            ->and($drill['Grade'])->toBe('4');
    });

    it('puts a quote in front of a text a spreadsheet would run as a formula, and in front of nothing else', function (): void {
        $case = exportFixture();
        $inventory = inventoryIn($case['team']['workspace'], 'Dangerous');
        $text = fieldIn($inventory, 'text', 'Text');
        $number = fieldIn($inventory, 'number', 'Number');
        $dangerous = ['=1+1', '+33 1', '-dash', '@SUM(A1)', "\tTabbed", "\rReturn"];

        // The single-line text type trims the tab and the return: they reach the file through the long text field.
        $notes = fieldIn($inventory, 'textarea', 'Notes');

        foreach ($dangerous as $value) {
            itemIn($inventory, [$text->ulid => ltrim($value), $number->ulid => '-5', $notes->ulid => 'ok '.$value]);
        }

        itemIn($inventory, [$text->ulid => 'Plain = text', $notes->ulid => 'Fine+']);

        [$export] = csvOf([], ['inventory' => $inventory] + $case);
        $records = exportRecords($export);

        expect(array_column($records, 'Text'))->toContain("'=1+1", "'+33 1", "'-dash", "'@SUM(A1)", 'Plain = text')
            // A number is not a text: a minus sign stays one.
            ->and(array_column($records, 'Number'))->toContain('-5')
            ->and(array_column($records, 'Notes'))->toContain('Fine+');
    });

    it('guards a header that looks like a formula too, and quotes what needs quoting', function (): void {
        $case = exportFixture();
        $inventory = inventoryIn($case['team']['workspace'], 'Quoted');
        $evil = fieldIn($inventory, 'text', '=HYPERLINK("x")');
        $plain = fieldIn($inventory, 'textarea', 'Comment, with "quotes"');
        itemIn($inventory, [$evil->ulid => 'a;b,c', $plain->ulid => "He said \"hi\"\nand left"]);

        [$export] = csvOf(['options' => ['delimiter' => 'comma']], ['inventory' => $inventory] + $case);
        $rows = exportSheet($export)['rows'];

        expect($rows[0][1])->toBe("'=HYPERLINK(\"x\")")
            ->and($rows[0][2])->toBe('Comment, with "quotes"')
            ->and($rows[1][1])->toBe('a;b,c')
            ->and($rows[1][2])->toBe("He said \"hi\"\nand left");
    });

    it('has the header and the items, whatever the number of items', function (): void {
        $case = exportFixture();
        $empty = inventoryIn($case['team']['workspace'], 'Nothing');
        fieldIn($empty, 'money', 'Price', ['currency' => 'USD']);
        [$export] = csvOf([], ['inventory' => $empty] + $case);

        expect(exportSheet($export)['rows'])->toBe([['Identifier', 'Price (USD)', 'Created on (Europe/Paris)', 'Modified on (Europe/Paris)']])
            ->and($export->items_count)->toBe(0);
    });
});

describe('the zone of the moments in a CSV', function (): void {
    it('is named in the header of every column of moments and of no other, with the cells left exact', function (string $zone, string $locale, string $summer, string $winter): void {
        $case = exportFixture();
        // A winter moment on one item and a summer one on the other: the header must be right for both, so it names the zone, not an offset.
        $other = $case['items']['formulas'];
        $other->forceFill(['values' => [...$other->values, $case['fields']['seen']->ulid => '2026-01-15T10:30:00Z']])->save();
        config(['app.display_timezone' => $zone]);

        [$export] = csvOf(['locale' => $locale], $case);
        $french = $locale === 'fr';
        $records = collect(exportRecords($export))->keyBy($french ? 'Identifiant' : 'Identifier');
        $created = $case['items']['drill']->created_at->copy()->setTimezone($zone)->format('Y-m-d H:i');

        expect(exportSheet($export)['rows'][0])->toBe([
            $french ? 'Identifiant' : 'Identifier', 'Name', 'Notes', 'Quantity (kg)', 'Price (EUR)', 'Discount (%)', 'In stock', 'Bought', "Seen ({$zone})", 'Opening', 'Condition',
            'Labels', 'Keywords', 'Website', 'Contact', 'Phone', 'Grade', 'Color', 'Barcode', 'Photos', 'Docs',
            ($french ? 'Créé le' : 'Created on')." ({$zone})", ($french ? 'Modifié le' : 'Modified on')." ({$zone})",
        ])
            ->and($records[$case['items']['drill']->ulid]["Seen ({$zone})"])->toBe($summer)
            ->and($records[$other->ulid]["Seen ({$zone})"])->toBe($winter)
            ->and($records[$case['items']['drill']->ulid][($french ? 'Créé le' : 'Created on')." ({$zone})"])->toBe($created)
            // A day and a time of the day have no zone: what is in the cell is what was typed.
            ->and($records[$case['items']['drill']->ulid]['Bought'])->toBe('2026-03-01')
            ->and($records[$case['items']['drill']->ulid]['Opening'])->toBe('07:05');
    })->with([
        'UTC, in English' => ['UTC', 'en', '2026-07-01 10:30', '2026-01-15 10:30'],
        'UTC, in French' => ['UTC', 'fr', '2026-07-01 10:30', '2026-01-15 10:30'],
        'Paris, in English' => ['Europe/Paris', 'en', '2026-07-01 12:30', '2026-01-15 11:30'],
        'Paris, in French' => ['Europe/Paris', 'fr', '2026-07-01 12:30', '2026-01-15 11:30'],
    ]);
});

describe('the JSON', function (): void {
    /**
     * A completed JSON export of the fixture inventory, decoded as objects.
     *
     * @return array{0: Export, 1: array<string, mixed>, 2: stdClass, 3: string}
     */
    function jsonOf(): array
    {
        $case = exportFixture();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Json));

        expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error);

        $raw = exportBytes($export);

        return [$export, $case, json_decode($raw, false, flags: JSON_THROW_ON_ERROR), $raw];
    }

    it('is the document of the inventory: its identity, the definitions of its fields and its items', function (): void {
        [$export, $case, $document] = jsonOf();
        $inventory = $case['inventory'];

        expect($document->format)->toBe('inventaire.cloud/export')
            ->and($document->version)->toBe(1)
            ->and($document->exported_at)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and($document->inventory)->toEqual((object) ['id' => $inventory->ulid, 'name' => 'Workshop', 'description' => null])
            ->and($document->fields)->toHaveCount(20)
            ->and(array_column($document->fields, 'id'))->toBe(array_values(array_map(fn ($field): string => $field->ulid, $case['fields'])))
            ->and($document->items)->toHaveCount(3)
            ->and($export->mime)->toBe('application/json')
            ->and($export->items_count)->toBe(3);
    });

    it('defines every field with its type, its options and whether it is required', function (): void {
        [, $case, $document] = jsonOf();
        $fields = collect($document->fields)->keyBy('name');

        expect($fields['Quantity']->type)->toBe('number')
            ->and($fields['Quantity']->options)->toEqual((object) ['decimals' => 2, 'unit' => 'kg'])
            ->and($fields['Quantity']->required)->toBeFalse()
            ->and($fields['Price']->options)->toEqual((object) ['currency' => 'EUR', 'decimals' => 2])
            ->and($fields['Condition']->options->choices)->toHaveCount(2)
            // A field without options has an object, not a list.
            ->and($fields['Name']->options)->toBeInstanceOf(stdClass::class)
            ->and($fields['Notes']->options)->toEqual((object) []);
    });

    it('gives the items in the shape of the API: values keyed by the ULID of the field, empty ones left out, ISO dates', function (): void {
        [, $case, $document] = jsonOf();
        $drill = collect($document->items)->firstWhere('id', $case['items']['drill']->ulid);
        $fields = $case['fields'];
        $values = (array) $drill->values;

        expect($values[$fields['name']->ulid])->toBe('Drill 18V')
            ->and($values[$fields['quantity']->ulid])->toBe('2.50')
            ->and($values[$fields['price']->ulid])->toBe('1234.50')
            ->and($values[$fields['stock']->ulid])->toBeTrue()
            ->and($values[$fields['seen']->ulid])->toBe('2026-07-01T10:30:00Z')
            ->and($values[$fields['condition']->ulid])->toBe($case['choices']['Used'])
            ->and($values[$fields['labels']->ulid])->toBe([$case['choices']['Heavy'], $case['choices']['Fragile']])
            ->and($values[$fields['grade']->ulid])->toBe(4)
            ->and($values[$fields['color']->ulid])->toBe('#ff8800')
            ->and($drill->created_at)->toBe($case['items']['drill']->created_at->toIso8601String())
            ->and($drill->updated_at)->toBe($case['items']['drill']->updated_at->toIso8601String());

        $empty = collect($document->items)->firstWhere('id', $case['items']['empty']->ulid);

        // No value at all: an object, as the API says it.
        expect($empty->values)->toEqual((object) []);
    });

    it('holds exactly the values the API gives for the items', function (): void {
        [, $case, $document] = jsonOf();
        $api = inventoryCall($this, $case['team']['owner'], 'GET', "/api/v1/inventories/{$case['inventory']->ulid}/items")->assertOk()->json('data');
        $exported = collect($document->items)->keyBy('id');

        expect($api)->toHaveCount(3);

        foreach ($api as $item) {
            expect(json_decode(json_encode($exported[$item['id']]->values), true))->toEqual($item['values'])
                ->and($exported[$item['id']]->created_at)->toBe($item['created_at'])
                ->and($exported[$item['id']]->updated_at)->toBe($item['updated_at']);
        }
    });

    it('is readable text: not escaped, one item to a line', function (): void {
        $case = exportFixture('Matériel / outils');
        $case['inventory']->forceFill(['description' => 'Café "chaud" é€'])->save();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Json));
        $raw = exportBytes($export);

        expect($raw)->toContain('"name":"Matériel / outils"')
            ->and($raw)->toContain('"description":"Café \"chaud\" é€"')
            ->and($raw)->not->toContain('\\'.'u00e9')
            ->and(substr_count($raw, "\n"))->toBeGreaterThan(20)
            ->and($raw)->toEndWith("]\n}\n");
    });

    it('is a valid document for an inventory without items or fields', function (): void {
        $case = exportFixture();
        $bare = inventoryIn($case['team']['workspace'], 'Bare');
        $export = exportRun(exportAsked($bare, $case['team']['owner'], ExportFormat::Json));
        $document = json_decode(exportBytes($export), false, flags: JSON_THROW_ON_ERROR);

        expect($document->fields)->toBe([])->and($document->items)->toBe([])->and($export->items_count)->toBe(0);
    });
});

it('is stored under the folder of its export, on the disk of the export', function (): void {
    [$export] = csvOf();

    expect(Storage::disk(ATTACHMENT_TEST_DISK)->exists((string) $export->path))->toBeTrue()
        ->and($export->path)->toStartWith(exportFolder($export).'/')
        ->and($export->size)->toBe(strlen(exportBytes($export)));
});

describe('a CSV that cannot be completed', function (): void {
    it('is failed, with the first error in the log, and the file is closed and deleted', function (): void {
        $case = exportFixture();
        $seen = 0;
        Event::listen('eloquent.retrieved: '.Item::class, function () use (&$seen): void {
            if (++$seen === 3) {
                throw new RuntimeException('The disk is on fire.');
            }
        });
        Log::spy();
        $scratch = fn (): array => glob(storage_path('framework/exports/*')) ?: [];
        $before = $scratch();

        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv));

        expect($export->status)->toBe(ExportStatus::Failed)
            ->and($export->path)->toBeNull()
            ->and($scratch())->toBe($before);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['exception']->getMessage() === 'The disk is on fire.')->once();
    });
});

describe('the decimal sign', function (): void {
    it('goes with the separator, whatever the language: a comma with the semicolon, a point with the comma or the tab', function (string $delimiter, string $expected): void {
        $case = exportFixture();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv, ['locale' => 'en', 'options' => ['delimiter' => $delimiter]]));
        $drill = collect(exportRecords($export))->firstWhere('Name', 'Drill 18V');

        expect($drill['Quantity (kg)'])->toBe($expected);
    })->with([
        'semicolon' => ['semicolon', '2,50'],
        'comma' => ['comma', '2.50'],
        'tab' => ['tab', '2.50'],
    ]);
});
