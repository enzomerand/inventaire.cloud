<?php

declare(strict_types=1);

use App\PhotoEntry\PhotoFields;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/photo-entry.php';

/*
 * The fields as the photo entry sees them: which ones a photo can fill, and the catalogue Claude is given for each.
 */

uses(RefreshDatabase::class);

describe('the fields a photo can fill', function (): void {
    it('are the typed ones: the file and photo fields are left out', function (): void {
        $case = photoCase();
        $inventory = $case['inventory'];
        $name = fieldIn($inventory, 'text', 'Name');
        fieldIn($inventory, 'file', 'Manual');
        fieldIn($inventory, 'photo', 'Pictures');
        $weight = fieldIn($inventory, 'number', 'Weight');

        $eligible = app(PhotoFields::class)->eligible($inventory->fields()->get());

        expect(array_map(fn ($field): string => $field->ulid, $eligible))->toBe([$name->ulid, $weight->ulid]);
    });

    it('are none for an inventory that only has file and photo fields, or no field', function (): void {
        $case = photoCase();
        $inventory = $case['inventory'];

        expect(app(PhotoFields::class)->eligible($inventory->fields()->get()))->toBe([]);

        fieldIn($inventory, 'photo', 'Pictures');

        expect(app(PhotoFields::class)->eligible($inventory->fields()->get()))->toBe([]);
    });
});

describe('the catalogue', function (): void {
    it('gives the ULID, the name and the type of each field', function (): void {
        $case = photoCase();
        $name = fieldIn($case['inventory'], 'text', 'Name', ['max_length' => 80]);

        $catalogue = app(PhotoFields::class)->catalogue(app(PhotoFields::class)->eligible($case['inventory']->fields()->get()));

        expect($catalogue)->toBe([['id' => $name->ulid, 'name' => 'Name', 'type' => 'text', 'hints' => ['max_length' => 80]]]);
    });

    it('says how the value of each type is written', function (): void {
        $case = photoCase();
        $inventory = $case['inventory'];
        $fields = [
            'text' => fieldIn($inventory, 'text', 'Text'),
            'textarea' => fieldIn($inventory, 'textarea', 'Notes'),
            'number' => fieldIn($inventory, 'number', 'Weight', ['decimals' => 2, 'unit' => 'kg', 'min' => '0', 'max' => '500']),
            'bare number' => fieldIn($inventory, 'number', 'Count'),
            'money' => fieldIn($inventory, 'money', 'Price', ['currency' => 'USD']),
            'percentage' => fieldIn($inventory, 'percentage', 'Share', ['decimals' => 1]),
            'boolean' => fieldIn($inventory, 'boolean', 'Working'),
            'date' => fieldIn($inventory, 'date', 'Bought'),
            'datetime' => fieldIn($inventory, 'datetime', 'Seen'),
            'time' => fieldIn($inventory, 'time', 'Opening'),
            'select' => fieldIn($inventory, 'select', 'Condition', ['choices' => [['label' => 'New'], ['label' => 'Used, good']]]),
            'multiselect' => fieldIn($inventory, 'multiselect', 'Extras', ['choices' => [['label' => 'Case'], ['label' => 'Charger']]]),
            'tags' => fieldIn($inventory, 'tags', 'Tags'),
            'url' => fieldIn($inventory, 'url', 'Page'),
            'email' => fieldIn($inventory, 'email', 'Mail'),
            'phone' => fieldIn($inventory, 'phone', 'Phone'),
            'rating' => fieldIn($inventory, 'rating', 'Grade', ['max' => 7]),
            'color' => fieldIn($inventory, 'color', 'Color'),
            'barcode' => fieldIn($inventory, 'barcode', 'Code', ['format' => 'ean13']),
        ];
        $photoFields = app(PhotoFields::class);

        $hints = collect($photoFields->catalogue($photoFields->eligible($inventory->fields()->get())))->keyBy('id')->map(fn (array $entry): array => $entry['hints']);

        expect($hints[$fields['text']->ulid])->toBe(['max_length' => 255])
            ->and($hints[$fields['textarea']->ulid])->toBe(['max_length' => 10000])
            ->and($hints[$fields['number']->ulid])->toBe(['decimals' => 2, 'unit' => 'kg', 'min' => '0', 'max' => '500'])
            ->and($hints[$fields['bare number']->ulid])->toBe(['decimals' => 0])
            ->and($hints[$fields['money']->ulid])->toBe(['currency' => 'USD', 'decimals' => 2])
            ->and($hints[$fields['percentage']->ulid])->toBe(['decimals' => 1, 'unit' => '%'])
            ->and($hints[$fields['boolean']->ulid])->toBe(['answer' => 'true or false'])
            ->and($hints[$fields['date']->ulid])->toBe(['format' => 'YYYY-MM-DD'])
            ->and($hints[$fields['datetime']->ulid])->toBe(['format' => 'YYYY-MM-DD HH:MM, 24 hours, local time'])
            ->and($hints[$fields['time']->ulid])->toBe(['format' => 'HH:MM, 24 hours'])
            ->and($hints[$fields['select']->ulid])->toBe(['choices' => ['New', 'Used, good']])
            ->and($hints[$fields['multiselect']->ulid])->toBe(['choices' => ['Case', 'Charger']])
            ->and($hints[$fields['tags']->ulid])->toBe(['max_tags' => 30, 'max_tag_length' => 50])
            ->and($hints[$fields['url']->ulid])->toBe(['format' => 'complete address, starting with https://'])
            ->and($hints[$fields['email']->ulid])->toBe([])
            ->and($hints[$fields['phone']->ulid])->toBe([])
            ->and($hints[$fields['rating']->ulid])->toBe(['from' => 1, 'to' => 7])
            ->and($hints[$fields['color']->ulid])->toBe(['format' => '#rrggbb'])
            ->and($hints[$fields['barcode']->ulid])->toBe(['format' => 'ean13']);
    });
});

describe('the budget of the catalogue', function (): void {
    /**
     * The characters the catalogue takes in the request.
     *
     * @param  list<array{id: string, name: string, type: string, hints: array<string, mixed>}>  $catalogue
     */
    function catalogueSize(array $catalogue): int
    {
        return array_sum(array_map(PhotoFields::size(...), $catalogue));
    }

    it('lists the labels of the choices while there is room', function (): void {
        $case = photoCase();
        $condition = fieldIn($case['inventory'], 'select', 'Condition', photoManyChoices(10));
        $photoFields = app(PhotoFields::class);

        $catalogue = $photoFields->catalogue($photoFields->eligible($case['inventory']->fields()->get()));

        expect($catalogue)->toHaveCount(1)
            ->and($catalogue[0]['hints']['choices'])->toBeArray()->toHaveCount(10)
            ->and(catalogueSize($catalogue))->toBeLessThanOrEqual(PhotoFields::CATALOGUE_BUDGET);
    });

    it('stays within the budget for a huge inventory: the choices that do not fit are summarised, in the order of the fields', function (): void {
        $case = photoCase();
        $fields = [];

        // 40 fields of 200 choices of 100 characters: about 800 000 characters if everything were written out.
        for ($number = 1; $number <= 40; $number++) {
            $fields[] = fieldIn($case['inventory'], 'select', "Field {$number}", photoManyChoices(200));
        }

        $photoFields = app(PhotoFields::class);
        $catalogue = $photoFields->catalogue($photoFields->eligible($case['inventory']->fields()->get()));
        $listed = array_values(array_filter($catalogue, fn (array $entry): bool => is_array($entry['hints']['choices'])));

        expect(catalogueSize($catalogue))->toBeLessThanOrEqual(PhotoFields::CATALOGUE_BUDGET)
            // Every field is still offered, with a sentence in place of the labels it had no room for.
            ->and(array_column($catalogue, 'id'))->toBe(array_map(fn ($field): string => $field->ulid, $fields))
            ->and(count($listed))->toBeLessThan(2)
            ->and($catalogue[39]['hints']['choices'])->toBe('one of 200 predefined values: write the label as read');
    });

    it('summarises a multiple choice as any of its values', function (): void {
        $case = photoCase();
        $first = fieldIn($case['inventory'], 'multiselect', 'First', photoManyChoices(200));
        $second = fieldIn($case['inventory'], 'multiselect', 'Second', photoManyChoices(200));
        $photoFields = app(PhotoFields::class);

        $hints = collect($photoFields->catalogue($photoFields->eligible($case['inventory']->fields()->get())))->keyBy('id')->map(fn (array $entry): array => $entry['hints']);

        expect($hints[$first->ulid]['choices'])->toBeArray()->toHaveCount(200)
            ->and($hints[$second->ulid]['choices'])->toBe('any of 200 predefined values: write each label as read');
    });

    it('writes out a small field further down when a big one before it had no room', function (): void {
        $case = photoCase();
        $bigs = [];

        for ($number = 1; $number <= 3; $number++) {
            $bigs[] = fieldIn($case['inventory'], 'select', "Big {$number}", photoManyChoices(200));
        }

        $small = fieldIn($case['inventory'], 'select', 'Small', ['choices' => [['label' => 'New'], ['label' => 'Used']]]);
        $photoFields = app(PhotoFields::class);

        $hints = collect($photoFields->catalogue($photoFields->eligible($case['inventory']->fields()->get())))->keyBy('id')->map(fn (array $entry): array => $entry['hints']);

        expect($hints[$bigs[0]->ulid]['choices'])->toBeArray()
            ->and($hints[$bigs[1]->ulid]['choices'])->toBeString()
            ->and($hints[$bigs[2]->ulid]['choices'])->toBeString()
            ->and($hints[$small->ulid]['choices'])->toBe(['New', 'Used']);
    });

    it('leaves out the fields that do not fit even summarised, so that Claude is not offered them', function (): void {
        $case = photoCase();

        for ($number = 1; $number <= 100; $number++) {
            // A long name and a long unit: about 250 characters a line, 25 000 for the hundred.
            fieldIn($case['inventory'], 'number', str_pad("Field {$number} ", 120, 'n'), ['unit' => str_repeat('u', 30)]);
        }

        $photoFields = app(PhotoFields::class);
        $eligible = $photoFields->eligible($case['inventory']->fields()->get());
        $catalogue = $photoFields->catalogue($eligible);

        expect(count($eligible))->toBe(100)
            ->and(count($catalogue))->toBeLessThan(100)->toBeGreaterThan(50)
            ->and(catalogueSize($catalogue))->toBeLessThanOrEqual(PhotoFields::CATALOGUE_BUDGET)
            // The ones that were kept are the first, in order.
            ->and(array_column($catalogue, 'id'))->toBe(array_map(fn ($field): string => $field->ulid, array_slice($eligible, 0, count($catalogue))));
    });
});
