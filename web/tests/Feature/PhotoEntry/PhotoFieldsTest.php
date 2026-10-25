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
