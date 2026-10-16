<?php

declare(strict_types=1);

use App\Inventories\Exceptions\InvalidImportValue;
use App\Inventories\Fields\ChoiceType;
use App\Inventories\Fields\DateTimeType;
use App\Inventories\Imports\ImportCell;

require_once __DIR__.'/../../Support/imports.php';

/*
 * What each type of field reads from a cell, in the words of the spec: tolerant of the way people write numbers, dates and yes/no,
 * strict about what it cannot read.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * The day and the moment of a spreadsheet cell, which is a wall clock (the zone of the object is not meaningful).
 */
function importWall(string $text): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone('UTC'));
}

/**
 * A value of a data set: a text starting with `@` stands for the date and time cell that follows.
 */
function importValueOf(mixed $value): mixed
{
    return is_string($value) && str_starts_with($value, '@') ? importWall(substr($value, 1)) : $value;
}

describe('a cell', function (): void {
    it('is empty when it holds nothing or only spaces', function (mixed $value, bool $empty): void {
        expect((new ImportCell($value))->isEmpty())->toBe($empty);
    })->with([[null, true], ['', true], ["  \u{00A0}\t", true], [0, false], [false, false], ['0', false], ['a', false]]);

    it('reads as a text a person recognises', function (mixed $value, string $text): void {
        expect((new ImportCell(importValueOf($value)))->text())->toBe($text);
    })->with([
        'a text, trimmed' => ['  Drill ', 'Drill'],
        'a whole number' => [12, '12'],
        'a whole float' => [12.0, '12'],
        'a float' => [12.5, '12.5'],
        'a small float' => [0.00001, '0.00001'],
        'a day' => ['@2026-03-01', '2026-03-01'],
        'a moment' => ['@2026-03-01 10:30:00', '2026-03-01 10:30'],
        'a time' => [new DateInterval('PT7H5M'), '07:05'],
        'yes' => [true, 'Yes'],
        'nothing' => [null, ''],
    ]);

    it('folds the line breaks of a cell on one line, and cuts a sample', function (): void {
        $cell = new ImportCell("two\r\nlines\tand tab");

        expect($cell->line())->toBe('two lines and tab')
            ->and((new ImportCell(str_repeat('é', 150)))->sample())->toHaveLength(100);
    });
});

describe('a text', function (): void {
    it('is the text on one line', function (string $type, mixed $value, mixed $expected): void {
        expect(importConvert($type, $value))->toBe($expected);
    })->with([
        'trimmed' => ['text', "  Drill\n18V ", 'Drill 18V'],
        'a number' => ['text', 12.0, '12'],
        'a decimal' => ['text', 1234.5, '1234.5'],
        'a day' => ['text', '@2026-03-01', '2026-03-01'],
        'a formula kept as text' => ['text', '=1+1', '=1+1'],
        'nothing' => ['text', null, null],
        'spaces' => ['text', '  ', null],
        'an url' => ['url', ' https://example.com/a ', 'https://example.com/a'],
        'an email' => ['email', "ada@example.com\n", 'ada@example.com'],
        'a phone number as a number' => ['phone', 612345678, '612345678'],
        'a long text keeps its lines' => ['textarea', "one\ntwo", "one\ntwo"],
        'a long text is trimmed' => ['textarea', "  one\r\ntwo  ", "one\r\ntwo"],
    ]);
});

describe('a number', function (): void {
    it('reads the way people write one', function (string $type, array $options, mixed $value, string $decimal, string $expected): void {
        expect(importConvert(importField($type, $options), $value, $decimal))->toBe($expected);
    })->with([
        'a French number' => ['number', ['decimals' => 2], '1 234,56', 'comma', '1234.56'],
        'an English number' => ['number', ['decimals' => 2], '1,234.56', 'point', '1234.56'],
        'both signs, the last is the decimals' => ['number', ['decimals' => 2], '1.234,56', 'point', '1234.56'],
        'no-break spaces' => ['number', ['decimals' => 0], "1\u{00A0}234\u{202F}567", 'comma', '1234567'],
        'a euro' => ['number', ['decimals' => 0], '12 €', 'comma', '12'],
        'a dollar sign in front' => ['number', ['decimals' => 2], '$12.5', 'point', '12.5'],
        'a code before' => ['number', ['decimals' => 2], 'EUR 12,5', 'comma', '12.5'],
        'a code after' => ['number', ['decimals' => 2], '12,5 usd', 'comma', '12.5'],
        'a percent' => ['number', ['decimals' => 1], '12.5 %', 'point', '12.5'],
        'a minus sign of the typographers' => ['number', ['decimals' => 1], "\u{2212}3,5", 'comma', '-3.5'],
        'an accountant negative' => ['number', ['decimals' => 2], '(12.50)', 'point', '-12.5'],
        'a plus sign' => ['number', ['decimals' => 0], '+7', 'point', '7'],
        'a point where commas are the decimals is the decimals' => ['number', ['decimals' => 2], '12.5', 'comma', '12.5'],
        'a point that looks like thousands is thousands' => ['number', ['decimals' => 0], '1.234', 'comma', '1234'],
        'a comma that looks like thousands is thousands' => ['number', ['decimals' => 0], '1,234', 'point', '1234'],
        'the decimal sign of the settings is the decimals' => ['number', ['decimals' => 3], '1,234', 'comma', '1.234'],
        'a leading zero is not thousands' => ['number', ['decimals' => 3], '0.125', 'comma', '0.125'],
        'several thousands' => ['number', ['decimals' => 0], '1.234.567', 'comma', '1234567'],
        'no integer part' => ['number', ['decimals' => 1], ',5', 'comma', '0.5'],
        'a spreadsheet integer' => ['number', ['decimals' => 2], 1234, 'point', '1234'],
        'a spreadsheet float' => ['number', ['decimals' => 2], 12.5, 'point', '12.5'],
        'rounded up' => ['number', ['decimals' => 2], 12.345, 'point', '12.35'],
        'a float with noise' => ['number', ['decimals' => 2], 1234.5600000000001, 'point', '1234.56'],
        'halves go away from zero' => ['number', ['decimals' => 0], '-2.5', 'point', '-3'],
        'a carry' => ['number', ['decimals' => 1], '9.96', 'point', '10'],
        'a zero keeps no sign' => ['number', ['decimals' => 1], '-0.04', 'point', '0'],
        'an amount' => ['money', ['currency' => 'EUR'], '12,999 €', 'comma', '13'],
        'an amount rounded down' => ['money', ['currency' => 'EUR'], '12,994', 'comma', '12.99'],
        'a percentage' => ['percentage', [], '12,5 %', 'comma', '12.5'],
        'a percentage rounded' => ['percentage', ['decimals' => 1], '12.34', 'point', '12.3'],
    ]);

    it('is refused when it is not a number', function (mixed $value): void {
        expect(fn () => importConvert('number', $value))->toThrow(InvalidImportValue::class);
    })->with([
        'a word' => ['abc'],
        'a number with a word' => ['12abc'],
        'two decimal signs' => ['1,2,3'],
        'badly placed thousands' => ['1,23,456.7'],
        'both signs the wrong way' => ['1,2.3,4'],
        'an exponent' => ['1e5'],
        'a yes' => [true],
        'a date' => ['@2026-03-01'],
        'too many digits' => ['1234567890123456'],
        'an unknown currency' => ['12 ABC'],
    ]);

    it('says what it could not read', function (): void {
        expect(fn () => importConvert('number', 'abc'))->toThrow(InvalidImportValue::class, '"abc" is not a number.')
            ->and(fn () => importConvert('number', '1234567890123456'))->toThrow(InvalidImportValue::class, 'has too many digits');
    });
});

describe('a yes or no', function (): void {
    it('reads the words of both languages', function (mixed $value, ?bool $expected): void {
        expect(importConvert('boolean', $value))->toBe($expected);
    })->with([
        [true, true], [false, false], [1, true], [0, false], [1.0, true], [0.0, false],
        ['oui', true], ['Non', false], ['YES', true], ['no', false], ['true', true], ['False', false], ['vrai', true], ['FAUX', false],
        ['o', true], ['n', false], ['y', true], ['x', true], ['X', true], ['✓', true], ['✔', true], ['1', true], ['0', false], [' Oui ', true],
        [null, null], ['', null],
    ]);

    it('reads the words of the language of the request', function (): void {
        $this->useLanguage('fr');

        expect(importConvert('boolean', 'Oui'))->toBeTrue()->and(importConvert('boolean', 'Non'))->toBeFalse();
    });

    it('is refused when it is neither', function (mixed $value): void {
        expect(fn () => importConvert('boolean', $value))->toThrow(InvalidImportValue::class);
    })->with([['maybe'], [2], ['2'], ['@2026-03-01']]);
});

describe('a date', function (): void {
    it('reads the days a person writes', function (mixed $value, string $order, string $expected): void {
        expect(importConvert('date', $value, 'point', $order))->toBe($expected);
    })->with([
        'ISO' => ['2026-03-15', 'dmy', '2026-03-15'],
        'ISO with slashes' => ['2026/03/15', 'mdy', '2026-03-15'],
        'ISO with points' => ['2026.3.5', 'dmy', '2026-03-05'],
        'ISO with a time' => ['2026-03-15 10:30', 'dmy', '2026-03-15'],
        'ISO with a T' => ['2026-03-15T10:30:00Z', 'dmy', '2026-03-15'],
        'day first' => ['15/03/2026', 'dmy', '2026-03-15'],
        'day first, points' => ['15.03.2026', 'dmy', '2026-03-15'],
        'day first, hyphens' => ['5-3-2026', 'dmy', '2026-03-05'],
        'day first, two digits' => ['15/03/26', 'dmy', '2026-03-15'],
        'day first, 1970' => ['01/01/70', 'dmy', '1970-01-01'],
        'day first, 2069' => ['01/01/69', 'dmy', '2069-01-01'],
        'month first' => ['03/15/2026', 'mdy', '2026-03-15'],
        'year first' => ['26-03-15', 'ymd', '2026-03-15'],
        'a date cell' => ['@2026-03-01 00:00:00', 'dmy', '2026-03-01'],
        'a date and time cell' => ['@2026-03-01 23:59:00', 'dmy', '2026-03-01'],
        'no-break spaces' => ["15/03/2026\u{00A0}10:30", 'dmy', '2026-03-15'],
    ]);

    it('is refused when it is not a day', function (mixed $value, string $order): void {
        expect(fn () => importConvert('date', $value, 'point', $order))->toThrow(InvalidImportValue::class);
    })->with([
        'a day that does not exist' => ['31/02/2026', 'dmy'],
        'a month that does not exist' => ['15/13/2026', 'dmy'],
        'month first with a day first date' => ['15/03/2026', 'mdy'],
        'a word' => ['tomorrow', 'dmy'],
        'a month and a year' => ['03/2026', 'dmy'],
        'a year in a number cell' => [2024, 'dmy'],
        'year first needs two digits' => ['03/04/2026', 'ymd'],
        'a year before 1000' => ['15/03/0999', 'dmy'],
        'a yes' => [true, 'dmy'],
    ]);

    it('says what it could not read', function (): void {
        expect(fn () => importConvert('date', 'tomorrow'))->toThrow(InvalidImportValue::class, '"tomorrow" is not a date.');
    });
});

describe('a date and time', function (): void {
    it('reads the moments a person writes, in the zone of the application unless told otherwise', function (mixed $value, string $expected): void {
        expect(importConvert('datetime', $value))->toBe($expected);
    })->with([
        'a summer time' => ['2026-07-01 12:30', '2026-07-01T12:30:00+02:00'],
        'a winter time' => ['2026-01-15 12:30:15', '2026-01-15T12:30:15+01:00'],
        'with a T' => ['2026-07-01T12:30', '2026-07-01T12:30:00+02:00'],
        'with Z' => ['2026-01-01T12:30:00Z', '2026-01-01T12:30:00+00:00'],
        'with an offset' => ['2026-07-01T12:30:00+02:00', '2026-07-01T12:30:00+02:00'],
        'with an offset without a colon' => ['2026-07-01T12:30:00+0530', '2026-07-01T12:30:00+05:30'],
        'with an offset of hours' => ['2026-07-01 12:30 -04', '2026-07-01T12:30:00-04:00'],
        'with fractions of seconds' => ['2026-07-01 12:30:15.250', '2026-07-01T12:30:15+02:00'],
        'day first' => ['15/03/2026 08:05', '2026-03-15T08:05:00+01:00'],
        'a day alone is midnight' => ['2026-03-15', '2026-03-15T00:00:00+01:00'],
        'with an h' => ['15/03/2026 8h05', '2026-03-15T08:05:00+01:00'],
        'a cell' => ['@2026-07-01 12:30:00', '2026-07-01T12:30:00+02:00'],
    ]);

    it('is refused when it is not a moment', function (string $value): void {
        expect(fn () => importConvert('datetime', $value))->toThrow(InvalidImportValue::class);
    })->with([['2026-07-01 25:00'], ['2026-07-01 12:60'], ['31/02/2026 10:00'], ['noon'], ['2026-07-01 12:30 +99:99']]);

    it('reads the hour the clocks show twice as the first of the two, and an hour around the change as it is', function (string $value, string $zone, string $expected): void {
        expect(importConvert('datetime', $value, zone: $zone))->toBe($expected);
    })->with([
        'the clocks of Paris go back: the first 02:30' => ['2026-10-25 02:30', 'Europe/Paris', '2026-10-25T02:30:00+02:00'],
        'the last second of the first hour' => ['2026-10-25 02:59:59', 'Europe/Paris', '2026-10-25T02:59:59+02:00'],
        'an hour before' => ['2026-10-25 01:59', 'Europe/Paris', '2026-10-25T01:59:00+02:00'],
        'an hour after' => ['2026-10-25 03:00', 'Europe/Paris', '2026-10-25T03:00:00+01:00'],
        'the clocks of New York go back: the first 01:30' => ['2026-11-01 01:30', 'America/New_York', '2026-11-01T01:30:00-04:00'],
        'the clocks of Paris go forward, just before' => ['2026-03-29 01:59', 'Europe/Paris', '2026-03-29T01:59:00+01:00'],
        'the clocks of Paris go forward, just after' => ['2026-03-29 03:00', 'Europe/Paris', '2026-03-29T03:00:00+02:00'],
        'a zone with no change' => ['2026-10-25 02:30', 'Asia/Kolkata', '2026-10-25T02:30:00+05:30'],
        'an offset that is given has no hour to skip' => ['2026-03-29 02:30 +01:00', 'Europe/Paris', '2026-03-29T02:30:00+01:00'],
    ]);

    it('refuses a reading in the hour the clocks skip when they go forward, and says so', function (string $value, string $zone): void {
        expect(fn () => importConvert('datetime', $value, zone: $zone))
            ->toThrow(InvalidImportValue::class, 'does not exist: the clocks skip that hour.');
    })->with([
        'Paris' => ['2026-03-29 02:30', 'Europe/Paris'],
        'Paris, the first second' => ['2026-03-29 02:00:00', 'Europe/Paris'],
        'Paris, the last second' => ['29/03/2026 02:59:59', 'Europe/Paris'],
        'a date and time cell' => ['@2026-03-29 02:30:00', 'Europe/Paris'],
        'New York' => ['2026-03-08 02:30', 'America/New_York'],
    ]);

    it('is stored the way the field takes it', function (): void {
        $field = importField('datetime');
        $value = importConvert($field, '2026-07-01 12:30');

        // What the API takes: the field normalizes it to UTC.
        expect(DateTimeType::toUtc($value))->toBe('2026-07-01T10:30:00Z');
    });
});

describe('a time', function (): void {
    it('reads the times a person writes', function (mixed $value, string $expected): void {
        expect(importConvert('time', $value))->toBe($expected);
    })->with([
        'hours and minutes' => ['9:30', '09:30'],
        'with seconds' => ['09:30:45', '09:30'],
        'with an h' => ['9h30', '09:30'],
        'an hour' => ['9h', '09:00'],
        'with spaces' => ['9 h 30', '09:30'],
        'midnight' => ['0:00', '00:00'],
        'the last minute' => ['23:59', '23:59'],
        'half a day' => [0.5, '12:00'],
        'a fraction of a day' => [0.3958333333, '09:30'],
        'a time a float cannot hold exactly' => [425 / 1440, '07:05'],
        'the seconds are cut off, not rounded up' => [(9 * 3600 + 30 * 60 + 50) / 86400, '09:30'],
        'the last second of the day is in the last minute' => [86399 / 86400, '23:59'],
        'a fraction just under a day' => [0.9999999999, '23:59'],
        'midnight' => [0.0, '00:00'],
        'a duration cell' => [new DateInterval('PT7H5M'), '07:05'],
        'a date cell' => ['@1899-12-30 07:05:00', '07:05'],
    ]);

    it('is refused when it is not a time of the day', function (mixed $value): void {
        expect(fn () => importConvert('time', $value))->toThrow(InvalidImportValue::class);
    })->with([['24:00'], ['9:75'], [1.5], [-0.1], ['noon'], [new DateInterval('PT30H')], [true], ['9:30 PM']]);
});

describe('a choice', function (): void {
    it('is found by its label, whatever the case and the accents, or by its id', function (): void {
        $field = importField('select', ['choices' => [['label' => 'Étagère'], ['label' => 'Boîte, grande'], ['label' => 'Bac']]]);
        [$shelf, $box, $tray] = array_column(ChoiceType::choicesOf($field->options), 'id');

        expect(importConvert($field, 'Étagère'))->toBe($shelf)
            ->and(importConvert($field, ' etagere '))->toBe($shelf)
            ->and(importConvert($field, 'BOÎTE, GRANDE'))->toBe($box)
            ->and(importConvert($field, "bac\n"))->toBe($tray)
            ->and(importConvert($field, $tray))->toBe($tray)
            ->and(importConvert($field, strtoupper($tray)))->toBe($tray)
            ->and(importConvert($field, ''))->toBeNull()
            ->and(fn () => importConvert($field, 'Shelf'))->toThrow(InvalidImportValue::class, 'Unknown choice: "Shelf".');
    });

    it('tells two choices apart that differ by an accent only: each is found by its own label first', function (array $labels): void {
        $field = importField('select', ['choices' => array_map(fn (string $label): array => ['label' => $label], $labels)]);
        $ids = array_column(ChoiceType::choicesOf($field->options), 'id', 'label');

        expect(importConvert($field, 'Été'))->toBe($ids['Été'])
            ->and(importConvert($field, 'Ete'))->toBe($ids['Ete'])
            ->and(importConvert($field, 'été'))->toBe($ids['Été'])
            ->and(importConvert($field, 'ETE'))->toBe($ids['Ete'])
            // Neither is written as it is: the accents are not looked at, and the first of the two is taken.
            ->and(importConvert($field, 'Eté'))->toBe($ids[$labels[0]]);
    })->with([
        'the accent first' => [['Été', 'Ete']],
        'the accent last' => [['Ete', 'Été']],
    ]);

    it('is found without its accents when no choice is spelled as it is', function (): void {
        $field = importField('select', ['choices' => [['label' => 'Été'], ['label' => 'Hiver']]]);
        $ids = array_column(ChoiceType::choicesOf($field->options), 'id', 'label');

        expect(importConvert($field, 'ete'))->toBe($ids['Été'])
            ->and(importConvert($field, 'HIVER'))->toBe($ids['Hiver']);
    });

    it('lists several choices for a multiple choice, and one whose label holds a comma', function (): void {
        $field = importField('multiselect', ['choices' => [['label' => 'Fragile'], ['label' => 'Heavy'], ['label' => 'Big, red']]]);
        [$fragile, $heavy, $big] = array_column(ChoiceType::choicesOf($field->options), 'id');

        expect(importConvert($field, 'Fragile, Heavy'))->toBe([$fragile, $heavy])
            ->and(importConvert($field, 'heavy;fragile'))->toBe([$heavy, $fragile])
            ->and(importConvert($field, "Fragile\nHeavy\n"))->toBe([$fragile, $heavy])
            ->and(importConvert($field, 'Big, red'))->toBe([$big])
            ->and(importConvert($field, 'Fragile, fragile, FRAGILE'))->toBe([$fragile])
            ->and(importConvert($field, 'Fragile,, ,'))->toBe([$fragile])
            ->and(importConvert($field, null))->toBeNull()
            ->and(fn () => importConvert($field, 'Fragile, Light'))->toThrow(InvalidImportValue::class, 'Unknown choice: "Light".');
    });

    it('puts a label that holds a comma back together wherever it is in the list, before it fails a piece', function (): void {
        $field = importField('multiselect', ['choices' => [['label' => 'Fragile'], ['label' => 'Big, red'], ['label' => 'Red'], ['label' => 'Red, dark, glossy'], ['label' => 'Heavy; tall']]]);
        [$fragile, $big, $red, $glossy, $tall] = array_column(ChoiceType::choicesOf($field->options), 'id');

        expect(importConvert($field, 'Fragile, Big, red'))->toBe([$fragile, $big])
            ->and(importConvert($field, 'Big, red, Fragile'))->toBe([$big, $fragile])
            ->and(importConvert($field, 'Fragile; big, RED ;fragile'))->toBe([$fragile, $big])
            ->and(importConvert($field, "Fragile\nBig, red\nRed"))->toBe([$fragile, $big, $red])
            // The longest label wins: Red, dark, glossy is one choice and not Red and two pieces that are not choices.
            ->and(importConvert($field, 'Red, dark, glossy, Fragile'))->toBe([$glossy, $fragile])
            ->and(importConvert($field, 'Red, Fragile'))->toBe([$red, $fragile])
            ->and(importConvert($field, 'heavy; tall, fragile'))->toBe([$tall, $fragile])
            // A label cut in two that is not whole is two pieces that must be choices.
            ->and(fn () => importConvert($field, 'Red, dark'))->toThrow(InvalidImportValue::class, 'Unknown choice: "dark".')
            ->and(fn () => importConvert($field, 'Big, Fragile'))->toThrow(InvalidImportValue::class, 'Unknown choice: "Big".');
    });

    it('does not look for labels that hold a comma when none does', function (): void {
        $field = importField('multiselect', ['choices' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C']]]);
        [$a, $b, $c] = array_column(ChoiceType::choicesOf($field->options), 'id');

        expect(importConvert($field, implode(', ', array_fill(0, 500, 'B')).', C, A'))->toBe([$b, $c, $a]);
    });
});

describe('the other types', function (): void {
    it('reads tags as the parts of a list', function (): void {
        expect(importConvert('tags', 'power, cordless; 18V'."\n".'drill'))->toBe(['power', 'cordless', '18V', 'drill'])
            ->and(importConvert('tags', 'single'))->toBe(['single'])
            ->and(importConvert('tags', ' , ; '))->toBe([])
            ->and(importConvert('tags', ''))->toBeNull();
    });

    it('reads a rating as a grade', function (mixed $value, ?int $expected): void {
        expect(importConvert('rating', $value))->toBe($expected);
    })->with([
        'a number' => [4, 4], 'a whole float' => [4.0, 4], 'a text' => ['4', 4], 'a text with a zero' => ['4.0', 4], 'a fraction' => ['3/5', 3],
        'a fraction with spaces' => ['3 / 10', 3], 'stars' => ['★★★☆☆', 3], 'only stars' => ['★★★★★', 5], 'no grade' => [0, null], 'zero' => ['0', null],
        'nothing' => [null, null],
    ]);

    it('refuses what is not a grade', function (mixed $value): void {
        expect(fn () => importConvert('rating', $value))->toThrow(InvalidImportValue::class);
    })->with([['good'], [3.5], ['3,5'], ['☆☆☆'], ['stars: ★★']]);

    it('writes a color as #rrggbb, and leaves the rest to the rules', function (): void {
        expect(importConvert('color', 'ff8800'))->toBe('#ff8800')
            ->and(importConvert('color', '#FF8800'))->toBe('#FF8800')
            ->and(importConvert('color', '#f80'))->toBe('#ff8800')
            ->and(importConvert('color', 'F80'))->toBe('#FF8800')
            ->and(importConvert('color', 'red'))->toBe('red');
    });

    it('gives a barcode back the zeros a spreadsheet took from its number', function (): void {
        expect(importConvert(importField('barcode', ['format' => 'ean13']), 12345678905))->toBe('0012345678905')
            ->and(importConvert(importField('barcode', ['format' => 'ean13']), '0012345678905'))->toBe('0012345678905')
            ->and(importConvert(importField('barcode', ['format' => 'ean8']), 1234567))->toBe('01234567')
            ->and(importConvert(importField('barcode'), 12345))->toBe('12345');
    });

    it('never reads a file or a photo', function (string $type): void {
        expect(fn () => importConvert($type, 'photo.jpg'))->toThrow(InvalidImportValue::class);
    })->with(['file', 'photo']);
});
