<?php

declare(strict_types=1);

use App\Inventories\Exceptions\ImportFileException;
use App\Inventories\Imports\ImportAnalyzer;
use App\Inventories\Imports\ImportFormat;
use App\Inventories\Imports\ImportRows;
use App\Models\InventoryField;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/imports.php';

/*
 * What reading the beginning of a file tells: the headers, the samples, the type each column looks like, and where each column is
 * proposed to go.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * The type guessed for a column of these values, the first value being on the second row under a header.
 *
 * @param  list<string>  $values
 */
function importGuess(array $values, string $locale = 'en'): string
{
    $rows = array_map(fn (string $value): array => [$value], $values);
    $analysis = importAnalyze(importFile(importCsv([['Column'], ...$rows])), locale: $locale);

    return $analysis->columns[0]['guess'];
}

describe('how far into the file it looks', function (): void {
    it('looks at the first thousand lines for the rows, the empty ones counted', function (): void {
        $blank = fn (int $lines): string => str_repeat("\r\n", $lines);
        $samples = fn (int $lines): array => array_column(importAnalyze(importFile($blank($lines)."Name\r\nDrill\r\n"), options: ['has_header' => false])->columns, 'samples');

        // The two rows are the 999th and the 1 000th line.
        expect($samples(998))->toBe([['Name', 'Drill']])
            // The second is the 1 001st: not seen.
            ->and($samples(999))->toBe([['Name']]);

        // A file with nothing in its first thousand lines holds no row, as far as the analysis can tell.
        expect(fn () => importAnalyze(importFile($blank(1000)."Name\r\nDrill\r\n")))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::EMPTY));
    });

    it('does not read the rest of a spreadsheet whose rows hold nothing, though it has more than a sheet has lines', function (): void {
        // The library takes time to build every row, whatever it holds: a million of them, each with cells in it, is not read to the end.
        $path = importOdsAfterRow(importSpreadsheet('ods', [['Name'], ['Drill']]), 2, '<table:table-row table:number-rows-repeated="'.ImportRows::MAX_LINES.'"><table:table-cell/></table:table-row>');

        expect(array_column(importAnalyze($path, ImportFormat::Ods)->columns, 'header'))->toBe(['Name']);
    });
});

describe('the type of a column', function (): void {
    it('is guessed from its values', function (array $values, string $expected): void {
        expect(importGuess($values))->toBe($expected);
    })->with([
        'yes and no' => [['oui', 'non', 'Yes', 'NO', 'true', 'faux'], 'boolean'],
        'ones and zeros are numbers' => [['1', '0', '1'], 'number'],
        'a single x is a text' => [['x', 'x'], 'text'],
        'days' => [['2026-03-15', '2026-04-01'], 'date'],
        'days written day first' => [['15/03/2026', '01/04/2026'], 'date'],
        'moments' => [['2026-03-15 10:30', '2026-04-01T08:00:00Z'], 'datetime'],
        'moments in the local way' => [['15/03/2026 10:30', '01/04/2026'], 'datetime'],
        'times' => [['9:30', '14:00', '9h15'], 'time'],
        'numbers' => [['12', '3,5', '-4', '1 234,56'], 'number'],
        'numbers with points' => [['12', '3.5', '1,234.56'], 'number'],
        'amounts' => [['12 €', '3,50 €', '$9'], 'money'],
        'amounts with a code' => [['12 EUR', 'USD 3'], 'money'],
        'percentages' => [['12 %', '5%', '7,5 %'], 'percentage'],
        'a number without a unit among amounts' => [['12 €', '3'], 'number'],
        'zeros in front are a code' => [['007', '042'], 'text'],
        'a phone number' => [['0612345678', '0698765432'], 'text'],
        'a plus sign is a phone number' => [['+33612345678'], 'text'],
        'a barcode' => [['3017620422003', '4006381333931'], 'text'],
        'a long number that is not a barcode yet' => [['12345678901'], 'number'],
        'email addresses' => [['ada@example.com', 'bob@example.org'], 'email'],
        'addresses of pages' => [['https://example.com', 'http://example.org/a?b=1'], 'url'],
        'a text with a line break' => [["one\ntwo", 'three'], 'textarea'],
        'a long text' => [[str_repeat('word ', 60)], 'textarea'],
        'a text' => [['Drill', 'Saw'], 'text'],
        'a mix' => [['12', 'abc'], 'text'],
        'nothing' => [['', ''], 'text'],
        'a day that does not exist' => [['31/02/2026'], 'text'],
    ]);

    it('is guessed from the cells of a spreadsheet', function (string $format): void {
        $path = importSpreadsheet($format, [
            ['Text', 'Int', 'Float', 'Yes', 'Day', 'Moment', 'Time', 'Code'],
            ['Drill', 3, 12.5, true, new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-01 10:30'), new DateInterval('PT7H5M'), 3017620422003],
            ['Saw', 0, -0.25, false, new DateTimeImmutable('2026-03-02'), new DateTimeImmutable('2026-03-02 11:00'), new DateInterval('PT9H'), 4006381333931],
        ]);

        expect(array_column(importAnalyze($path, ImportFormat::from($format))->columns, 'guess'))->toBe(['text', 'number', 'number', 'boolean', 'date', 'datetime', 'time', 'text']);
    })->with(['xlsx', 'ods']);

    it('reads only the first fifty rows', function (): void {
        $rows = array_fill(0, 50, ['12']);
        $rows[] = ['abc'];

        expect(importAnalyze(importFile(importCsv([['Column'], ...$rows])))->columns[0]['guess'])->toBe('number');
    });
});

describe('the columns', function (): void {
    it('have the header of the first row, three samples, and a letter when they have no header', function (): void {
        $csv = importFile(importCsv([['Name', '', str_repeat('é', 150)], ['a', 'b', 'c'], ['d', '', 'f'], ['g', 'h'], ['i', 'j', 'k']]));

        $columns = importAnalyze($csv)->columns;

        expect(array_column($columns, 'header'))->toBe(['Name', 'Column B', str_repeat('é', 120)])
            ->and($columns[0]['samples'])->toBe(['a', 'd', 'g'])
            ->and($columns[1]['samples'])->toBe(['b', 'h', 'j'])
            ->and(array_column($columns, 'index'))->toBe([0, 1, 2]);
    });

    it('are named by the letters of a spreadsheet when the first row is not a header', function (): void {
        $csv = importFile(importCsv([array_map(fn (int $number): string => (string) $number, range(1, 28)), ['a']]));

        $columns = importAnalyze($csv, options: ['has_header' => false])->columns;

        expect($columns[0]['header'])->toBe('Column A')
            ->and($columns[25]['header'])->toBe('Column Z')
            ->and($columns[26]['header'])->toBe('Column AA')
            ->and($columns[27]['header'])->toBe('Column AB')
            ->and($columns[0]['samples'])->toBe(['1', 'a'])
            ->and(ImportAnalyzer::letter(701))->toBe('ZZ')
            ->and(ImportAnalyzer::letter(702))->toBe('AAA');
    });

    it('are as many as the widest of the rows read', function (): void {
        $csv = importFile(importCsv([['a', 'b'], ['1', '2', '3', '4'], ['5']]));

        expect(importAnalyze($csv)->columns)->toHaveCount(4);
    });

    it('show a header on one line, with the line breaks of a spreadsheet cell turned into spaces', function (): void {
        $csv = importFile(importCsv([["Quantity\n(kg)"], ['1']]));

        expect(importAnalyze($csv)->columns[0]['header'])->toBe('Quantity (kg)');
    });

    it('may have only a header, or no header at all in a file of one row', function (): void {
        expect(importAnalyze(importFile(importCsv([['a', 'b']])))->columns)->toHaveCount(2)
            ->and(importAnalyze(importFile(importCsv([['a', 'b']])), options: ['has_header' => false])->columns[0]['samples'])->toBe(['a']);
    });

    it('take the first row of the file for the header, and no other: an empty first row gives the names by letters', function (): void {
        $csv = importFile("\r\nName,Qty\r\nDrill,3\r\n");

        $columns = importAnalyze($csv, options: ['has_header' => true])->columns;

        expect(array_column($columns, 'header'))->toBe(['Column A', 'Column B'])
            ->and($columns[0]['samples'])->toBe(['Name', 'Drill'])
            ->and($columns[1]['samples'])->toBe(['Qty', '3']);
    });

    it('are refused when the file holds no row', function (): void {
        expect(fn () => importAnalyze(importSpreadsheet('xlsx', [[null]]), ImportFormat::Xlsx))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::EMPTY));
    });
});

describe('the decimal sign and the order of the dates', function (): void {
    it('are found in the values when they show them', function (array $values, string $key, string $expected): void {
        $analysis = importAnalyze(importFile(importCsv([['Column'], ...array_map(fn (string $value): array => [$value], $values)])));

        expect($analysis->options->toArray()[$key])->toBe($expected);
    })->with([
        'a comma' => [['12,5', '3'], 'decimal', 'comma'],
        'a point' => [['12.5', '3'], 'decimal', 'point'],
        'both, the point last' => [['1,234.56'], 'decimal', 'point'],
        'both, the comma last' => [['1.234,56'], 'decimal', 'comma'],
        'amounts' => [['12,50 €', '9 €'], 'decimal', 'comma'],
        'thousands tell nothing: the default of an English reader' => [['1,234', '2,345'], 'decimal', 'point'],
        'dates tell nothing' => [['15.03.2026', '01.04.2026'], 'decimal', 'point'],
        'the majority wins' => [['1,5', '2,5', '3.5'], 'decimal', 'comma'],
        'a day above twelve first' => [['15/03/2026', '01/02/2026'], 'date_order', 'dmy'],
        'a day above twelve second' => [['03/15/2026', '01/02/2026'], 'date_order', 'mdy'],
        'a day above twelve with points' => [['15.03.26'], 'date_order', 'dmy'],
        'nothing conclusive: the default of an English reader' => [['01/02/2026'], 'date_order', 'mdy'],
        'both orders: the default' => [['15/03/2026', '03/15/2026'], 'date_order', 'mdy'],
    ]);

    it('follow the language of the reader when the values say nothing', function (): void {
        $csv = importFile(importCsv([['Column'], ['01/02/2026'], ['1,234']]));

        expect(importAnalyze($csv, locale: 'fr')->options->toArray())->toMatchArray(['decimal' => 'comma', 'date_order' => 'dmy'])
            ->and(importAnalyze($csv, locale: 'en')->options->toArray())->toMatchArray(['decimal' => 'point', 'date_order' => 'mdy']);
    });

    it('are left as the person chose them', function (): void {
        $csv = importFile(importCsv([['Column'], ['12,5'], ['15/03/2026']]));

        $analysis = importAnalyze($csv, options: ['decimal' => 'point', 'date_order' => 'mdy'], detect: []);

        expect($analysis->options->toArray())->toMatchArray(['decimal' => 'point', 'date_order' => 'mdy']);
    });

    it('make the guess of the type: a day first date is a date', function (): void {
        $csv = importFile(importCsv([['Bought', 'Price'], ['15/03/2026', '12,5'], ['16/03/2026', '9,75']]));

        expect(array_column(importAnalyze($csv)->columns, 'guess'))->toBe(['date', 'number']);
    });
});

describe('a column that looks like the fractions a spreadsheet shows as percentages', function (): void {
    it('is told by its numbers: all between -1 and 1, and not all whole', function (array $values, bool $expected): void {
        foreach (['xlsx', 'ods'] as $format) {
            $path = importSpreadsheet($format, [['Rate'], ...array_map(fn (mixed $value): array => [$value], $values)]);
            $column = importAnalyze($path, ImportFormat::from($format))->columns[0];

            expect($column['fraction_like'])->toBe($expected, "{$format} {$column['guess']}");
        }
    })->with([
        'the cell of a percentage: 12.5 % is 0.125' => [[0.125, 0.5, 0.75], true],
        'whole numbers can be in it' => [[0.5, 1, 0], true],
        'negative fractions' => [[-0.25, 0.5, -1], true],
        'a single fraction' => [[0.2], true],
        'empty cells are not values' => [[0.25, null, 0.5], true],
        'whole numbers only: flags, not fractions' => [[0, 1, 0, 1], false],
        'a value beyond one' => [[0.5, 1.5], false],
        'a value below minus one' => [[0.5, -1.25], false],
        'percentages written as plain numbers' => [[12.5, 40, 3.25], false],
        'no value at all' => [[null, null], false],
        'a text among the numbers' => [[0.25, 'n/a'], false],
        'a date' => [[new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC'))], false],
        'yes and no' => [[true, false], false],
    ]);

    it('is told for a text file too, but not for a value written with a percent sign or a currency', function (array $values, bool $expected): void {
        // A second column, so that the semicolon is the separator and the commas are the decimals.
        $csv = importFile(importCsv([['Rate', 'Other'], ...array_map(fn (string $value): array => [$value, 'x'], $values)], ';'));

        expect(importAnalyze($csv, locale: 'fr')->columns[0]['fraction_like'])->toBe($expected);
    })->with([
        'a comma for decimals' => [['0,125', '0,5'], true],
        'a point for decimals, read with commas' => [['0.5', '0,25'], true],
        'with a percent sign: the person said percent' => [['0,5 %', '0,25 %'], false],
        'with a currency' => [['0,5 €', '0,25 €'], false],
        'not a number' => [['0,5', 'half'], false],
        'plain percentages' => [['12,5', '40'], false],
    ]);

    it('does not change the guess of the type', function (): void {
        $path = importSpreadsheet('xlsx', [['Rate'], [0.125], [0.5]]);

        expect(importAnalyze($path, ImportFormat::Xlsx)->columns[0])->toMatchArray(['guess' => 'number', 'fraction_like' => true]);
    });
});

describe('the proposal', function (): void {
    /**
     * An inventory with fields of several types, as the proposal sees them.
     *
     * @return list<InventoryField>
     */
    function importProposalFields(): array
    {
        $case = importCase();

        return [
            fieldIn($case['inventory'], 'text', 'Name'),
            fieldIn($case['inventory'], 'number', 'Quantity'),
            fieldIn($case['inventory'], 'text', 'Prénom'),
            fieldIn($case['inventory'], 'photo', 'Photos'),
            fieldIn($case['inventory'], 'text', 'Identifier'),
        ];
    }

    it('sends a column to the field that has its name, whatever the case, the accents and the unit in brackets', function (): void {
        $fields = importProposalFields();
        $csv = importFile(importCsv([['NAME', 'quantity (kg)', 'Prenom'], ['a', '1', 'b']]));

        $mapping = importAnalyze($csv, fields: $fields)->mapping->toArray();

        expect(array_column($mapping, 'field'))->toBe([$fields[0]->ulid, $fields[1]->ulid, $fields[2]->ulid])
            ->and(array_column($mapping, 'target'))->toBe(['field', 'field', 'field']);
    });

    it('sends only one column to a field, and makes a new field of the next one that has its name', function (): void {
        $fields = importProposalFields();
        $csv = importFile(importCsv([['Name', 'Name', 'name'], ['a', 'b', 'c']]));

        $mapping = importAnalyze($csv, fields: $fields)->mapping->toArray();

        expect(array_column($mapping, 'target'))->toBe(['field', 'new', 'new'])
            ->and(array_column($mapping, 'name'))->toBe([null, 'Name 2', 'name 3']);
    });

    it('never sends a column to a field filled by files', function (): void {
        $fields = importProposalFields();
        $csv = importFile(importCsv([['Photos'], ['a']]));

        $mapping = importAnalyze($csv, fields: $fields)->mapping->toArray();

        // A new field cannot take the name of a field the inventory has, the one that cannot be filled included.
        expect($mapping[0])->toMatchArray(['target' => 'new', 'type' => 'text', 'name' => 'Photos 2']);
    });

    it('ignores the columns an export adds', function (string $header): void {
        $csv = importFile(importCsv([[$header, 'Other'], ['a', 'b']]));

        expect(importAnalyze($csv, locale: 'fr')->mapping->toArray()[0]['target'])->toBe('ignore');
    })->with([
        'Identifier', 'identifiant', 'Created on', 'Créé le', 'CREE LE', 'Modified on', 'Modifié le',
        // An export names the zone of its moments in the header of the column.
        'Created on (Europe/Paris)',
    ]);

    it('prefers a field to the columns of an export', function (): void {
        $fields = importProposalFields();
        $csv = importFile(importCsv([['Identifier', 'Identifier'], ['a', 'b']]));

        expect(array_column(importAnalyze($csv, fields: $fields)->mapping->toArray(), 'target'))->toBe(['field', 'ignore']);
    });

    it('makes new fields of the others, with the type guessed and the header as the name', function (): void {
        $csv = importFile(importCsv([['Bought', 'Price (EUR)', 'In stock'], ['2026-03-01', '12,5 €', 'oui']]));

        $mapping = importAnalyze($csv)->mapping->toArray();

        expect(array_column($mapping, 'type'))->toBe(['date', 'money', 'boolean'])
            ->and(array_column($mapping, 'name'))->toBe(['Bought', 'Price (EUR)', 'In stock']);
    });

    it('names the new field of a column of moments without the zone its header gives, and keeps any other brackets', function (): void {
        $csv = importFile(importCsv([
            ['Seen (Europe/Paris)', 'Delivered (UTC)', 'Planned (estimate)', 'Remark (UTC)'],
            ['2026-07-01 12:30', '2026-07-02 08:00', '2026-07-03 09:00', 'a (b)'],
        ]));

        $mapping = importAnalyze($csv)->mapping->toArray();

        // The last column holds texts, not moments: its brackets are part of its name, whatever they say.
        expect(array_column($mapping, 'type'))->toBe(['datetime', 'datetime', 'datetime', 'text'])
            ->and(array_column($mapping, 'name'))->toBe(['Seen', 'Delivered', 'Planned (estimate)', 'Remark (UTC)']);
    });

    it('leaves the zone out of the name of the new field of a moment column, an alias of the database being a zone too', function (string $zone): void {
        $csv = importFile(importCsv([["Seen ({$zone})"], ['2026-07-01 12:30']]));

        expect(importAnalyze($csv)->mapping->toArray()[0])->toMatchArray(['type' => 'datetime', 'name' => 'Seen']);
    })->with(['Asia/Calcutta', 'US/Eastern']);

    it('keeps the brackets of a header that only looks like a zone, whatever the database calls it', function (string $remark): void {
        $csv = importFile(importCsv([["Delivery ({$remark})"], ['2026-07-01 12:30']]));

        expect(importAnalyze($csv)->mapping->toArray()[0])->toMatchArray(['type' => 'datetime', 'name' => "Delivery ({$remark})"]);
    })->with([
        // Aliases of the database without a region are countries or remarks in a header, not what an export writes.
        'Poland', 'Japan', 'GB', 'NZ', 'CET',
        // Any text with a slash is not a zone: a unit, a ratio.
        'EUR/kg', 'Europe/Nowhere',
    ]);

    it('leaves the zone of the instance out of the name of the new field of a moment column, however the database names it', function (string $zone): void {
        config(['app.display_timezone' => $zone]);
        $csv = importFile(importCsv([["Seen ({$zone})"], ['2026-07-01 12:30']]));

        expect(importAnalyze($csv)->mapping->toArray()[0])->toMatchArray(['type' => 'datetime', 'name' => 'Seen']);
    })->with(['Poland', 'Europe/Paris', 'UTC', 'Mars/Olympus']);

    it('leaves the zone out of the name of the new field of a column that reads as days', function (): void {
        // The moments of a spreadsheet that all fall at midnight read as days.
        $path = importSpreadsheet('xlsx', [['Seen (Europe/Paris)'], [new DateTimeImmutable('2026-07-01')], [new DateTimeImmutable('2026-07-02')]]);

        expect(importAnalyze($path, ImportFormat::Xlsx)->mapping->toArray()[0])->toMatchArray(['type' => 'date', 'name' => 'Seen']);
    });

    it('ignores the others when the person may not create fields', function (): void {
        $fields = importProposalFields();
        $csv = importFile(importCsv([['Name', 'Bought'], ['a', '2026-03-01']]));

        $mapping = importAnalyze($csv, fields: $fields, canManageFields: false)->mapping->toArray();

        expect(array_column($mapping, 'target'))->toBe(['field', 'ignore']);
    });

    it('has an entry for every column, with all the keys', function (): void {
        $csv = importFile(importCsv([['a', 'b'], ['one', '2']]));

        expect(importAnalyze($csv)->mapping->toArray())->toBe([
            ['column' => 0, 'target' => 'new', 'field' => null, 'type' => 'text', 'name' => 'a'],
            ['column' => 1, 'target' => 'new', 'field' => null, 'type' => 'number', 'name' => 'b'],
        ]);
    });

    it('gives a new field a name that is not too long and that no field has', function (): void {
        $case = importCase();
        $long = fieldIn($case['inventory'], 'text', str_repeat('x', 120));
        $csv = importFile(importCsv([[str_repeat('x', 150), 'y'], ['a', 'b']]));

        $mapping = importAnalyze($csv, fields: [$long, fieldIn($case['inventory'], 'text', 'Other')])->mapping->toArray();

        // The header is cut to 120 characters, which is the name of a field the inventory has: the field is used, and a second
        // column with such a header gets a name of its own that still fits.
        expect($mapping[0]['target'])->toBe('field');

        $twice = importFile(importCsv([[str_repeat('x', 150), str_repeat('x', 150)], ['a', 'b']]));
        $mapping = importAnalyze($twice, fields: [$long])->mapping->toArray();

        expect($mapping[1]['target'])->toBe('new')->and($mapping[1]['name'])->toBe(str_repeat('x', 118).' 2')
            ->and(mb_strlen((string) $mapping[1]['name']))->toBeLessThanOrEqual(120);
    });
});
