<?php

declare(strict_types=1);

use App\Inventories\Fields\FieldTypes;
use App\Inventories\ItemSearchText;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/inventories.php';

/*
 * Searching the items of an inventory by words (`?q=`), whatever the case and the accents: the text each item is
 * searched by (`items.search_text`), what each type of field adds to it, and how it follows the changes made to items and
 * fields. The migration that fills it for the items that already exist is in ItemSearchTextMigrationTest.php.
 */

uses(RefreshDatabase::class);

/**
 * The names of the items a search finds, sorted. An item without a name is "(no name)".
 *
 * @return list<string>
 */
function searchFinds(object $test, User $as, Inventory $inventory, string $typed, InventoryField $name): array
{
    $response = inventoryCall($test, $as, 'GET', "/api/v1/inventories/{$inventory->ulid}/items?q=".rawurlencode($typed))->assertOk();
    $names = array_map(fn (array $item): string => $item['values'][$name->ulid] ?? '(no name)', $response->json('data'));
    sort($names);

    return $names;
}

/**
 * An inventory with a text field named "Name", and one item for each name given.
 *
 * @param  list<string>  $names
 * @return array{team: array<string, mixed>, inventory: Inventory, name: InventoryField}
 */
function searchableInventory(array $names): array
{
    $team = inventoryTeam();
    $inventory = inventoryIn($team['workspace']);
    $name = fieldIn($inventory, 'text', 'Name');

    foreach ($names as $label) {
        itemIn($inventory, [$name->ulid => $label]);
    }

    return ['team' => $team, 'inventory' => $inventory, 'name' => $name];
}

/**
 * The text an item is searched by, as the database holds it.
 */
function searchTextOf(Item $item): ?string
{
    $text = DB::table('items')->where('id', $item->id)->value('search_text');

    return $text === null ? null : (string) $text;
}

/**
 * A field in memory (not saved), with the options a person would send once the type has normalized them.
 *
 * @param  array<string, mixed>  $options
 */
function searchFieldOf(string $type, array $options = []): InventoryField
{
    return new InventoryField(['name' => 'Field', 'type' => $type, 'options' => app(FieldTypes::class)->get($type)->normalizeOptions($options, null)]);
}

describe('finding items by words', function (): void {
    it('ignores the case and the accents, on both sides', function (): void {
        $made = searchableInventory(['Électricité', 'Plomberie', 'electrique', 'ÉLAN']);
        ['team' => $team, 'inventory' => $inventory, 'name' => $name] = $made;

        expect(searchFinds($this, $team['owner'], $inventory, 'electricite', $name))->toBe(['Électricité'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'ÉLEC', $name))->toBe(['electrique', 'Électricité'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'électr', $name))->toBe(['electrique', 'Électricité'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'ELECTRICITÉ', $name))->toBe(['Électricité'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'elan', $name))->toBe(['ÉLAN'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'omberi', $name))->toBe(['Plomberie'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'gaz', $name))->toBe([]);
    });

    it('finds the letters written with two by the two, and the two by the letter', function (): void {
        ['team' => $team, 'inventory' => $inventory, 'name' => $name] = searchableInventory(['Œuvre complète', 'Oeuvre', 'Straße', 'Æther']);

        expect(searchFinds($this, $team['owner'], $inventory, 'oeuvre', $name))->toBe(['Oeuvre', 'Œuvre complète'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'œuvre', $name))->toBe(['Oeuvre', 'Œuvre complète'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'ŒUVRE COMPLETE', $name))->toBe(['Œuvre complète'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'strasse', $name))->toBe(['Straße'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'STRASSE', $name))->toBe(['Straße'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'straße', $name))->toBe(['Straße'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'aether', $name))->toBe(['Æther']);
    });

    it('wants every word, in any order, wherever they are', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $notes = fieldIn($inventory, 'textarea', 'Notes');
        itemIn($inventory, [$name->ulid => 'Red hammer', $notes->ulid => "Bought in Lyon\nlast spring"]);
        itemIn($inventory, [$name->ulid => 'Blue hammer', $notes->ulid => 'Bought in Paris']);
        itemIn($inventory, [$name->ulid => 'Red saw']);

        expect(searchFinds($this, $team['owner'], $inventory, 'hammer', $name))->toBe(['Blue hammer', 'Red hammer'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'hammer red', $name))->toBe(['Red hammer'])
            ->and(searchFinds($this, $team['owner'], $inventory, '  RED   Hammer ', $name))->toBe(['Red hammer'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'red red', $name))->toBe(['Red hammer', 'Red saw'])
            // Words in two different fields, and across the lines of a long text.
            ->and(searchFinds($this, $team['owner'], $inventory, 'lyon hammer', $name))->toBe(['Red hammer'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'spring lyon', $name))->toBe(['Red hammer'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'hammer paris lyon', $name))->toBe([])
            ->and(searchFinds($this, $team['owner'], $inventory, 'saw lyon', $name))->toBe([]);
    });

    it('does not let a word match across two values', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $first = fieldIn($inventory, 'text', 'First');
        $second = fieldIn($inventory, 'text', 'Second');
        itemIn($inventory, [$first->ulid => 'abc', $second->ulid => 'def']);

        expect(searchFinds($this, $team['owner'], $inventory, 'abc', $first))->toBe(['abc'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'cd', $first))->toBe([])
            ->and(searchFinds($this, $team['owner'], $inventory, 'abcdef', $first))->toBe([])
            // Two words may each be in another value, as they may be in another field of the same item.
            ->and(searchFinds($this, $team['owner'], $inventory, 'c d', $first))->toBe(['abc']);
    });

    it('takes %, _ and ! for themselves, never for a wildcard', function (): void {
        ['team' => $team, 'inventory' => $inventory, 'name' => $name] = searchableInventory(['100% cotton', '1000 cotton', 'A_B', 'AxB', 'Hello!', 'Hello', 'a!b', 'ab']);

        expect(searchFinds($this, $team['owner'], $inventory, '100%', $name))->toBe(['100% cotton'])
            ->and(searchFinds($this, $team['owner'], $inventory, '%', $name))->toBe(['100% cotton'])
            ->and(searchFinds($this, $team['owner'], $inventory, '10%0', $name))->toBe([])
            ->and(searchFinds($this, $team['owner'], $inventory, 'a_b', $name))->toBe(['A_B'])
            ->and(searchFinds($this, $team['owner'], $inventory, '_', $name))->toBe(['A_B'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'a_', $name))->toBe(['A_B'])
            ->and(searchFinds($this, $team['owner'], $inventory, '!', $name))->toBe(['Hello!', 'a!b'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'hello!', $name))->toBe(['Hello!'])
            ->and(searchFinds($this, $team['owner'], $inventory, 'a!b', $name))->toBe(['a!b'])
            ->and(searchFinds($this, $team['owner'], $inventory, '!!', $name))->toBe([])
            ->and(searchFinds($this, $team['owner'], $inventory, '%%', $name))->toBe([])
            ->and(searchFinds($this, $team['owner'], $inventory, '!%', $name))->toBe([])
            ->and(searchFinds($this, $team['owner'], $inventory, '!_', $name))->toBe([])
            ->and(searchFinds($this, $team['owner'], $inventory, '\\', $name))->toBe([])
            ->and(searchFinds($this, $team['owner'], $inventory, "'; DROP TABLE items; --", $name))->toBe([]);
    });

    it('filters nothing when there is nothing to look for', function (string $typed): void {
        ['team' => $team, 'inventory' => $inventory, 'name' => $name] = searchableInventory(['Hammer', 'Saw']);
        itemIn($inventory);

        expect(searchFinds($this, $team['owner'], $inventory, $typed, $name))->toBe(['(no name)', 'Hammer', 'Saw']);
    })->with(['an empty search' => '', 'spaces' => '   ', 'tabs and line breaks' => " \t\n "]);

    it('never finds an item that holds nothing to search', function (): void {
        ['team' => $team, 'inventory' => $inventory, 'name' => $name] = searchableInventory(['Hammer']);
        $number = fieldIn($inventory, 'number', 'Weight');
        $empty = itemIn($inventory);
        $onlyNumber = itemIn($inventory, [$number->ulid => '12']);

        expect(searchFinds($this, $team['owner'], $inventory, 'a', $name))->toBe(['Hammer'])
            ->and(searchFinds($this, $team['owner'], $inventory, '', $name))->toBe(['(no name)', '(no name)', 'Hammer'])
            ->and(searchTextOf($empty))->toBeNull()
            ->and(searchTextOf($onlyNumber))->toBeNull();
    });

    it('stays in the inventory that is asked for', function (): void {
        ['team' => $team, 'inventory' => $inventory, 'name' => $name] = searchableInventory(['Hammer']);
        $other = inventoryIn($team['workspace'], 'Other');
        itemIn($other, [fieldIn($other, 'text', 'Name')->ulid => 'Hammer drill']);

        expect(searchFinds($this, $team['owner'], $inventory, 'hammer', $name))->toBe(['Hammer']);
    });

    it('is open to every member, and a 404 to a stranger', function (string $role): void {
        ['team' => $team, 'inventory' => $inventory, 'name' => $name] = searchableInventory(['Électricité', 'Plomberie']);

        expect(searchFinds($this, $team[$role], $inventory, 'electricite', $name))->toBe(['Électricité']);

        inventoryCall($this, $team['stranger'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items?q=electricite")->assertNotFound();
    })->with(['an owner' => 'owner', 'an editor' => 'editor', 'a viewer' => 'viewer']);

    it('is refused beyond 200 characters, or when it is not a text (422)', function (): void {
        ['team' => $team, 'inventory' => $inventory, 'name' => $name] = searchableInventory(['Hammer']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";

        expect(searchFinds($this, $team['owner'], $inventory, str_repeat('é', 200), $name))->toBe([]);

        inventoryCall($this, $team['owner'], 'GET', "{$uri}?q=".rawurlencode(str_repeat('é', 201)))->assertUnprocessable()->assertJsonValidationErrors('q');
        inventoryCall($this, $team['owner'], 'GET', "{$uri}?q[]=hammer")->assertUnprocessable()->assertJsonValidationErrors('q');
    });

    it('is kept by the links of the pages', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');

        foreach (range(1, 51) as $number) {
            Item::factory()->for($inventory)->create(['values' => [$name->ulid => "Électricité {$number}"], 'search_text' => "electricite {$number}"]);
        }

        Item::factory()->for($inventory)->create(['values' => [$name->ulid => 'Plomberie'], 'search_text' => 'plomberie']);

        $first = inventoryCall($this, $team['viewer'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items?q=".rawurlencode('Électricité'))->assertOk();
        $next = parse_url((string) $first->json('links.next'), PHP_URL_QUERY);
        parse_str((string) $next, $query);

        expect($first->json('meta.total'))->toBe(51)
            ->and($first->json('data'))->toHaveCount(50)
            ->and($query)->toBe(['q' => 'Électricité', 'page' => '2']);

        $second = inventoryCall($this, $team['viewer'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items?page=2&q=".rawurlencode('Électricité'))->assertOk();
        $previous = parse_url((string) $second->json('links.prev'), PHP_URL_QUERY);

        expect($second->json('data'))->toHaveCount(1)
            ->and($second->json("data.0.values.{$name->ulid}"))->toBe('Électricité 1')
            ->and((string) $previous)->toContain('q='.rawurlencode('Électricité'));
    });

    it('never gives the search text out', function (): void {
        ['team' => $team, 'inventory' => $inventory, 'name' => $name] = searchableInventory(['Électricité']);
        $item = Item::query()->sole();
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";

        $one = inventoryCall($this, $team['owner'], 'GET', "{$uri}/{$item->ulid}")->assertOk();
        $list = inventoryCall($this, $team['owner'], 'GET', "{$uri}?q=electricite")->assertOk();
        $created = inventoryCall($this, $team['owner'], 'POST', $uri, ['values' => [$name->ulid => 'Plomberie']])->assertCreated();

        expect(searchTextOf($item))->toBe('electricite')
            ->and(array_keys($one->json('data')))->toBe(['id', 'values', 'created_at', 'updated_at'])
            ->and(array_keys($list->json('data.0')))->toBe(['id', 'values', 'created_at', 'updated_at'])
            ->and(array_keys($created->json('data')))->toBe(['id', 'values', 'created_at', 'updated_at'])
            ->and($one->getContent())->not->toContain('search_text')->not->toContain('"electricite"')
            ->and($item->fresh()->toArray())->not->toHaveKey('search_text')
            ->and($item->fresh()->toJson())->not->toContain('search_text');
    });
});

describe('what is searched', function (): void {
    it('is the text of every type that holds text, and nothing of the others', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Everything');
        $options = [
            'text' => ['max_length' => 50],
            'number' => ['decimals' => 2, 'unit' => 'kg'],
            'money' => ['currency' => 'EUR'],
            'percentage' => ['decimals' => 1],
            'select' => ['choices' => [['label' => 'Nouveauté'], ['label' => 'Used']]],
            'multiselect' => ['choices' => [['label' => 'Rouge'], ['label' => 'Vert'], ['label' => 'Bleu']]],
            'rating' => ['max' => 5],
            'barcode' => ['format' => 'ean13'],
        ];
        $fields = [];

        foreach (app(FieldTypes::class)->keys() as $type) {
            $fields[$type] = fieldIn($inventory, $type, ucfirst($type), $options[$type] ?? []);
        }

        [$new] = array_column($fields['select']->options['choices'], 'id');
        [$red, , $blue] = array_column($fields['multiselect']->options['choices'], 'id');
        $sent = [
            'text' => ' Marteau ', 'textarea' => "Ligne 1\r\nLigne 2", 'number' => 8765.5, 'money' => '4321.9', 'percentage' => 7, 'boolean' => true,
            'date' => '2026-10-15', 'datetime' => '2026-10-15T14:30:00+02:00', 'time' => '08:05', 'select' => $new, 'multiselect' => [$blue, $red],
            'tags' => ['Outil', 'outil', ' À main '], 'url' => 'https://Example.com/marteau', 'email' => 'Boutique@example.com', 'phone' => '+33 1 23 45 67 89',
            'rating' => 4, 'color' => '#AABBCC', 'barcode' => '4006381333931',
        ];

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => array_combine(
            array_map(fn (string $type): string => $fields[$type]->ulid, array_keys($sent)),
            $sent,
        )])->assertCreated();

        $found = fn (string $typed): int => count(inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items?q=".rawurlencode($typed))->assertOk()->json('data'));

        // In the order of the fields, one part for each, parts joined by a line break; the labels, not the ids.
        expect(searchTextOf(Item::query()->sole()))->toBe(implode("\n", [
            'marteau', 'ligne 1 ligne 2', 'nouveaute', 'bleu rouge', 'outil a main',
            'https://example.com/marteau', 'boutique@example.com', '+33 1 23 45 67 89', '4006381333931',
        ]))
            ->and($found('marteau'))->toBe(1)
            ->and($found('ligne 2'))->toBe(1)
            ->and($found('NOUVEAUTÉ'))->toBe(1)
            ->and($found('rouge bleu'))->toBe(1)
            ->and($found('à main outil'))->toBe(1)
            ->and($found('EXAMPLE.com'))->toBe(1)
            ->and($found('boutique@'))->toBe(1)
            ->and($found('45 67'))->toBe(1)
            ->and($found('40063813'))->toBe(1)
            // The ids of the choices are not what is searched.
            ->and($found($new))->toBe(0)
            ->and($found('vert'))->toBe(0)
            // Neither are the numbers, the dates, the yes or no, the rating nor the color.
            ->and($found('8765'))->toBe(0)
            ->and($found('4321'))->toBe(0)
            ->and($found('2026'))->toBe(0)
            ->and($found('08:05'))->toBe(0)
            ->and($found('true'))->toBe(0)
            ->and($found('aabbcc'))->toBe(0)
            ->and($found('kg'))->toBe(0);
    });

    it('is what each type says of a stored value', function (string $type, array $options, mixed $stored, ?string $text): void {
        $field = searchFieldOf($type, $options);

        expect(app(FieldTypes::class)->get($type)->searchText($stored, $field))->toBe($text);
    })->with([
        'a text' => ['text', [], 'Hammer', 'Hammer'],
        'a text that is not a string' => ['text', [], 12, null],
        'a long text, with its lines' => ['textarea', [], "A\nB", "A\nB"],
        'a url' => ['url', [], 'https://example.com/a', 'https://example.com/a'],
        'an email address' => ['email', [], 'a@example.com', 'a@example.com'],
        'a phone number' => ['phone', [], '+33 1 23 45', '+33 1 23 45'],
        'a barcode' => ['barcode', [], 'ABC-123', 'ABC-123'],
        'tags' => ['tags', [], ['Tool', 'Hand tool'], 'Tool Hand tool'],
        'no tag' => ['tags', [], [], null],
        'tags that are not strings' => ['tags', [], [1, 'x', null], 'x'],
        'tags that are a string' => ['tags', [], 'x', null],
        'a number' => ['number', [], '12.50', null],
        'an amount' => ['money', ['currency' => 'EUR'], '19.90', null],
        'a percentage' => ['percentage', [], '7.0', null],
        'a yes' => ['boolean', [], true, null],
        'a date' => ['date', [], '2026-10-15', null],
        'a moment' => ['datetime', [], '2026-10-15T12:30:00Z', null],
        'a time' => ['time', [], '08:05', null],
        'a rating' => ['rating', ['max' => 5], 4, null],
        'a color' => ['color', [], '#aabbcc', null],
    ]);

    it('is the label of a choice, not its id', function (): void {
        $select = searchFieldOf('select', ['choices' => [['label' => 'Neuf'], ['label' => 'Occasion']]]);
        $multiselect = searchFieldOf('multiselect', ['choices' => [['label' => 'Rouge'], ['label' => 'Vert'], ['label' => 'Bleu']]]);
        [$new, $used] = array_column($select->options['choices'], 'id');
        [$red, $green, $blue] = array_column($multiselect->options['choices'], 'id');
        $types = app(FieldTypes::class);

        expect($types->get('select')->searchText($new, $select))->toBe('Neuf')
            ->and($types->get('select')->searchText($used, $select))->toBe('Occasion')
            // Gone, or not a choice: nothing.
            ->and($types->get('select')->searchText('gone0000', $select))->toBeNull()
            ->and($types->get('select')->searchText(['x'], $select))->toBeNull()
            ->and($types->get('select')->searchText(5, $select))->toBeNull()
            ->and($types->get('multiselect')->searchText([$blue, $red], $multiselect))->toBe('Bleu Rouge')
            ->and($types->get('multiselect')->searchText([$green, 'gone0000', 7], $multiselect))->toBe('Vert')
            ->and($types->get('multiselect')->searchText(['gone0000'], $multiselect))->toBeNull()
            ->and($types->get('multiselect')->searchText([], $multiselect))->toBeNull()
            ->and($types->get('multiselect')->searchText($blue, $multiselect))->toBeNull();
    });

    it('is built from the fields in order, skipping a value that no field owns', function (): void {
        $inventory = inventoryIn(inventoryTeam()['workspace']);
        $second = fieldIn($inventory, 'text', 'Second');
        $first = fieldIn($inventory, 'text', 'First');
        $number = fieldIn($inventory, 'number', 'Number');
        $fields = $inventory->fields()->get();
        $builder = app(ItemSearchText::class);

        expect($builder->build($fields, [$first->ulid => 'Été', $second->ulid => 'Pomme', $number->ulid => '5', '01j0000000000000000000000x' => 'stray']))->toBe("pomme\nete")
            ->and($builder->build($fields, [$number->ulid => '5']))->toBeNull()
            ->and($builder->build($fields, []))->toBeNull()
            ->and($builder->build($fields, [$first->ulid => '   ']))->toBeNull();
    });
});

describe('the search text of an item', function (): void {
    it('follows the changes made to the item', function (): void {
        ['team' => $team, 'inventory' => $inventory, 'name' => $name] = searchableInventory(['Électricité']);
        $notes = fieldIn($inventory, 'text', 'Notes');
        $item = Item::query()->sole();
        $uri = "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}";

        inventoryCall($this, $team['editor'], 'PATCH', $uri, ['values' => [$notes->ulid => 'Prise murale']])->assertOk();
        expect(searchTextOf($item))->toBe("electricite\nprise murale")
            ->and(searchFinds($this, $team['viewer'], $inventory, 'prise', $name))->toBe(['Électricité']);

        inventoryCall($this, $team['editor'], 'PATCH', $uri, ['values' => [$name->ulid => 'Plomberie']])->assertOk();
        expect(searchTextOf($item))->toBe("plomberie\nprise murale")
            ->and(searchFinds($this, $team['viewer'], $inventory, 'electricite', $name))->toBe([])
            ->and(searchFinds($this, $team['viewer'], $inventory, 'plomberie prise', $name))->toBe(['Plomberie']);

        // Clearing a value takes its words out; clearing the last one leaves no text.
        inventoryCall($this, $team['editor'], 'PATCH', $uri, ['values' => [$name->ulid => null]])->assertOk();
        expect(searchTextOf($item))->toBe('prise murale');

        inventoryCall($this, $team['editor'], 'PATCH', $uri, ['values' => [$notes->ulid => '']])->assertOk();
        expect(searchTextOf($item))->toBeNull()
            ->and(searchFinds($this, $team['viewer'], $inventory, 'prise', $name))->toBe([]);
    });

    it('is written with the item when it is added through the service', function (): void {
        ['inventory' => $inventory, 'name' => $name] = searchableInventory([]);

        $item = itemIn($inventory, [$name->ulid => 'Ŕéßumé']);

        expect(searchTextOf($item))->toBe('ressume')->and($item->search_text)->toBe('ressume');
    });

    it('is not written when an update changes nothing', function (): void {
        ['team' => $team, 'inventory' => $inventory, 'name' => $name] = searchableInventory(['Hammer']);
        $item = Item::query()->sole();
        DB::table('items')->where('id', $item->id)->update(['search_text' => 'left alone', 'updated_at' => '2020-01-01 00:00:00']);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}", ['values' => [$name->ulid => 'Hammer']])->assertOk();

        expect(searchTextOf($item))->toBe('left alone')
            ->and((string) DB::table('items')->where('id', $item->id)->value('updated_at'))->toBe('2020-01-01 00:00:00');
    });
});

describe('the search text when the fields change', function (): void {
    /**
     * An inventory with a name and a choice field, and one item for each choice and one with a name only; their dates set
     * far back to show they are not touched.
     *
     * @param  'select'|'multiselect'  $type
     * @return array{team: array<string, mixed>, inventory: Inventory, name: InventoryField, field: InventoryField, ids: list<string>, items: list<Item>}
     */
    function searchChoiceInventory(string $type): array
    {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $field = fieldIn($inventory, $type, 'Condition', ['choices' => [['label' => 'New'], ['label' => 'Used'], ['label' => 'Broken']]]);
        [$new, $used] = $ids = array_column($field->options['choices'], 'id');
        $wrap = fn (string $id): string|array => $type === 'select' ? $id : [$id];
        $items = [
            itemIn($inventory, [$name->ulid => 'Hammer', $field->ulid => $wrap($new)]),
            itemIn($inventory, [$name->ulid => 'Saw', $field->ulid => $wrap($used)]),
            itemIn($inventory, [$name->ulid => 'Plane']),
        ];
        DB::table('items')->update(['updated_at' => '2020-01-01 00:00:00']);

        return ['team' => $team, 'inventory' => $inventory, 'name' => $name, 'field' => $field, 'ids' => $ids, 'items' => $items];
    }

    it('finds an item by the new label of a choice that was renamed, and no longer by the old one', function (string $type): void {
        ['team' => $team, 'inventory' => $inventory, 'name' => $name, 'field' => $field, 'ids' => [$new, $used, $broken], 'items' => $items] = searchChoiceInventory($type);

        expect(searchFinds($this, $team['viewer'], $inventory, 'new', $name))->toBe(['Hammer'])
            ->and(searchFinds($this, $team['viewer'], $inventory, 'used', $name))->toBe(['Saw']);

        inventoryCall($this, $team['editor'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}", ['options' => ['choices' => [
            ['id' => $new, 'label' => 'Neuf à moitié'], ['id' => $used, 'label' => 'Used'], ['id' => $broken, 'label' => 'Broken'],
        ]]])->assertOk();

        expect(searchFinds($this, $team['viewer'], $inventory, 'new', $name))->toBe([])
            ->and(searchFinds($this, $team['viewer'], $inventory, 'NEUF moitie', $name))->toBe(['Hammer'])
            ->and(searchFinds($this, $team['viewer'], $inventory, 'used', $name))->toBe(['Saw'])
            ->and(searchTextOf($items[0]))->toBe("hammer\nneuf a moitie")
            ->and(searchTextOf($items[1]))->toBe("saw\nused")
            ->and(searchTextOf($items[2]))->toBe('plane')
            // The items did not change: they keep their dates.
            ->and(DB::table('items')->where('updated_at', '!=', '2020-01-01 00:00:00')->count())->toBe(0);
    })->with(['a choice' => 'select', 'a multiple choice' => 'multiselect']);

    it('no longer finds an item by a choice that was deleted', function (string $type): void {
        ['team' => $team, 'inventory' => $inventory, 'name' => $name, 'field' => $field, 'ids' => [$new], 'items' => $items] = searchChoiceInventory($type);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}", ['options' => ['choices' => [['id' => $new, 'label' => 'New']]]])->assertOk();

        expect(searchFinds($this, $team['viewer'], $inventory, 'used', $name))->toBe([])
            ->and(searchFinds($this, $team['viewer'], $inventory, 'new', $name))->toBe(['Hammer'])
            ->and(searchTextOf($items[0]))->toBe("hammer\nnew")
            ->and(searchTextOf($items[1]))->toBe('saw')
            ->and(DB::table('items')->where('updated_at', '!=', '2020-01-01 00:00:00')->count())->toBe(0);
    })->with(['a choice' => 'select', 'a multiple choice' => 'multiselect']);

    it('leaves the search text alone when a change cannot alter it', function (): void {
        ['team' => $team, 'inventory' => $inventory, 'field' => $field, 'ids' => [$new, $used, $broken]] = searchChoiceInventory('select');
        DB::table('items')->update(['search_text' => 'untouched']);

        // A color, a new choice, another order, a new name: nothing a value says changes.
        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}", [
            'name' => 'State',
            'options' => ['choices' => [['id' => $broken, 'label' => 'Broken'], ['id' => $new, 'label' => 'New', 'color' => 'green'], ['id' => $used, 'label' => 'Used'], ['label' => 'Lost']]],
        ])->assertOk();

        expect(DB::table('items')->where('search_text', '!=', 'untouched')->count())->toBe(0);
    });

    it('takes the words of a deleted field out of the items that held it, and only theirs', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $notes = fieldIn($inventory, 'text', 'Notes');
        $tags = fieldIn($inventory, 'tags', 'Tags');
        $held = itemIn($inventory, [$name->ulid => 'Hammer', $notes->ulid => 'Fragile', $tags->ulid => ['Outil', 'Métal']]);
        $other = itemIn($inventory, [$name->ulid => 'Saw']);
        DB::table('items')->update(['updated_at' => '2020-01-01 00:00:00']);

        expect(searchFinds($this, $team['viewer'], $inventory, 'fragile', $name))->toBe(['Hammer']);

        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/inventories/{$inventory->ulid}/fields/{$notes->ulid}")->assertNoContent();

        expect(searchFinds($this, $team['viewer'], $inventory, 'fragile', $name))->toBe([])
            ->and(searchFinds($this, $team['viewer'], $inventory, 'metal hammer', $name))->toBe(['Hammer'])
            ->and(searchTextOf($held))->toBe("hammer\noutil metal")
            ->and(searchTextOf($other))->toBe('saw');

        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/inventories/{$inventory->ulid}/fields/{$tags->ulid}")->assertNoContent();
        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/inventories/{$inventory->ulid}/fields/{$name->ulid}")->assertNoContent();

        expect(searchFinds($this, $team['viewer'], $inventory, 'hammer', $name))->toBe([])
            ->and(searchTextOf($held))->toBeNull()
            ->and(searchTextOf($other))->toBeNull()
            ->and(DB::table('items')->where('updated_at', '!=', '2020-01-01 00:00:00')->count())->toBe(0);
    });

    it('rebuilds the text of the items that held a deleted field, even when it was never written', function (): void {
        // Items written without their text: deleting a field fixes the ones that held it.
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $notes = fieldIn($inventory, 'text', 'Notes');
        $item = Item::factory()->for($inventory)->create(['values' => [$name->ulid => 'Hammer', $notes->ulid => 'Fragile']]);

        expect(searchTextOf($item))->toBeNull();

        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/inventories/{$inventory->ulid}/fields/{$notes->ulid}")->assertNoContent();

        expect(searchTextOf($item))->toBe('hammer');
    });

    it('does not move when the fields are put in another order', function (): void {
        ['team' => $team, 'inventory' => $inventory, 'name' => $name, 'field' => $field, 'items' => $items] = searchChoiceInventory('select');

        inventoryCall($this, $team['owner'], 'PUT', "/api/v1/inventories/{$inventory->ulid}/fields/order", ['fields' => [$field->ulid, $name->ulid]])->assertOk();

        expect(searchFinds($this, $team['viewer'], $inventory, 'hammer new', $name))->toBe(['Hammer'])
            ->and(searchTextOf($items[0]))->toBe("hammer\nnew");
    });
});

describe('a choice field and its options', function (): void {
    it('may change the search text only when a choice is renamed or deleted', function (array $after, bool $changes): void {
        $field = searchFieldOf('select', ['choices' => [['label' => 'New'], ['label' => 'Used']]]);
        [$new, $used] = array_column($field->options['choices'], 'id');
        $handler = app(FieldTypes::class)->get('select');
        $after = array_map(fn (array $choice): array => ['id' => str_replace(['@new', '@used'], [$new, $used], $choice['id'])] + $choice, $after);

        expect($handler->mayChangeSearchText($field->options, ['choices' => $after]))->toBe($changes);
    })->with([
        'the same' => [[['id' => '@new', 'label' => 'New'], ['id' => '@used', 'label' => 'Used']], false],
        'a renamed choice' => [[['id' => '@new', 'label' => 'Brand new'], ['id' => '@used', 'label' => 'Used']], true],
        'a deleted choice' => [[['id' => '@new', 'label' => 'New']], true],
        'a new choice' => [[['id' => '@new', 'label' => 'New'], ['id' => '@used', 'label' => 'Used'], ['id' => 'zzzzzzzz', 'label' => 'Lost']], false],
        'another color' => [[['id' => '@new', 'label' => 'New', 'color' => 'red'], ['id' => '@used', 'label' => 'Used']], false],
        'another order' => [[['id' => '@used', 'label' => 'Used'], ['id' => '@new', 'label' => 'New']], false],
    ]);

    it('is not changed by the options of the other types', function (string $type, array $before, array $after): void {
        $handler = app(FieldTypes::class)->get($type);

        expect($handler->mayChangeSearchText($before, $after))->toBeFalse();
    })->with([
        'a text' => ['text', ['max_length' => 10], ['max_length' => 20]],
        'a barcode' => ['barcode', ['format' => 'any'], ['format' => 'ean13']],
        'a rating' => ['rating', ['max' => 5], ['max' => 3]],
    ]);
});

describe('rebuilding the search text of an inventory', function (): void {
    it('writes only the items whose text is not the right one, and leaves their dates alone', function (): void {
        ['team' => $team, 'inventory' => $inventory] = searchableInventory(['Électricité', 'Plomberie', 'Œuvre', 'Hammer', 'Saw']);
        $other = inventoryIn($team['workspace'], 'Other');
        $foreign = itemIn($other, [fieldIn($other, 'text', 'Name')->ulid => 'Chauffage']);
        $items = Item::query()->where('inventory_id', $inventory->id)->orderBy('id')->get();
        DB::table('items')->update(['updated_at' => '2020-01-01 00:00:00']);
        DB::table('items')->where('id', $items[0]->id)->update(['search_text' => null]);
        DB::table('items')->where('id', $items[2]->id)->update(['search_text' => 'wrong']);
        DB::table('items')->where('id', $foreign->id)->update(['search_text' => null]);

        DB::enableQueryLog();
        app(ItemSearchText::class)->refresh($inventory, $inventory->fields()->get());
        $updates = array_filter(DB::getQueryLog(), fn (array $query): bool => stripos($query['query'], 'update') === 0);
        DB::disableQueryLog();

        expect(count($updates))->toBe(2)
            ->and(Item::query()->where('inventory_id', $inventory->id)->orderBy('id')->get()->map(fn (Item $item): ?string => searchTextOf($item))->all())
            ->toBe(['electricite', 'plomberie', 'oeuvre', 'hammer', 'saw'])
            // The inventory that was not asked for is not touched.
            ->and(searchTextOf($foreign))->toBeNull()
            ->and(DB::table('items')->where('updated_at', '!=', '2020-01-01 00:00:00')->count())->toBe(0);
    });
});
