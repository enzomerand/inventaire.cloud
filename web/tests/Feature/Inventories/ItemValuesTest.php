<?php

declare(strict_types=1);

use App\Inventories\Fields\FieldTypes;
use App\Inventories\ItemValues;
use App\Models\InventoryField;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/*
 * The rules that turn what a person sends into what an item stores, on fields held in memory (no database): the
 * API tests go through the same code with real rows.
 */

/**
 * A field in memory, with the options a person would send, normalized by its type.
 *
 * @param  array<string, mixed>  $options
 */
function valuesField(string $ulid, string $type, string $name = '', array $options = [], bool $required = false): InventoryField
{
    $field = new InventoryField(['name' => $name !== '' ? $name : ucfirst($type), 'type' => $type, 'required' => $required]);
    $known = app(FieldTypes::class)->find($type);
    $field->forceFill(['ulid' => $ulid, 'options' => $known?->normalizeOptions($options, null)]);

    return $field;
}

/**
 * The fields as the services read them: a collection.
 *
 * @return Collection<int, InventoryField>
 */
function valuesFields(InventoryField ...$fields): Collection
{
    return new Collection($fields);
}

describe('writing values', function (): void {
    it('stores the canonical form of what is sent, and nothing for what is empty', function (): void {
        $fields = valuesFields(
            valuesField('f1', 'number', options: ['decimals' => 2]),
            valuesField('f2', 'text'),
            valuesField('f3', 'tags'),
            valuesField('f4', 'boolean'),
        );

        $stored = app(ItemValues::class)->apply($fields, ['f1' => 3, 'f2' => '  ', 'f3' => [' a ', 'A'], 'f4' => false], [], true);

        expect($stored)->toBe(['f1' => '3.00', 'f3' => ['a'], 'f4' => false]);
    });

    it('keeps what an update does not mention, replaces what it sends and clears what it empties', function (): void {
        $fields = valuesFields(valuesField('f1', 'text'), valuesField('f2', 'text'), valuesField('f3', 'text'));

        $stored = app(ItemValues::class)->apply($fields, ['f1' => 'new', 'f2' => null], ['f1' => 'old', 'f2' => 'old', 'f3' => 'kept'], false);

        expect($stored)->toBe(['f1' => 'new', 'f3' => 'kept']);
    });

    it('does not judge the fields an update does not mention, required or not', function (): void {
        $fields = valuesFields(valuesField('f1', 'text', required: true), valuesField('f2', 'number', required: true));

        expect(app(ItemValues::class)->apply($fields, ['f2' => '4'], [], false))->toBe(['f2' => '4'])
            ->and(app(ItemValues::class)->apply($fields, [], ['f1' => 'a'], false))->toBe(['f1' => 'a']);
    });

    it('judges every field when an item is created, and says so for each required one that is empty', function (): void {
        $fields = valuesFields(valuesField('f1', 'text', 'Name', required: true), valuesField('f2', 'number', 'Weight', required: true), valuesField('f3', 'text', 'Note'));

        try {
            app(ItemValues::class)->apply($fields, ['f3' => 'x'], [], true);
            $this->fail('A required field without a value must be refused.');
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(['values.f1', 'values.f2']);
        }
    });

    it('drops the keys of fields that are gone from what an item holds', function (): void {
        $fields = valuesFields(valuesField('f1', 'text'));

        expect(app(ItemValues::class)->apply($fields, [], ['f1' => 'a', 'gone' => 'stray'], false))->toBe(['f1' => 'a'])
            ->and(app(ItemValues::class)->apply($fields, ['f1' => 'b'], ['gone' => 'stray'], false))->toBe(['f1' => 'b']);
    });

    it('refuses the key of a field whose type this version does not know, and does not ask it for a value', function (): void {
        $fields = valuesFields(valuesField('f1', 'relation', required: true), valuesField('f2', 'text'));

        try {
            app(ItemValues::class)->apply($fields, ['f1' => 'x', 'f2' => 'a'], [], true);
            $this->fail('A field of an unknown type cannot be written.');
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(['values.f1']);
        }

        // It is not required of anyone either, and what an item holds for it is kept.
        expect(app(ItemValues::class)->apply($fields, ['f2' => 'a'], ['f1' => 'kept'], false))->toBe(['f1' => 'kept', 'f2' => 'a'])
            ->and(app(ItemValues::class)->apply(valuesFields(valuesField('f1', 'relation', required: true)), [], [], true))->toBe([]);
    });

    it('names the unknown keys as they were sent, numeric-looking and unusual ones included', function (): void {
        $fields = valuesFields(valuesField('f1', 'text'));

        try {
            app(ItemValues::class)->apply($fields, ['f1' => 'a', 0 => 'x', 'a.b' => 'y', 'G1' => 'z'], [], true);
            $this->fail('Unknown keys must be refused.');
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(['values.0', 'values.a.b', 'values.G1']);
        }
    });

    it('finds a field by its ULID whatever the case, as a URL does, and stores the value under the lowercase key', function (): void {
        $fields = valuesFields(valuesField('01j0000000000000000000000a', 'text'), valuesField('01j0000000000000000000000b', 'number'), valuesField('01j0000000000000000000000c', 'text', required: true));

        $created = app(ItemValues::class)->apply($fields, ['01J0000000000000000000000C' => 'x', '01J0000000000000000000000A' => ' y ', '01j0000000000000000000000b' => '3'], [], true);
        $changed = app(ItemValues::class)->apply($fields, ['01J0000000000000000000000A' => null], $created, false);

        expect($created)->toBe(['01j0000000000000000000000a' => 'y', '01j0000000000000000000000b' => '3', '01j0000000000000000000000c' => 'x'])
            ->and($changed)->toBe(['01j0000000000000000000000b' => '3', '01j0000000000000000000000c' => 'x']);

        // The required field is satisfied by its key in capitals, and cleared by it too.
        try {
            app(ItemValues::class)->apply($fields, ['01J0000000000000000000000C' => ''], $created, false);
            $this->fail('A required field cannot be cleared.');
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(['values.01j0000000000000000000000c']);
        }
    });

    it('names the field in the message of a refused value', function (): void {
        $this->useLanguage('en');
        $fields = valuesFields(valuesField('f1', 'rating', 'Quality', ['max' => 3]));

        try {
            app(ItemValues::class)->apply($fields, ['f1' => 7], [], true);
            $this->fail('A rating above the maximum must be refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toBe(['values.f1' => ['The Quality must be a whole number from 1 to 3.']]);
        }
    });
});

describe('reading values', function (): void {
    it('gives the values of the fields that exist, in the order of the fields', function (): void {
        $fields = valuesFields(valuesField('f2', 'text'), valuesField('f1', 'number', options: ['decimals' => 1]), valuesField('f3', 'text'));

        $values = app(ItemValues::class)->present($fields, ['f1' => '1.5', 'f2' => 'b', 'gone' => 'stray']);

        expect($values)->toBe(['f2' => 'b', 'f1' => '1.5'])
            ->and(array_keys($values))->toBe(['f2', 'f1']);
    });

    it('lets each type clean up its value, and drops what is left empty', function (): void {
        $select = valuesField('f1', 'select', options: ['choices' => [['label' => 'A']]]);
        $multi = valuesField('f2', 'multiselect', options: ['choices' => [['label' => 'A'], ['label' => 'B']]]);
        [$a] = array_column($select->options['choices'], 'id');
        [$ma, $mb] = array_column($multi->options['choices'], 'id');

        $presenter = app(ItemValues::class);

        expect($presenter->present(valuesFields($select, $multi), ['f1' => $a, 'f2' => [$mb, 'gone0000', $ma]]))->toBe(['f1' => $a, 'f2' => [$mb, $ma]])
            ->and($presenter->present(valuesFields($select, $multi), ['f1' => 'gone0000', 'f2' => ['gone0000']]))->toBe([]);
    });

    it('keeps false and zero, and skips a field of a type it does not know', function (): void {
        $fields = valuesFields(valuesField('f1', 'boolean'), valuesField('f2', 'number'), valuesField('f3', 'relation'));

        expect(app(ItemValues::class)->present($fields, ['f1' => false, 'f2' => '0', 'f3' => 'x']))->toBe(['f1' => false, 'f2' => '0']);
    });
});
