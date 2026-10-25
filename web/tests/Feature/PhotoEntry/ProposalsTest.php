<?php

declare(strict_types=1);

use App\Models\Inventory;
use App\Models\InventoryField;
use App\PhotoEntry\Proposals;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/photo-entry.php';

/*
 * What Claude read becomes values the inventory can store, or nothing: every proposal is converted to the shape the API takes for its
 * field, judged by the rules of the field itself, and kept in the canonical form the field stores. What the field refuses is dropped.
 */

uses(RefreshDatabase::class);

/**
 * A raw proposal as the analyzer gives it.
 *
 * @param  string|list<string>  $values
 * @return array{field: string, values: list<string>, confidence: string, evidence: string}
 */
function rawProposal(InventoryField $field, string|array $values, string $confidence = 'high', string $evidence = 'Printed on the label'): array
{
    return ['field' => $field->ulid, 'values' => (array) $values, 'confidence' => $confidence, 'evidence' => $evidence];
}

/**
 * The value that comes out for one field and some strings, or null when the proposal is dropped.
 *
 * @param  string|list<string>  $values
 */
function proposedValue(Inventory $inventory, InventoryField $field, string|array $values): mixed
{
    $out = app(Proposals::class)->normalize($inventory->fields()->get(), [rawProposal($field, $values)]);

    return $out === [] ? null : ['value' => $out[0]['value']];
}

describe('the shape of what comes out', function (): void {
    it('is a list of field, canonical value, confidence and evidence', function (): void {
        $case = photoCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');

        $out = app(Proposals::class)->normalize($case['inventory']->fields()->get(), [rawProposal($name, '  Drill  ', 'medium', "On the\nlabel")]);

        expect($out)->toBe([['field' => $name->ulid, 'value' => 'Drill', 'confidence' => 'medium', 'evidence' => 'On the label']]);
    });

    it('follows the order of the fields of the inventory, not the order of the answer', function (): void {
        $case = photoCase();
        $first = fieldIn($case['inventory'], 'text', 'First');
        $second = fieldIn($case['inventory'], 'text', 'Second');
        $third = fieldIn($case['inventory'], 'text', 'Third');

        $out = app(Proposals::class)->normalize($case['inventory']->fields()->get(), [rawProposal($third, 'c'), rawProposal($first, 'a'), rawProposal($second, 'b')]);

        expect(array_column($out, 'field'))->toBe([$first->ulid, $second->ulid, $third->ulid]);
    });

    it('is empty when nothing holds, and for nothing', function (): void {
        $case = photoCase();
        $number = fieldIn($case['inventory'], 'number', 'Weight');

        expect(app(Proposals::class)->normalize($case['inventory']->fields()->get(), []))->toBe([])
            ->and(app(Proposals::class)->normalize($case['inventory']->fields()->get(), [rawProposal($number, 'heavy')]))->toBe([]);
    });

    it('cuts a long evidence and flattens its line breaks and control characters', function (): void {
        $case = photoCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');

        $out = app(Proposals::class)->normalize($case['inventory']->fields()->get(), [rawProposal($name, 'x', evidence: "a\r\n\tb\u{0007}c ".str_repeat('word ', 80))]);

        expect($out[0]['evidence'])->toStartWith('a b c word')->and(mb_strlen($out[0]['evidence']))->toBe(200)->and($out[0]['evidence'])->toEndWith('…')
            ->and($out[0]['evidence'])->not->toContain("\n");
    });

    it('reads the confidence in any case and falls back to low for a word it does not know', function (): void {
        $case = photoCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $other = fieldIn($case['inventory'], 'text', 'Other');

        $out = app(Proposals::class)->normalize($case['inventory']->fields()->get(), [rawProposal($name, 'x', ' HIGH '), rawProposal($other, 'y', 'certain')]);

        expect(array_column($out, 'confidence'))->toBe(['high', 'low']);
    });
});

describe('the fields it keeps', function (): void {
    it('drops a field that does not exist, one of another inventory, and the file and photo fields', function (): void {
        $case = photoCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $photo = fieldIn($case['inventory'], 'photo', 'Pictures');
        $file = fieldIn($case['inventory'], 'file', 'Manual');
        $elsewhere = fieldIn(inventoryIn($case['team']['workspace'], 'Other'), 'text', 'Name');

        $out = app(Proposals::class)->normalize($case['inventory']->fields()->get(), [
            rawProposal($photo, 'photo.jpg'),
            rawProposal($file, 'manual.pdf'),
            rawProposal($elsewhere, 'Not mine'),
            ['field' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'values' => ['ghost'], 'confidence' => 'high', 'evidence' => ''],
            rawProposal($name, 'Mine'),
        ]);

        expect(array_column($out, 'value'))->toBe(['Mine']);
    });

    it('finds a field whatever the case of its ULID, and gives it back in lowercase', function (): void {
        $case = photoCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');

        $out = app(Proposals::class)->normalize($case['inventory']->fields()->get(), [['field' => strtoupper($name->ulid), 'values' => ['Drill'], 'confidence' => 'high', 'evidence' => '']]);

        expect($out[0]['field'])->toBe($name->ulid);
    });

    it('keeps one proposal per field: the first that holds', function (): void {
        $case = photoCase();
        $weight = fieldIn($case['inventory'], 'number', 'Weight', ['decimals' => 1]);

        $out = app(Proposals::class)->normalize($case['inventory']->fields()->get(), [
            rawProposal($weight, 'unreadable'),
            rawProposal($weight, '2.5', 'medium'),
            rawProposal($weight, '9', 'high'),
        ]);

        expect($out)->toHaveCount(1)->and($out[0]['value'])->toBe('2.5')->and($out[0]['confidence'])->toBe('medium');
    });

    it('drops a proposal with no value, or with values that are not texts', function (): void {
        $case = photoCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');

        $out = app(Proposals::class)->normalize($case['inventory']->fields()->get(), [
            ['field' => $name->ulid, 'values' => [], 'confidence' => 'high', 'evidence' => ''],
        ]);
        $weird = app(Proposals::class)->normalize($case['inventory']->fields()->get(), [
            ['field' => $name->ulid, 'values' => [12, null, ['a'], '   '], 'confidence' => 'high', 'evidence' => ''],
        ]);

        expect($out)->toBe([])->and($weird)->toBe([]);
    });
});

describe('texts', function (): void {
    it('keeps a text, on one line, and drops one the field refuses', function (): void {
        $case = photoCase();
        $short = fieldIn($case['inventory'], 'text', 'Short', ['max_length' => 5]);
        $notes = fieldIn($case['inventory'], 'textarea', 'Notes');

        expect(proposedValue($case['inventory'], $short, "AB\nCD"))->toBe(['value' => 'AB CD'])
            ->and(proposedValue($case['inventory'], $short, 'Too long for it'))->toBeNull()
            ->and(proposedValue($case['inventory'], $notes, "Line one\nLine two"))->toBe(['value' => "Line one\nLine two"]);
    });

    it('uses the first value of a type that holds one', function (): void {
        $case = photoCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');

        expect(proposedValue($case['inventory'], $name, ['First', 'Second']))->toBe(['value' => 'First']);
    });
});

describe('numbers, amounts and percentages', function (): void {
    it('are read from the ways they are written, and kept with the decimals of the field', function (string $written, ?string $expected): void {
        $case = photoCase();
        $weight = fieldIn($case['inventory'], 'number', 'Weight', ['decimals' => 2]);

        expect(proposedValue($case['inventory'], $weight, $written))->toBe($expected === null ? null : ['value' => $expected]);
    })->with([
        'a plain number' => ['12.5', '12.50'],
        'a decimal comma' => ['12,5', '12.50'],
        'a whole number' => ['12', '12.00'],
        'a unit after' => ['2.5 kg', '2.50'],
        'a unit before' => ['kg 2.5', '2.50'],
        'a currency' => ['12,50 €', '12.50'],
        'a percent sign' => ['45 %', '45.00'],
        'a negative' => ['-3.25', '-3.25'],
        'a plus sign' => ['+3', '3.00'],
        'spaces for thousands' => ['1 234,5', '1234.50'],
        'non-breaking spaces' => ["1\u{202F}234,5", '1234.50'],
        'a dot for thousands, a comma for decimals' => ['1.234,56', '1234.56'],
        'a comma for thousands, a dot for decimals' => ['1,234.56', '1234.56'],
        'thousands only' => ['1.234.567', '1234567.00'],
        'zeros after the last decimal' => ['2.500', '2.50'],
        'more decimals than the field keeps' => ['2.505', null],
        'a range' => ['10-12', null],
        'a word' => ['about five', null],
        'an exponent' => ['1e3', null],
        'two numbers' => ['3 and 4', null],
        'a version number' => ['12.5.3', null],
        'a number too large' => ['1000000000000000', null],
    ]);

    it('keep the bounds of the field', function (): void {
        $case = photoCase();
        $bounded = fieldIn($case['inventory'], 'number', 'Bounded', ['decimals' => 0, 'min' => '1', 'max' => '10']);

        expect(proposedValue($case['inventory'], $bounded, '5'))->toBe(['value' => '5'])
            ->and(proposedValue($case['inventory'], $bounded, '0'))->toBeNull()
            ->and(proposedValue($case['inventory'], $bounded, '11'))->toBeNull();
    });

    it('apply to amounts and percentages, with their own decimals', function (): void {
        $case = photoCase();
        $price = fieldIn($case['inventory'], 'money', 'Price', ['currency' => 'EUR']);
        $yen = fieldIn($case['inventory'], 'money', 'Yen', ['currency' => 'JPY']);
        $share = fieldIn($case['inventory'], 'percentage', 'Share', ['decimals' => 1]);

        expect(proposedValue($case['inventory'], $price, '19,9 €'))->toBe(['value' => '19.90'])
            ->and(proposedValue($case['inventory'], $price, '19.999'))->toBeNull()
            ->and(proposedValue($case['inventory'], $yen, '1200'))->toBe(['value' => '1200'])
            ->and(proposedValue($case['inventory'], $yen, '1200.5'))->toBeNull()
            ->and(proposedValue($case['inventory'], $share, '12.5%'))->toBe(['value' => '12.5']);
    });
});

describe('booleans', function (): void {
    it('are read from yes and no in both languages', function (string $written, ?bool $expected): void {
        $case = photoCase();
        $working = fieldIn($case['inventory'], 'boolean', 'Working');

        expect(proposedValue($case['inventory'], $working, $written))->toBe($expected === null ? null : ['value' => $expected]);
    })->with([
        ['true', true], ['TRUE', true], ['yes', true], ['Oui', true], ['vrai', true], [' y ', true],
        ['false', false], ['No', false], ['non', false], ['faux', false], ['0', false],
        ['maybe', null], ['peut-être', null],
    ]);
});

describe('dates and times', function (): void {
    it('read a day written year first, and drop one that does not exist', function (string $written, ?string $expected): void {
        $case = photoCase();
        $bought = fieldIn($case['inventory'], 'date', 'Bought');

        expect(proposedValue($case['inventory'], $bought, $written))->toBe($expected === null ? null : ['value' => $expected]);
    })->with([
        'an ISO date' => ['2026-03-15', '2026-03-15'],
        'slashes' => ['2026/3/5', '2026-03-05'],
        'a time after the day' => ['2026-03-15 10:30', '2026-03-15'],
        'a day that does not exist' => ['2026-02-30', null],
        'day first' => ['15/03/2026', null],
        'a month only' => ['2026-03', null],
        'a word' => ['last spring', null],
    ]);

    it('read a moment in the timezone of the instance, unless it carries its offset, and keep it in UTC', function (string $written, ?string $expected): void {
        config(['app.display_timezone' => 'Europe/Paris']);
        $case = photoCase();
        $seen = fieldIn($case['inventory'], 'datetime', 'Seen');

        expect(proposedValue($case['inventory'], $seen, $written))->toBe($expected === null ? null : ['value' => $expected]);
    })->with([
        'winter in Paris' => ['2026-01-15 14:30', '2026-01-15T13:30:00Z'],
        'summer in Paris' => ['2026-07-15T14:30', '2026-07-15T12:30:00Z'],
        'with seconds' => ['2026-07-15 14:30:45', '2026-07-15T12:30:45Z'],
        'with Z' => ['2026-07-15T14:30:00Z', '2026-07-15T14:30:00Z'],
        'with an offset' => ['2026-07-15T14:30:00+05:30', '2026-07-15T09:00:00Z'],
        'a day only' => ['2026-07-15', null],
        'a day that does not exist' => ['2026-02-30 10:00', null],
        'an hour that does not exist' => ['2026-02-10 25:00', null],
    ]);

    it('read a time of day', function (string $written, ?string $expected): void {
        $case = photoCase();
        $opening = fieldIn($case['inventory'], 'time', 'Opening');

        expect(proposedValue($case['inventory'], $opening, $written))->toBe($expected === null ? null : ['value' => $expected]);
    })->with([
        ['15:30', '15:30'], ['9:05', '09:05'], ['15h30', '15:30'], ['15:30:12', '15:30'],
        ['25:00', null], ['15:61', null], ['noon', null],
    ]);
});

describe('choices', function (): void {
    it('are found by their label whatever the case and the accents, or by their id', function (): void {
        $case = photoCase();
        $condition = fieldIn($case['inventory'], 'select', 'Condition', ['choices' => [['label' => 'Neuf'], ['label' => 'Occasion, très bon état'], ['label' => 'Bac']]]);
        $choices = collect($condition->options['choices'])->pluck('id', 'label');

        expect(proposedValue($case['inventory'], $condition, 'neuf'))->toBe(['value' => $choices['Neuf']])
            ->and(proposedValue($case['inventory'], $condition, 'NEUF'))->toBe(['value' => $choices['Neuf']])
            ->and(proposedValue($case['inventory'], $condition, 'Occasion, tres bon etat'))->toBe(['value' => $choices['Occasion, très bon état']])
            ->and(proposedValue($case['inventory'], $condition, $choices['Bac']))->toBe(['value' => $choices['Bac']])
            ->and(proposedValue($case['inventory'], $condition, 'Cassé'))->toBeNull()
            ->and(proposedValue($case['inventory'], $condition, ['Cassé', 'Bac']))->toBeNull();
    });

    it('take every value for a multiple choice, once each, and leave out the ones that match nothing', function (): void {
        $case = photoCase();
        $extras = fieldIn($case['inventory'], 'multiselect', 'Extras', ['choices' => [['label' => 'Case'], ['label' => 'Charger'], ['label' => 'Étui']]]);
        $ids = collect($extras->options['choices'])->pluck('id', 'label');

        expect(proposedValue($case['inventory'], $extras, ['charger', 'CASE', 'Charger', 'Spare battery', 'etui']))
            ->toBe(['value' => [$ids['Charger'], $ids['Case'], $ids['Étui']]])
            ->and(proposedValue($case['inventory'], $extras, ['Spare battery']))->toBeNull();
    });
});

describe('tags', function (): void {
    it('take every value, once each, and leave out the ones that are too long', function (): void {
        $case = photoCase();
        $tags = fieldIn($case['inventory'], 'tags', 'Tags');

        expect(proposedValue($case['inventory'], $tags, ['red', 'Red', ' metal ', str_repeat('x', 51), 'big']))->toBe(['value' => ['red', 'metal', 'big']])
            ->and(proposedValue($case['inventory'], $tags, [str_repeat('x', 51)]))->toBeNull()
            ->and(count(proposedValue($case['inventory'], $tags, array_map(fn (int $number): string => "tag{$number}", range(1, 40)))['value']))->toBe(30);
    });
});

describe('addresses, e-mail addresses and phone numbers', function (): void {
    it('are kept when the field accepts them', function (): void {
        $case = photoCase();
        $page = fieldIn($case['inventory'], 'url', 'Page');
        $mail = fieldIn($case['inventory'], 'email', 'Mail');
        $phone = fieldIn($case['inventory'], 'phone', 'Phone');

        expect(proposedValue($case['inventory'], $page, 'https://example.com/a?b=1'))->toBe(['value' => 'https://example.com/a?b=1'])
            ->and(proposedValue($case['inventory'], $page, 'www.example.com/page'))->toBe(['value' => 'https://www.example.com/page'])
            ->and(proposedValue($case['inventory'], $page, 'ftp://example.com'))->toBeNull()
            ->and(proposedValue($case['inventory'], $page, 'see the box'))->toBeNull()
            ->and(proposedValue($case['inventory'], $mail, 'mailto:Sales@Example.com'))->toBe(['value' => 'Sales@Example.com'])
            ->and(proposedValue($case['inventory'], $mail, 'sales at example'))->toBeNull()
            ->and(proposedValue($case['inventory'], $phone, 'tel:+33 1 23 45 67 89'))->toBe(['value' => '+33 1 23 45 67 89'])
            ->and(proposedValue($case['inventory'], $phone, 'call us'))->toBeNull();
    });
});

describe('ratings and colors', function (): void {
    it('read a grade as a whole number within the scale of the field', function (): void {
        $case = photoCase();
        $grade = fieldIn($case['inventory'], 'rating', 'Grade', ['max' => 5]);

        expect(proposedValue($case['inventory'], $grade, '4'))->toBe(['value' => 4])
            ->and(proposedValue($case['inventory'], $grade, '4/5'))->toBe(['value' => 4])
            ->and(proposedValue($case['inventory'], $grade, '6'))->toBeNull()
            ->and(proposedValue($case['inventory'], $grade, '0'))->toBeNull()
            ->and(proposedValue($case['inventory'], $grade, '4.5'))->toBeNull()
            ->and(proposedValue($case['inventory'], $grade, 'four stars'))->toBeNull();
    });

    it('read a color as a hexadecimal code, in lowercase', function (): void {
        $case = photoCase();
        $color = fieldIn($case['inventory'], 'color', 'Color');

        expect(proposedValue($case['inventory'], $color, '#FF8800'))->toBe(['value' => '#ff8800'])
            ->and(proposedValue($case['inventory'], $color, 'ff8800'))->toBe(['value' => '#ff8800'])
            ->and(proposedValue($case['inventory'], $color, '#f80'))->toBe(['value' => '#ff8800'])
            ->and(proposedValue($case['inventory'], $color, 'orange'))->toBeNull()
            ->and(proposedValue($case['inventory'], $color, '#ff88'))->toBeNull();
    });
});

describe('barcodes', function (): void {
    it('lose their spaces and are dropped when the check digit is wrong: a misread digit never gets through', function (): void {
        $case = photoCase();
        $ean = fieldIn($case['inventory'], 'barcode', 'EAN', ['format' => 'ean13']);
        $isbn = fieldIn($case['inventory'], 'barcode', 'ISBN', ['format' => 'isbn13']);
        $any = fieldIn($case['inventory'], 'barcode', 'Any');

        expect(proposedValue($case['inventory'], $ean, '4006381333931'))->toBe(['value' => '4006381333931'])
            ->and(proposedValue($case['inventory'], $ean, '4 006381 333931'))->toBe(['value' => '4006381333931'])
            ->and(proposedValue($case['inventory'], $ean, '4006381333932'))->toBeNull()
            ->and(proposedValue($case['inventory'], $isbn, '978-3-16-148410-0'))->toBe(['value' => '9783161484100'])
            ->and(proposedValue($case['inventory'], $isbn, '978-3-16-148410-1'))->toBeNull()
            ->and(proposedValue($case['inventory'], $any, 'AB-12.3'))->toBe(['value' => 'AB-12.3'])
            ->and(proposedValue($case['inventory'], $any, 'AB 12/3'))->toBeNull();
    });
});

describe('a required field', function (): void {
    it('is proposed like any other', function (): void {
        $case = photoCase();
        $name = fieldIn($case['inventory'], 'text', 'Name', required: true);

        expect(proposedValue($case['inventory'], $name, 'Drill'))->toBe(['value' => 'Drill']);
    });
});
