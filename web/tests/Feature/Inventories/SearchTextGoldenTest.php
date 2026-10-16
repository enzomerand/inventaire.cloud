<?php

declare(strict_types=1);

use App\Inventories\Fields\FieldTypes;
use App\Inventories\ItemSearchText;
use App\Inventories\SearchText;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/inventories.php';

/*
 * The golden text: one item that holds a value of every type of field that is searched, and the exact text it is searched
 * by. The text is stored (items.search_text) and compared with what is typed, so anything that changes it for the same item
 * leaves the items written before the change unfindable until `inventaire:upgrade` builds them again, and it only does so
 * when SearchText::VERSION changed.
 *
 * This test fails whenever the text comes out different: a step of SearchText::normalize(), SearchText's table of letters
 * written out, the separator of the parts, or the searchText() of any type of field. When it does, that was intended:
 * bump SearchText::VERSION and then update the expectation (version and text) here, in the same change.
 */

uses(RefreshDatabase::class);

/**
 * The types of field that are searched, in the order the fields of the golden inventory are created, with their options.
 *
 * @return array<string, array<string, mixed>>
 */
function goldenSearchedTypes(): array
{
    return [
        'text' => [],
        'textarea' => [],
        'phone' => [],
        'url' => [],
        'email' => [],
        'select' => ['choices' => [['label' => 'Neuf'], ['label' => 'Reçu en état']]],
        'multiselect' => ['choices' => [['label' => 'Rouge'], ['label' => 'Œil de bœuf'], ['label' => 'Bleu']]],
        'barcode' => [],
        'tags' => [],
    ];
}

/**
 * The types of field that add nothing to the text, with a value each (so that the item holds one of every type).
 *
 * @return array<string, array{options: array<string, mixed>, value: mixed}>
 */
function goldenSilentTypes(): array
{
    return [
        'number' => ['options' => ['decimals' => 2], 'value' => '12.50'],
        'money' => ['options' => ['currency' => 'EUR'], 'value' => '19.90'],
        'percentage' => ['options' => [], 'value' => '7.5'],
        'rating' => ['options' => ['max' => 5], 'value' => 4],
        'boolean' => ['options' => [], 'value' => true],
        'date' => ['options' => [], 'value' => '2026-10-15'],
        'datetime' => ['options' => [], 'value' => '2026-10-15T12:30:00Z'],
        'time' => ['options' => [], 'value' => '08:05'],
        'color' => ['options' => [], 'value' => '#aabbcc'],
        // Files are listed by their attachments, not searched; their value is made by uploading (see FileFieldsTest.php).
        'file' => ['options' => [], 'value' => null],
        'photo' => ['options' => [], 'value' => null],
    ];
}

describe('the golden search text', function (): void {
    it('knows every type of field the application has, searched or not: a new type must be placed in this test', function (): void {
        $known = [...array_keys(goldenSearchedTypes()), ...array_keys(goldenSilentTypes())];

        expect(app(FieldTypes::class)->keys())->toEqualCanonicalizing($known);
    });

    it('is the text an item holding a value of every type is searched by, and the version of its form', function (): void {
        $inventory = inventoryIn(inventoryTeam()['workspace']);
        $fields = [];

        foreach (goldenSearchedTypes() as $type => $options) {
            $fields[$type] = fieldIn($inventory, $type, ucfirst($type), $options);
        }

        foreach (goldenSilentTypes() as $type => $silent) {
            $fields[$type] = fieldIn($inventory, $type, ucfirst($type), $silent['options']);
        }

        $choices = fn (string $type): array => array_column($fields[$type]->fresh()?->options['choices'] ?? [], 'id');
        [, $received] = $choices('select');
        [$red, $eye, $blue] = $choices('multiselect');

        $values = [
            $fields['text']->ulid => '  Électricité   ŒUVRE  Straße ',
            $fields['textarea']->ulid => "Première ligne\n\nSeconde\tligne — Łódź, Ǆ ﬁn ＡＢＣ",
            $fields['phone']->ulid => '+33 (0)1 23-45.67 89',
            $fields['url']->ulid => 'https://Example.com/Chemin?mot=Été',
            $fields['email']->ulid => 'Jane.Doë@Example.COM',
            $fields['select']->ulid => $received,
            $fields['multiselect']->ulid => [$blue, $red, $eye],
            $fields['barcode']->ulid => 'ABC-123',
            $fields['tags']->ulid => ['Outil à main', 'ÉLECTRO', 'Σίσυφος ὀδυσσεύς'],
        ];

        foreach (goldenSilentTypes() as $type => $silent) {
            // A file or photo field has no value to send: its files are uploaded.
            if ($silent['value'] !== null) {
                $values[$fields[$type]->ulid] = $silent['value'];
            }
        }

        $item = itemIn($inventory, $values);
        $text = app(ItemSearchText::class)->build($inventory->fields()->get(), $item->values);

        // The text is what the item is stored with, and it is one text for the same values (the version is part of the answer).
        expect($item->fresh()?->search_text)->toBe($text)
            ->and([SearchText::VERSION, $text])->toBe([
                1,
                implode("\n", [
                    'electricite oeuvre strasse',
                    'premiere ligne seconde ligne — lodz, dz fin abc',
                    '+33 (0)1 23-45.67 89',
                    'https://example.com/chemin?mot=ete',
                    'jane.doe@example.com',
                    'recu en etat',
                    'bleu rouge oeil de boeuf',
                    'abc-123',
                    'outil a main electro σισυφοσ οδυσσευσ',
                ]),
            ], 'The text an item is searched by came out different. If that is intended, bump SearchText::VERSION and update this expectation.');
    });
});
