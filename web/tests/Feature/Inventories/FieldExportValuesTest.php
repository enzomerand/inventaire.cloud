<?php

declare(strict_types=1);

use App\Inventories\Fields\ChoiceType;
use App\Inventories\Fields\ExportKind;
use App\Inventories\Fields\ExportValue;
use App\Inventories\Fields\FieldTypes;
use App\Models\InventoryField;

/*
 * What every field type gives an export file for a stored value (App\Inventories\Fields\ExportValue): its genre, its raw value and
 * what a format needs to write it. In memory, no database: the field is built the way the types normalize their options.
 */

/**
 * A field of a type, in memory, with the options a person would send (normalized by the type, defaults included).
 *
 * @param  array<string, mixed>  $options
 */
function exportFieldOf(string $type, array $options = []): InventoryField
{
    return new InventoryField([
        'name' => 'Field',
        'type' => $type,
        'options' => app(FieldTypes::class)->get($type)->normalizeOptions($options, null),
    ]);
}

/**
 * The export value a type gives for a stored value.
 *
 * @param  array<string, mixed>  $options
 * @param  array<string, string>  $fileNames
 */
function exportedValue(string $type, mixed $stored, array $options = [], array $fileNames = []): ExportValue
{
    return app(FieldTypes::class)->get($type)->exportValue($stored, exportFieldOf($type, $options), $fileNames);
}

/**
 * The unit shown in the header of the column of a field.
 *
 * @param  array<string, mixed>  $options
 */
function exportedUnit(string $type, array $options = []): ?string
{
    return app(FieldTypes::class)->get($type)->exportUnit(exportFieldOf($type, $options));
}

beforeEach(function (): void {
    config(['app.display_timezone' => 'Europe/Paris']);
});

describe('the text types', function (): void {
    it('keeps the text as it was stored', function (string $type, string $stored): void {
        $value = exportedValue($type, $stored);

        expect($value->kind)->toBe(ExportKind::Text)
            ->and($value->string())->toBe($stored)
            ->and($value->isEmpty())->toBeFalse();
    })->with([
        'text' => ['text', 'Drill 18V'],
        'textarea with line breaks' => ['textarea', "Two lines\nof text"],
        'url' => ['url', 'https://example.com/a?b=1'],
        'email' => ['email', 'ada@example.com'],
        'phone' => ['phone', '+33612345678'],
        'color' => ['color', '#ff8800'],
        'barcode keeps its leading zeros' => ['barcode', '0012345678905'],
        'a text that looks like a formula' => ['text', '=SUM(A1:A2)'],
    ]);

    it('is nothing for a missing or empty value, or a value of another shape', function (string $type, mixed $stored): void {
        expect(exportedValue($type, $stored)->isEmpty())->toBeTrue()
            ->and(exportedValue($type, $stored)->kind)->toBe(ExportKind::Empty);
    })->with([
        'text null' => ['text', null],
        'text empty' => ['text', ''],
        'text number' => ['text', 12],
        'barcode list' => ['barcode', ['1']],
        'color null' => ['color', null],
    ]);

    it('has no unit', function (): void {
        expect(exportedUnit('text'))->toBeNull()->and(exportedUnit('barcode'))->toBeNull()->and(exportedUnit('date'))->toBeNull();
    });
});

describe('the numbers', function (): void {
    it('gives a number with the decimals of the field', function (): void {
        $value = exportedValue('number', '12.50', ['decimals' => 2]);

        expect($value->kind)->toBe(ExportKind::Number)
            ->and($value->string())->toBe('12.5')
            ->and($value->decimals)->toBe(2)
            ->and($value->numberText())->toBe('12.50')
            ->and($value->currency)->toBeNull()
            ->and($value->unit)->toBeNull();
    });

    it('keeps a value stored with more decimals than the field has now', function (): void {
        $value = exportedValue('number', '1.2345', ['decimals' => 2]);

        expect($value->numberText())->toBe('1.2345')->and($value->decimals)->toBe(2);
    });

    it('keeps the sign and drops the zeros that mean nothing', function (): void {
        expect(exportedValue('number', '-0007.500', ['decimals' => 0])->numberText())->toBe('-7.5')
            ->and(exportedValue('number', '0', ['decimals' => 1])->numberText())->toBe('0.0')
            ->and(exportedValue('number', '0.00', ['decimals' => 0])->string())->toBe('0');
    });

    it('counts the significant digits, which a spreadsheet keeps only up to 15 of', function (): void {
        expect(exportedValue('number', '123456789012345')->significantDigits())->toBe(15)
            ->and(exportedValue('number', '1234567890123.456', ['decimals' => 3])->significantDigits())->toBe(16)
            ->and(exportedValue('number', '0.000012', ['decimals' => 6])->significantDigits())->toBe(2)
            ->and(exportedValue('number', '0')->significantDigits())->toBe(0);
    });

    it('is nothing for a value that is not a decimal number', function (mixed $stored): void {
        expect(exportedValue('number', $stored)->isEmpty())->toBeTrue();
    })->with([[null], [''], ['abc'], ['1e5'], [['1']], [true]]);

    it('puts the unit of a number in the header and the value', function (): void {
        expect(exportedUnit('number', ['unit' => 'kg']))->toBe('kg')
            ->and(exportedUnit('number'))->toBeNull()
            ->and(exportedValue('number', '3', ['unit' => 'kg'])->unit)->toBe('kg');
    });

    it('gives an amount its currency and the decimals of the currency', function (): void {
        $euros = exportedValue('money', '19.9', ['currency' => 'EUR']);
        $yen = exportedValue('money', '1500', ['currency' => 'JPY']);

        expect($euros->kind)->toBe(ExportKind::Number)
            ->and($euros->currency)->toBe('EUR')
            ->and($euros->unit)->toBeNull()
            ->and($euros->decimals)->toBe(2)
            ->and($euros->numberText())->toBe('19.90')
            ->and($yen->decimals)->toBe(0)
            ->and($yen->numberText())->toBe('1500')
            ->and(exportedUnit('money', ['currency' => 'USD']))->toBe('USD')
            ->and(exportedUnit('money'))->toBe('EUR');
    });

    it('gives a percentage the number before the sign, and the sign as its unit', function (): void {
        $value = exportedValue('percentage', '12.5', ['decimals' => 1]);

        expect($value->kind)->toBe(ExportKind::Number)
            ->and($value->numberText())->toBe('12.5')
            ->and($value->unit)->toBe('%')
            ->and(exportedUnit('percentage'))->toBe('%');
    });

    it('gives a rating as a whole number out of the maximum of the field', function (): void {
        $value = exportedValue('rating', 4, ['max' => 5]);

        expect($value->kind)->toBe(ExportKind::Number)
            ->and($value->numberText())->toBe('4')
            ->and($value->decimals)->toBe(0)
            ->and($value->maximum)->toBe(5)
            ->and(exportedValue('rating', 1, ['max' => 10])->maximum)->toBe(10);
    });

    it('is nothing for a rating the field no longer allows', function (mixed $stored): void {
        expect(exportedValue('rating', $stored, ['max' => 5])->isEmpty())->toBeTrue();
    })->with([[0], [6], [null], ['4'], [4.5]]);
});

describe('the dates and times', function (): void {
    it('gives a date as the day it was stored', function (): void {
        $value = exportedValue('date', '2026-10-15');

        expect($value->kind)->toBe(ExportKind::Date)->and($value->string())->toBe('2026-10-15');
    });

    it('is nothing for a date that does not exist', function (mixed $stored): void {
        expect(exportedValue('date', $stored)->isEmpty())->toBeTrue();
    })->with([['2026-02-30'], ['15/10/2026'], [''], [null], [20261015]]);

    it('converts a moment from UTC to the timezone of the instance', function (): void {
        $summer = exportedValue('datetime', '2026-07-01T10:30:00Z');
        $winter = exportedValue('datetime', '2026-12-01T10:30:00Z');

        expect($summer->kind)->toBe(ExportKind::DateTime)
            ->and($summer->moment()?->format('Y-m-d H:i:s P'))->toBe('2026-07-01 12:30:00 +02:00')
            ->and($winter->moment()?->format('Y-m-d H:i:s P'))->toBe('2026-12-01 11:30:00 +01:00');
    });

    it('follows the timezone of the instance', function (): void {
        config(['app.display_timezone' => 'America/New_York']);

        expect(exportedValue('datetime', '2026-07-01T10:30:00Z')->moment()?->format('Y-m-d H:i'))->toBe('2026-07-01 06:30');

        config(['app.display_timezone' => 'UTC']);

        expect(exportedValue('datetime', '2026-07-01T10:30:00Z')->moment()?->format('Y-m-d H:i'))->toBe('2026-07-01 10:30');
    });

    it('can cross midnight when it is converted', function (): void {
        expect(exportedValue('datetime', '2026-12-31T23:30:00Z')->moment()?->format('Y-m-d H:i'))->toBe('2027-01-01 00:30');
    });

    it('is nothing for a moment that is not valid', function (mixed $stored): void {
        expect(exportedValue('datetime', $stored)->isEmpty())->toBeTrue();
    })->with([['not a date'], [''], [null], ['2026-13-01T00:00:00Z'], [12]]);

    it('gives a time as it was stored', function (): void {
        $value = exportedValue('time', '07:05');

        expect($value->kind)->toBe(ExportKind::Time)->and($value->string())->toBe('07:05');
    });

    it('is nothing for a time that is not valid', function (mixed $stored): void {
        expect(exportedValue('time', $stored)->isEmpty())->toBeTrue();
    })->with([['24:00'], ['7:05'], [''], [null]]);
});

describe('yes or no', function (): void {
    it('gives the answer, and false is a value', function (): void {
        expect(exportedValue('boolean', true)->kind)->toBe(ExportKind::Boolean)
            ->and(exportedValue('boolean', true)->answer())->toBeTrue()
            ->and(exportedValue('boolean', false)->kind)->toBe(ExportKind::Boolean)
            ->and(exportedValue('boolean', false)->answer())->toBeFalse()
            ->and(exportedValue('boolean', false)->isEmpty())->toBeFalse();
    });

    it('is nothing when there is no answer', function (mixed $stored): void {
        expect(exportedValue('boolean', $stored)->isEmpty())->toBeTrue();
    })->with([[null], ['yes'], [1], ['']]);
});

describe('the choices and the tags', function (): void {
    /**
     * A choice field and the ids its choices were given (New, "Used, good", Broken).
     *
     * @return array{0: InventoryField, 1: list<string>}
     */
    $choiceField = function (string $type): array {
        $field = exportFieldOf($type, ['choices' => [['label' => 'New'], ['label' => 'Used, good'], ['label' => 'Broken']]]);

        return [$field, array_column(ChoiceType::choicesOf($field->options), 'id')];
    };

    it('gives the label of a choice, not its id', function () use ($choiceField): void {
        [$field, $ids] = $choiceField('select');
        $value = app(FieldTypes::class)->get('select')->exportValue($ids[1], $field);

        expect($value->kind)->toBe(ExportKind::Text)->and($value->string())->toBe('Used, good');
    });

    it('is nothing for a choice the field no longer has', function (mixed $stored) use ($choiceField): void {
        [$field] = $choiceField('select');

        expect(app(FieldTypes::class)->get('select')->exportValue($stored, $field)->isEmpty())->toBeTrue();
    })->with([['zzzzzzz9'], [null], [''], [['aaaaaaa1']], [12]]);

    it('joins the labels of several choices by a comma, in the order they were chosen', function () use ($choiceField): void {
        [$field, $ids] = $choiceField('multiselect');
        $value = app(FieldTypes::class)->get('multiselect')->exportValue([$ids[2], $ids[0]], $field);

        expect($value->kind)->toBe(ExportKind::Text)->and($value->string())->toBe('Broken, New');
    });

    it('leaves out the choices the field no longer has, and is nothing when none is left', function () use ($choiceField): void {
        [$field, $ids] = $choiceField('multiselect');
        $type = app(FieldTypes::class)->get('multiselect');

        expect($type->exportValue([$ids[0], 'zzzzzzz9', 7], $field)->string())->toBe('New')
            ->and($type->exportValue(['zzzzzzz9'], $field)->isEmpty())->toBeTrue()
            ->and($type->exportValue([], $field)->isEmpty())->toBeTrue()
            ->and($type->exportValue($ids[0], $field)->isEmpty())->toBeTrue();
    });

    it('joins the tags by a comma', function (): void {
        expect(exportedValue('tags', ['red', 'blue', 'two words'])->string())->toBe('red, blue, two words')
            ->and(exportedValue('tags', [])->isEmpty())->toBeTrue()
            ->and(exportedValue('tags', 'red')->isEmpty())->toBeTrue();
    });
});

describe('the files and photos', function (): void {
    $names = ['01hzzzzzzzzzzzzzzzzzzzzzz1' => 'front.jpg', '01hzzzzzzzzzzzzzzzzzzzzzz2' => 'back, left.jpg'];

    it('gives the names of the attached files, in the order of the value', function (string $type) use ($names): void {
        $value = exportedValue($type, ['01hzzzzzzzzzzzzzzzzzzzzzz2', '01hzzzzzzzzzzzzzzzzzzzzzz1'], fileNames: $names);

        expect($value->kind)->toBe(ExportKind::Text)->and($value->string())->toBe('back, left.jpg, front.jpg');
    })->with(['file', 'photo']);

    it('leaves out a file that is gone, and is nothing when none is left', function () use ($names): void {
        expect(exportedValue('photo', ['01hzzzzzzzzzzzzzzzzzzzzzz1', '01hyyyyyyyyyyyyyyyyyyyyyyy'], fileNames: $names)->string())->toBe('front.jpg')
            ->and(exportedValue('photo', ['01hyyyyyyyyyyyyyyyyyyyyyyy'], fileNames: $names)->isEmpty())->toBeTrue()
            ->and(exportedValue('photo', [], fileNames: $names)->isEmpty())->toBeTrue()
            ->and(exportedValue('file', null, fileNames: $names)->isEmpty())->toBeTrue()
            ->and(exportedValue('file', ['01hzzzzzzzzzzzzzzzzzzzzzz1'])->isEmpty())->toBeTrue();
    });
});

it('has an export value for every type of the registry', function (): void {
    foreach (app(FieldTypes::class)->all() as $type) {
        $field = exportFieldOf($type->key(), $type->key() === 'select' || $type->key() === 'multiselect' ? ['choices' => [['label' => 'A']]] : []);

        expect($type->exportValue(null, $field))->toBeInstanceOf(ExportValue::class)
            ->and($type->exportValue(null, $field)->isEmpty())->toBeTrue();
    }
});
