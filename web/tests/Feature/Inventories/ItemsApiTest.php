<?php

declare(strict_types=1);

use App\Inventories\Fields\FieldTypes;
use App\Inventories\InventoryFields;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/**
 * An inventory with a field of every type, and values for each of them that the application accepts, with what it gives
 * back for them.
 *
 * @return array{inventory: Inventory, fields: array<string, InventoryField>, sent: array<string, mixed>, read: array<string, mixed>}
 */
function itemInventoryOfEveryType(Model $workspace): array
{
    $inventory = inventoryIn($workspace, 'Everything');
    $options = [
        'text' => ['max_length' => 50],
        'number' => ['decimals' => 2, 'unit' => 'kg'],
        'money' => ['currency' => 'EUR'],
        'percentage' => ['decimals' => 1],
        'select' => ['choices' => [['label' => 'New'], ['label' => 'Used']]],
        'multiselect' => ['choices' => [['label' => 'Red'], ['label' => 'Green'], ['label' => 'Blue']]],
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
        'text' => ' Hammer ', 'textarea' => "Line 1\r\nLine 2", 'number' => 12.5, 'money' => '19.9', 'percentage' => 7, 'boolean' => false,
        'date' => '2026-10-15', 'datetime' => '2026-10-15T14:30:00+02:00', 'time' => '08:05', 'select' => $new, 'multiselect' => [$blue, $red, $blue],
        'tags' => ['Tool', 'tool', ' Hand '], 'url' => 'https://example.com/hammer', 'email' => 'shop@example.com', 'phone' => '+33 1 23 45 67 89',
        'rating' => 4, 'color' => '#AABBCC', 'barcode' => '4006381333931',
    ];
    $read = [
        'text' => 'Hammer', 'textarea' => "Line 1\nLine 2", 'number' => '12.50', 'money' => '19.90', 'percentage' => '7.0', 'boolean' => false,
        'date' => '2026-10-15', 'datetime' => '2026-10-15T12:30:00Z', 'time' => '08:05', 'select' => $new, 'multiselect' => [$blue, $red],
        'tags' => ['Tool', 'Hand'], 'url' => 'https://example.com/hammer', 'email' => 'shop@example.com', 'phone' => '+33 1 23 45 67 89',
        'rating' => 4, 'color' => '#aabbcc', 'barcode' => '4006381333931',
    ];

    return [
        'inventory' => $inventory,
        'fields' => $fields,
        'sent' => array_combine(array_map(fn (string $type): string => $fields[$type]->ulid, array_keys($sent)), $sent),
        'read' => array_combine(array_map(fn (string $type): string => $fields[$type]->ulid, array_keys($read)), $read),
    ];
}

describe('adding an item', function (): void {
    it('stores a value of every type in its canonical form and gives it back', function (): void {
        $team = inventoryTeam();
        $everything = itemInventoryOfEveryType($team['workspace']);

        $response = inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$everything['inventory']->ulid}/items", ['values' => $everything['sent']])->assertCreated();

        $item = Item::query()->sole();

        expect($response->json('data.id'))->toBe($item->ulid)
            ->and($response->json('data.values'))->toBe($everything['read'])
            ->and(array_keys($response->json('data')))->toBe(['id', 'values', 'created_at', 'updated_at'])
            ->and($item->values)->toEqual($everything['read'])
            // What is stored: decimal strings, not numbers; a real false; the moment in UTC.
            ->and($item->values[$everything['fields']['number']->ulid])->toBe('12.50')
            ->and($item->values[$everything['fields']['boolean']->ulid])->toBeFalse();

        // And a read gives the same.
        inventoryCall($this, $team['viewer'], 'GET', "/api/v1/inventories/{$everything['inventory']->ulid}/items/{$item->ulid}")->assertOk()->assertJsonPath('data.values', $everything['read']);
    });

    it('is for owners and editors, who are recorded as its author', function (string $role): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');

        inventoryCall($this, $team[$role], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => [$name->ulid => 'Hammer']])->assertCreated();

        $item = Item::query()->sole();

        expect($item->created_by)->toBe($team[$role]->id)
            ->and($item->updated_by)->toBe($team[$role]->id)
            ->and($item->inventory_id)->toBe($inventory->id);
    })->with(['an owner' => 'owner', 'an editor' => 'editor']);

    it('is refused to a viewer (403) and a stranger (404), who learn nothing from the validation', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        fieldIn($inventory, 'text', 'Name', required: true);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";

        inventoryCall($this, $team['viewer'], 'POST', $uri, ['values' => []])->assertForbidden();
        inventoryCall($this, $team['stranger'], 'POST', $uri, ['values' => []])->assertNotFound();

        expect(Item::query()->count())->toBe(0);
    });

    it('may have no value at all, and then holds an empty object, not an empty list', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";

        foreach ([[], ['values' => []], ['values' => null]] as $body) {
            $response = inventoryCall($this, $team['owner'], 'POST', $uri, $body)->assertCreated();

            expect($response->getContent())->toContain('"values":{}');
        }

        inventoryCall($this, $team['owner'], 'GET', $uri)->assertOk();
        expect(inventoryCall($this, $team['owner'], 'GET', $uri)->getContent())->toContain('"values":{}');
    });

    it('finds the field of a key whatever its case, and stores the value under the lowercase key', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name', required: true);
        $weight = fieldIn($inventory, 'number', 'Weight');
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";

        $created = inventoryCall($this, $team['owner'], 'POST', $uri, ['values' => [strtoupper($name->ulid) => 'Hammer', strtoupper($weight->ulid) => '2']])->assertCreated();

        expect($created->json('data.values'))->toBe([$name->ulid => 'Hammer', $weight->ulid => '2']);

        $updated = inventoryCall($this, $team['owner'], 'PATCH', "{$uri}/".$created->json('data.id'), ['values' => [strtoupper($weight->ulid) => null, strtoupper($name->ulid) => 'Saw']])->assertOk();

        expect($updated->json('data.values'))->toBe([$name->ulid => 'Saw'])
            ->and(Item::query()->sole()->values)->toBe([$name->ulid => 'Saw']);

        // The key is the field's, not a lookalike: a required field is still required in capitals.
        inventoryCall($this, $team['owner'], 'PATCH', "{$uri}/".$created->json('data.id'), ['values' => [strtoupper($name->ulid) => null]])->assertUnprocessable()->assertJsonValidationErrors("values.{$name->ulid}");
    });

    it('refuses a key that is no field of the inventory, and writes nothing', function (string $key): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $foreign = fieldIn(inventoryIn($team['workspace'], 'Other'), 'text', 'Name');
        $key = $key === 'foreign' ? $foreign->ulid : ($key === 'numeric' ? '0' : $key);

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => [$name->ulid => 'Hammer', $key => 'x']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors("values.{$key}");

        expect(Item::query()->count())->toBe(0);
    })->with([
        'a ULID nobody has' => '01j0000000000000000000000x',
        'a field of another inventory' => 'foreign',
        'a name' => 'Name',
        'a number' => 'numeric',
    ]);

    it('names the unknown keys, at most twenty of them', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $response = inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => array_fill_keys(array_map(fn (int $number): string => "key{$number}", range(1, 30)), 'x')], ['Accept-Language' => 'en'])
            ->assertUnprocessable();

        expect($response->json('errors'))->toHaveCount(20)
            ->and($response->json('errors')['values.key1'])->toBe(['This field does not exist in this inventory.']);
    });

    it('refuses values that are not an object', function (mixed $values): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => $values])->assertUnprocessable()->assertJsonValidationErrors('values');
    })->with(['text' => 'abc', 'a number' => 12, 'true' => true]);

    it('refuses a list of values, whose keys are not fields', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => ['Hammer', 'Saw']])->assertUnprocessable()->assertJsonValidationErrors(['values.0', 'values.1']);
    });

    it('refuses a value the field does not accept, in words that name the field', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $price = fieldIn($inventory, 'money', 'Purchase price');
        $code = fieldIn($inventory, 'barcode', 'Code', ['format' => 'ean13']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";
        $values = [$price->ulid => '12.345', $code->ulid => '4006381333932'];

        $english = inventoryCall($this, $team['owner'], 'POST', $uri, ['values' => $values], ['Accept-Language' => 'en'])->assertUnprocessable();
        $french = inventoryCall($this, $team['owner'], 'POST', $uri, ['values' => $values], ['Accept-Language' => 'fr'])->assertUnprocessable();

        expect($english->json('errors'))->toBe([
            "values.{$price->ulid}" => ['The Purchase price must have at most 2 decimals.'],
            "values.{$code->ulid}" => ['The Code must be a valid EAN-13 code: 13 digits, the last one a check digit.'],
        ])->and($french->json('errors')["values.{$price->ulid}"][0])->toBe('Le champ Purchase price doit avoir au plus 2 décimales.')
            ->and($english->json('message'))->toBe('The Purchase price must have at most 2 decimals. (and 1 more error)');

        expect(Item::query()->count())->toBe(0);
    });

    it('stops at the first problem of a value', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $link = fieldIn($inventory, 'url', 'Link');

        $response = inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => [$link->ulid => 12]], ['Accept-Language' => 'en'])->assertUnprocessable();

        expect($response->json('errors')["values.{$link->ulid}"])->toHaveCount(1);
    });

    it('needs a value for a required field, however it is left out', function (mixed $sent): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name', required: true);
        $body = $sent === 'left out' ? ['values' => []] : ['values' => [$name->ulid => $sent]];

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", $body, ['Accept-Language' => 'en'])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ["values.{$name->ulid}" => ['The Name field is required.']]);

        expect(Item::query()->count())->toBe(0);
    })->with(['left out' => 'left out', 'null' => null, 'an empty text' => '', 'spaces' => '   ']);

    it('takes false and zero as values of a required field', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $flag = fieldIn($inventory, 'boolean', 'In stock', required: true);
        $count = fieldIn($inventory, 'number', 'Count', required: true);

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => [$flag->ulid => false, $count->ulid => 0]])
            ->assertCreated()
            ->assertJsonPath('data.values', [$flag->ulid => false, $count->ulid => '0']);
    });

    it('does not store an empty value of a field that is not required', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $tags = fieldIn($inventory, 'tags', 'Tags');
        $weight = fieldIn($inventory, 'number', 'Weight');

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => [$name->ulid => '  ', $tags->ulid => [], $weight->ulid => null]])->assertCreated();

        expect(DB::table('items')->value('values'))->not->toContain($name->ulid)
            ->and(Item::query()->sole()->values)->toBe([]);
    });

    it('is refused beyond the quota of the inventory, with a code and the limit (409)', function (): void {
        config(['inventories.max_items' => 2]);
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";

        inventoryCall($this, $team['owner'], 'POST', $uri)->assertCreated();
        inventoryCall($this, $team['editor'], 'POST', $uri)->assertCreated();
        inventoryCall($this, $team['owner'], 'POST', $uri, [], ['Accept-Language' => 'en'])
            ->assertStatus(409)
            ->assertJson(['code' => 'item_limit_reached', 'limit' => 2, 'message' => 'An inventory cannot hold more than 2 items.']);

        // Another inventory has its own quota; deleting an item frees a place.
        inventoryCall($this, $team['owner'], 'POST', '/api/v1/inventories/'.inventoryIn($team['workspace'], 'Other')->ulid.'/items')->assertCreated();
        inventoryCall($this, $team['owner'], 'DELETE', "{$uri}/".Item::query()->where('inventory_id', $inventory->id)->first()->ulid)->assertNoContent();
        inventoryCall($this, $team['owner'], 'POST', $uri)->assertCreated();

        expect(Item::query()->where('inventory_id', $inventory->id)->count())->toBe(2);
    });

    it('is checked for the quota before the values', function (): void {
        config(['inventories.max_items' => 0]);
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => ['nope' => 'x']])->assertStatus(409);
    });
});

describe('reading items', function (): void {
    it('shows one to every member, and a 404 to a stranger', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $item = itemIn($inventory, [$name->ulid => 'Hammer']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}";

        foreach (['owner', 'editor', 'viewer'] as $role) {
            inventoryCall($this, $team[$role], 'GET', $uri)->assertOk()->assertExactJson(['data' => [
                'id' => $item->ulid,
                'values' => [$name->ulid => 'Hammer'],
                'created_at' => $item->created_at->toIso8601String(),
                'updated_at' => $item->updated_at->toIso8601String(),
            ]]);
        }

        inventoryCall($this, $team['stranger'], 'GET', $uri)->assertNotFound();
    });

    it('lists fifty a page, newest first, with the total and the links', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');

        foreach (range(1, 51) as $number) {
            Item::factory()->for($inventory)->create(['values' => [$name->ulid => "Item {$number}"]]);
        }

        Item::factory()->create();

        $first = inventoryCall($this, $team['viewer'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items")->assertOk();
        $second = inventoryCall($this, $team['viewer'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items?page=2")->assertOk();

        expect($first->json('data'))->toHaveCount(50)
            ->and($first->json("data.0.values.{$name->ulid}"))->toBe('Item 51')
            ->and($first->json("data.49.values.{$name->ulid}"))->toBe('Item 2')
            ->and($first->json('meta.total'))->toBe(51)
            ->and($first->json('meta.per_page'))->toBe(50)
            ->and($first->json('meta.last_page'))->toBe(2)
            ->and($first->json('links.next'))->not->toBeNull()
            ->and($second->json('data'))->toHaveCount(1)
            ->and($second->json("data.0.values.{$name->ulid}"))->toBe('Item 1');
    });

    it('answers an empty page for an empty inventory and past the last page', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items")->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);

        itemIn($inventory);
        inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items?page=9")->assertOk()->assertJsonPath('data', []);
    });

    it('refuses a page that is not a number from 1 to a million', function (string $page): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items?page={$page}")->assertUnprocessable()->assertJsonValidationErrors('page');
    })->with(['zero' => '0', 'negative' => '-1', 'text' => 'abc', 'too far' => '1000001']);

    it('is a 404 for a stranger, and shows nothing of another inventory', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        itemIn(inventoryIn($team['workspace'], 'Other'));

        inventoryCall($this, $team['stranger'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items")->assertNotFound();
        inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items")->assertOk()->assertJsonPath('data', []);
    });

    it('gives the values in the order of the fields, and only the ones the item has', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $a = fieldIn($inventory, 'text', 'A');
        $b = fieldIn($inventory, 'text', 'B');
        $c = fieldIn($inventory, 'text', 'C');
        $item = itemIn($inventory, [$c->ulid => 'c', $a->ulid => 'a']);
        inventoryCall($this, $team['owner'], 'PUT', "/api/v1/inventories/{$inventory->ulid}/fields/order", ['fields' => [$c->ulid, $b->ulid, $a->ulid]])->assertOk();

        $values = inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}")->json('data.values');

        expect(array_keys($values))->toBe([$c->ulid, $a->ulid]);
    });

    it('never gives back a value whose field is gone, even one left in the row', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $item = itemIn($inventory, [$name->ulid => 'Hammer']);
        DB::table('items')->where('id', $item->id)->update(['values' => json_encode([$name->ulid => 'Hammer', '01j0000000000000000000000x' => 'stray'])]);

        $one = inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}");
        $many = inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items");

        expect($one->json('data.values'))->toBe([$name->ulid => 'Hammer'])
            ->and($many->json('data.0.values'))->toBe([$name->ulid => 'Hammer'])
            ->and($one->getContent())->not->toContain('stray');
    });

    it('drops the choices that were deleted from their field, and does not bring them back with a choice of the same name', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $condition = fieldIn($inventory, 'select', 'Condition', ['choices' => [['label' => 'New'], ['label' => 'Used']]]);
        $colors = fieldIn($inventory, 'multiselect', 'Colors', ['choices' => [['label' => 'Red'], ['label' => 'Green'], ['label' => 'Blue']]]);
        [$new, $used] = array_column($condition->options['choices'], 'id');
        [$red, $green, $blue] = array_column($colors->options['choices'], 'id');
        $item = itemIn($inventory, [$condition->ulid => $used, $colors->ulid => [$red, $green, $blue]]);
        $itemUri = "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}";
        $fieldUri = fn ($field): string => "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}";

        // "Used" is deleted from the select, "Green" from the multiple choice.
        inventoryCall($this, $team['owner'], 'PATCH', $fieldUri($condition), ['options' => ['choices' => [['id' => $new, 'label' => 'New']]]])->assertOk();
        inventoryCall($this, $team['owner'], 'PATCH', $fieldUri($colors), ['options' => ['choices' => [['id' => $red, 'label' => 'Red'], ['id' => $blue, 'label' => 'Blue']]]])->assertOk();

        expect(inventoryCall($this, $team['owner'], 'GET', $itemUri)->json('data.values'))->toBe([$colors->ulid => [$red, $blue]]);

        // The choice comes back under its old name, and under its old id: it is a new choice, the item has no value for it.
        $back = inventoryCall($this, $team['owner'], 'PATCH', $fieldUri($condition), ['options' => ['choices' => [['id' => $new, 'label' => 'New'], ['id' => $used, 'label' => 'Used']]]])->assertOk();

        expect($back->json('data.options.choices.1.id'))->not->toBe($used)
            ->and(inventoryCall($this, $team['owner'], 'GET', $itemUri)->json('data.values'))->toBe([$colors->ulid => [$red, $blue]]);

        // The ids of the deleted choices were removed from the item when the choices were deleted, not just hidden: the
        // choice that came back under its old name has a new id and nothing in the item points to it.
        expect($item->fresh()->values)->toEqual([$colors->ulid => [$red, $blue]]);
    });

    it('still hides a value of a choice that is gone and a grade that is too high, if a row holds one however it got there', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $condition = fieldIn($inventory, 'select', 'Condition', ['choices' => [['label' => 'New']]]);
        $colors = fieldIn($inventory, 'multiselect', 'Colors', ['choices' => [['label' => 'Red']]]);
        $quality = fieldIn($inventory, 'rating', 'Quality', ['max' => 3]);
        [$red] = array_column($colors->options['choices'], 'id');
        $item = itemIn($inventory);
        DB::table('items')->where('id', $item->id)->update(['values' => json_encode([$condition->ulid => 'gone0000', $colors->ulid => ['gone0000', $red], $quality->ulid => 5])]);

        $read = inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}")->assertOk();

        expect($read->json('data.values'))->toBe([$colors->ulid => [$red]]);
    });

    it('omits a select whose only value is a deleted choice, and a multiple choice left empty', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $condition = fieldIn($inventory, 'select', 'Condition', ['choices' => [['label' => 'New'], ['label' => 'Used']]]);
        [$new, $used] = array_column($condition->options['choices'], 'id');
        $item = itemIn($inventory, [$condition->ulid => $used]);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$condition->ulid}", ['options' => ['choices' => [['id' => $new, 'label' => 'New']]]])->assertOk();

        $response = inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}");

        expect($response->getContent())->toContain('"values":{}');
    });
});

describe('changing an item', function (): void {
    it('changes only the keys that are sent', function (string $role): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $weight = fieldIn($inventory, 'number', 'Weight', ['decimals' => 1]);
        $tags = fieldIn($inventory, 'tags', 'Tags');
        $item = itemIn($inventory, [$name->ulid => 'Hammer', $weight->ulid => '2.5', $tags->ulid => ['tool']]);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}";

        $response = inventoryCall($this, $team[$role], 'PATCH', $uri, ['values' => [$weight->ulid => 3]])->assertOk();

        expect($response->json('data.values'))->toBe([$name->ulid => 'Hammer', $weight->ulid => '3.0', $tags->ulid => ['tool']])
            ->and($item->fresh()->values)->toEqual([$name->ulid => 'Hammer', $weight->ulid => '3.0', $tags->ulid => ['tool']])
            ->and($item->fresh()->updated_by)->toBe($team[$role]->id);
    })->with(['an owner' => 'owner', 'an editor' => 'editor']);

    it('clears a field that is sent empty, however it is empty', function (mixed $empty): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $tags = fieldIn($inventory, 'tags', 'Tags');
        $item = itemIn($inventory, [$name->ulid => 'Hammer', $tags->ulid => ['tool']]);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}", ['values' => [$name->ulid => $empty, $tags->ulid => $empty]])->assertOk()->assertJsonPath('data.values', []);

        expect($item->fresh()->values)->toBe([]);
    })->with(['null' => null, 'an empty text' => '', 'spaces' => '  ']);

    it('clears a list with an empty list', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $tags = fieldIn($inventory, 'tags', 'Tags');
        $item = itemIn($inventory, [$tags->ulid => ['tool']]);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}", ['values' => [$tags->ulid => []]])->assertOk();

        expect($item->fresh()->values)->toBe([]);
    });

    it('keeps false and zero, which are values', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $flag = fieldIn($inventory, 'boolean', 'In stock');
        $count = fieldIn($inventory, 'number', 'Count');
        $item = itemIn($inventory, [$flag->ulid => true, $count->ulid => 5]);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}", ['values' => [$flag->ulid => false, $count->ulid => 0]])->assertOk();

        expect($item->fresh()->values)->toBe([$flag->ulid => false, $count->ulid => '0']);
    });

    it('cannot clear a required field, but leaves alone one it does not mention', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $weight = fieldIn($inventory, 'number', 'Weight');
        $old = itemIn($inventory, [$weight->ulid => '1']);
        $item = itemIn($inventory, [$name->ulid => 'Hammer']);
        // The field becomes required after the items were written: the item that has no value for it stays valid until the key is sent.
        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$name->ulid}", ['required' => true])->assertOk();

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/items/{$old->ulid}", ['values' => [$weight->ulid => '2']])->assertOk();
        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/items/{$old->ulid}", ['values' => [$name->ulid => null]], ['Accept-Language' => 'en'])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ["values.{$name->ulid}" => ['The Name field is required.']]);
        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}", ['values' => [$name->ulid => '']])->assertUnprocessable();

        expect($old->fresh()->values)->toEqual([$weight->ulid => '2'])
            ->and($item->fresh()->values)->toBe([$name->ulid => 'Hammer']);
    });

    it('refuses an unknown key or a refused value, and changes nothing', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $weight = fieldIn($inventory, 'number', 'Weight');
        $item = itemIn($inventory, [$name->ulid => 'Hammer']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}";

        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['values' => [$name->ulid => 'Saw', 'nope' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('values.nope');
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['values' => [$name->ulid => 'Saw', $weight->ulid => 'heavy']])->assertUnprocessable()->assertJsonValidationErrors("values.{$weight->ulid}");

        expect($item->fresh()->values)->toBe([$name->ulid => 'Hammer']);
    });

    it('needs values to change', function (array $body): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $item = itemIn($inventory);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}", $body)->assertUnprocessable()->assertJsonValidationErrors('values');
    })->with(['nothing' => [[]], 'null' => [['values' => null]], 'text' => [['values' => 'x']]]);

    it('writes nothing when nothing changes: the item keeps its dates and its editor', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $weight = fieldIn($inventory, 'number', 'Weight', ['decimals' => 2]);
        $item = itemIn($inventory, [$name->ulid => 'Hammer', $weight->ulid => '2.50'], $team['owner']);
        $this->travel(10)->minutes();
        $uri = "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}";

        // The same values, said another way ("2.5" is "2.50" for a number of two decimals), and an empty one for a field it has not.
        inventoryCall($this, $team['editor'], 'PATCH', $uri, ['values' => [$name->ulid => 'Hammer', $weight->ulid => 2.5]])->assertOk();
        inventoryCall($this, $team['editor'], 'PATCH', $uri, ['values' => []])->assertOk();

        $unchanged = $item->fresh();
        expect($unchanged->updated_at->equalTo($item->updated_at))->toBeTrue()
            ->and($unchanged->updated_by)->toBe($team['owner']->id);

        // A real change moves both.
        inventoryCall($this, $team['editor'], 'PATCH', $uri, ['values' => [$name->ulid => 'Saw']])->assertOk();

        $changed = $item->fresh();
        expect($changed->updated_at->greaterThan($item->updated_at))->toBeTrue()
            ->and($changed->updated_by)->toBe($team['editor']->id)
            ->and($changed->created_by)->toBe($team['owner']->id);
    });

    it('does not take "12.50" for "12.5": a number stored with its decimals is compared as text', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $money = fieldIn($inventory, 'money', 'Price');
        $item = itemIn($inventory, [$money->ulid => '12.50']);

        $response = inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}", ['values' => [$money->ulid => '12.5']])->assertOk();

        expect($response->json("data.values.{$money->ulid}"))->toBe('12.50')
            ->and($item->fresh()->values[$money->ulid])->toBe('12.50');
    });

    it('rebuilds what it stores from the fields that exist: a stray key left in the row is dropped', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $weight = fieldIn($inventory, 'number', 'Weight');
        $item = itemIn($inventory, [$name->ulid => 'Hammer']);
        DB::table('items')->where('id', $item->id)->update(['values' => json_encode([$name->ulid => 'Hammer', '01j0000000000000000000000x' => 'stray'])]);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}", ['values' => [$weight->ulid => '4']])->assertOk();

        expect($item->fresh()->values)->toEqual([$name->ulid => 'Hammer', $weight->ulid => '4']);
    });

    it('is refused to a viewer (403) and a stranger (404)', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $item = itemIn($inventory, [$name->ulid => 'Hammer']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}";

        inventoryCall($this, $team['viewer'], 'PATCH', $uri, ['values' => [$name->ulid => 'Hacked']])->assertForbidden();
        inventoryCall($this, $team['stranger'], 'PATCH', $uri, ['values' => [$name->ulid => 'Hacked']])->assertNotFound();

        expect($item->fresh()->values)->toBe([$name->ulid => 'Hammer']);
    });
});

describe('deleting an item', function (): void {
    it('is for owners and editors', function (string $role): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $item = itemIn($inventory);
        $other = itemIn($inventory);

        inventoryCall($this, $team[$role], 'DELETE', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}")->assertNoContent();

        expect(Item::query()->pluck('id')->all())->toBe([$other->id]);

        inventoryCall($this, $team[$role], 'DELETE', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}")->assertNotFound();
    })->with(['an owner' => 'owner', 'an editor' => 'editor']);

    it('is refused to a viewer (403) and a stranger (404)', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $item = itemIn($inventory);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}";

        inventoryCall($this, $team['viewer'], 'DELETE', $uri)->assertForbidden();
        inventoryCall($this, $team['stranger'], 'DELETE', $uri)->assertNotFound();

        expect(Item::query()->count())->toBe(1);
    });
});

describe('an item of another inventory', function (): void {
    it('is a 404, whether the other inventory is in the same workspace or in another one', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'One');
        $name = fieldIn($inventory, 'text', 'Name');
        $sibling = itemIn(inventoryIn($team['workspace'], 'Two'));
        $stranger = inventoryPerson('Other');
        $theirs = itemIn(inventoryIn($stranger->workspaces()->sole(), 'Theirs'));

        foreach ([$sibling, $theirs] as $item) {
            $uri = "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}";

            inventoryCall($this, $team['owner'], 'GET', $uri)->assertNotFound();
            inventoryCall($this, $team['owner'], 'PATCH', $uri, ['values' => [$name->ulid => 'Hacked']])->assertNotFound();
            inventoryCall($this, $team['owner'], 'DELETE', $uri)->assertNotFound();
        }

        expect(Item::query()->count())->toBe(2);
    });

    it('is a 404 for an item that does not exist, and for a field or an inventory used as an item', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory);

        foreach (['01j0000000000000000000000x', $field->ulid, $inventory->ulid] as $ulid) {
            inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items/{$ulid}")->assertNotFound();
        }
    });

    it('is found whatever the case of its ULID', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $item = itemIn($inventory);

        inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items/".strtoupper($item->ulid))->assertOk()->assertJsonPath('data.id', $item->ulid);
    });
});

describe('the fields while items are written', function (): void {
    it('are the ones that exist when the item is written: a field deleted since is an unknown key', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $weight = fieldIn($inventory, 'number', 'Weight');

        // The person loaded the fields, then somebody deleted one; their form still sends it.
        app(InventoryFields::class)->delete($inventory, $weight);

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => [$name->ulid => 'Hammer', $weight->ulid => '2']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors("values.{$weight->ulid}");

        expect(Item::query()->count())->toBe(0);
    });

    it('become required for the next items as soon as the field is', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";

        inventoryCall($this, $team['owner'], 'POST', $uri, ['values' => []])->assertCreated();
        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$name->ulid}", ['required' => true])->assertOk();
        inventoryCall($this, $team['owner'], 'POST', $uri, ['values' => []])->assertUnprocessable();
    });
});
