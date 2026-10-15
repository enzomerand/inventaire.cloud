<?php

declare(strict_types=1);

use App\Inventories\Fields\FieldTypes;
use App\Models\InventoryField;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

describe('adding a field', function (): void {
    it('is for owners and editors, and puts the field at the end', function (string $role): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields";

        $first = inventoryCall($this, $team[$role], 'POST', $uri, ['type' => 'text', 'name' => '  Name  '])->assertCreated();
        $second = inventoryCall($this, $team[$role], 'POST', $uri, ['type' => 'number', 'name' => 'Weight', 'required' => true, 'options' => ['decimals' => 2, 'unit' => 'kg']])->assertCreated();

        expect($first->json('data'))->toMatchArray(['name' => 'Name', 'type' => 'text', 'options' => ['max_length' => 255], 'required' => false, 'position' => 0])
            ->and($first->json('data'))->toHaveKeys(['id', 'created_at', 'updated_at'])
            ->and($second->json('data'))->toMatchArray(['name' => 'Weight', 'type' => 'number', 'options' => ['decimals' => 2, 'unit' => 'kg'], 'required' => true, 'position' => 1])
            ->and($inventory->fields()->pluck('ulid')->all())->toBe([$first->json('data.id'), $second->json('data.id')]);
    })->with(['an owner' => 'owner', 'an editor' => 'editor']);

    it('is refused to a viewer (403) and a stranger (404), who learn nothing from the validation', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields";

        inventoryCall($this, $team['viewer'], 'POST', $uri, ['type' => 'text', 'name' => 'Name'])->assertForbidden();
        inventoryCall($this, $team['viewer'], 'POST', $uri, [])->assertForbidden();
        inventoryCall($this, $team['stranger'], 'POST', $uri, ['type' => 'text', 'name' => 'Name'])->assertNotFound();
        inventoryCall($this, $team['stranger'], 'POST', $uri, [])->assertNotFound();

        expect(InventoryField::query()->count())->toBe(0);
    });

    it('can be of any of the eighteen types, with the options of the type in canonical form', function (string $type, array $sent, array $stored): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $response = inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", ['type' => $type, 'name' => 'Field', 'options' => $sent])->assertCreated();

        expect($response->json('data.type'))->toBe($type)
            ->and($response->json('data.options'))->toEqual($stored);
    })->with([
        'text' => ['text', [], ['max_length' => 255]],
        'text with a length' => ['text', ['max_length' => 40], ['max_length' => 40]],
        'textarea' => ['textarea', [], []],
        'number' => ['number', ['decimals' => 3, 'min' => '0.50', 'max' => 99, 'unit' => 'm'], ['decimals' => 3, 'min' => '0.5', 'max' => '99', 'unit' => 'm']],
        'money' => ['money', ['currency' => 'USD'], ['currency' => 'USD']],
        'percentage' => ['percentage', [], ['decimals' => 2]],
        'boolean' => ['boolean', [], []],
        'date' => ['date', [], []],
        'datetime' => ['datetime', [], []],
        'time' => ['time', [], []],
        'url' => ['url', [], []],
        'email' => ['email', [], []],
        'phone' => ['phone', [], []],
        'rating' => ['rating', ['max' => 10], ['max' => 10]],
        'color' => ['color', [], []],
        'barcode' => ['barcode', ['format' => 'ean13'], ['format' => 'ean13']],
    ]);

    it('can be a select or a multiple choice, whose choices get ids from the server', function (string $type): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $response = inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", [
            'type' => $type,
            'name' => 'Condition',
            'options' => ['choices' => [['label' => ' New ', 'color' => 'green'], ['label' => 'Used']]],
        ])->assertCreated();

        $choices = $response->json('data.options.choices');

        expect($choices)->toHaveCount(2)
            ->and($choices[0])->toMatchArray(['label' => 'New', 'color' => 'green'])
            ->and($choices[0]['id'])->toMatch('/^[a-z0-9]{8}$/')
            ->and($choices[1])->toMatchArray(['label' => 'Used'])->not->toHaveKey('color')
            ->and($choices[1]['id'])->not->toBe($choices[0]['id']);
    })->with(['select' => 'select', 'multiselect' => 'multiselect']);

    it('offers every type of the registry (a test per type above must not be forgotten)', function (): void {
        // The two datasets above cover all of them: 16 here, select and multiselect separately.
        expect(app(FieldTypes::class)->keys())->toHaveCount(18);
    });

    it('is refused when the request is wrong', function (array $data, string $key): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", $data)->assertUnprocessable()->assertJsonValidationErrors($key);

        expect(InventoryField::query()->count())->toBe(0);
    })->with([
        'no type' => [['name' => 'Name'], 'type'],
        'a type that does not exist' => [['type' => 'relation', 'name' => 'Name'], 'type'],
        'a type that is a list' => [['type' => ['text'], 'name' => 'Name'], 'type'],
        'no name' => [['type' => 'text'], 'name'],
        'a name of spaces' => [['type' => 'text', 'name' => '  '], 'name'],
        'a name on two lines' => [['type' => 'text', 'name' => "a\nb"], 'name'],
        'a name of 121 characters' => [['type' => 'text', 'name' => str_repeat('a', 121)], 'name'],
        'required that is not a boolean' => [['type' => 'text', 'name' => 'Name', 'required' => 'maybe'], 'required'],
        'options that are not an object' => [['type' => 'text', 'name' => 'Name', 'options' => 'long'], 'options'],
        'an option the type does not have' => [['type' => 'text', 'name' => 'Name', 'options' => ['decimals' => 2]], 'options.decimals'],
        'an option on a type without options' => [['type' => 'date', 'name' => 'Name', 'options' => ['format' => 'x']], 'options.format'],
        'a text longer than a thousand' => [['type' => 'text', 'name' => 'Name', 'options' => ['max_length' => 1001]], 'options.max_length'],
        'seven decimals' => [['type' => 'number', 'name' => 'Name', 'options' => ['decimals' => 7]], 'options.decimals'],
        'a minimum above the maximum' => [['type' => 'number', 'name' => 'Name', 'options' => ['min' => 10, 'max' => 5]], 'options.max'],
        'a currency that does not exist' => [['type' => 'money', 'name' => 'Name', 'options' => ['currency' => 'ZZZ']], 'options.currency'],
        'a rating of two' => [['type' => 'rating', 'name' => 'Name', 'options' => ['max' => 2]], 'options.max'],
        'a barcode format that does not exist' => [['type' => 'barcode', 'name' => 'Name', 'options' => ['format' => 'ean14']], 'options.format'],
        'a select without choices' => [['type' => 'select', 'name' => 'Name'], 'options.choices'],
        'a select with an empty list' => [['type' => 'select', 'name' => 'Name', 'options' => ['choices' => []]], 'options.choices'],
        'a choice without a label' => [['type' => 'select', 'name' => 'Name', 'options' => ['choices' => [['color' => 'red']]]], 'options.choices.0.label'],
        'a choice with a color outside the palette' => [['type' => 'select', 'name' => 'Name', 'options' => ['choices' => [['label' => 'A', 'color' => 'mauve']]]], 'options.choices.0.color'],
        'two choices with the same label' => [['type' => 'multiselect', 'name' => 'Name', 'options' => ['choices' => [['label' => 'A'], ['label' => ' a ']]]], 'options.choices'],
    ]);

    it('names the option in its own words when an option is refused', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", ['type' => 'text', 'name' => 'Name', 'options' => ['max_length' => 0]], ['Accept-Language' => 'en'])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['options.max_length' => ['The maximum length field must be between 1 and 1000.']]);
    });

    it('refuses a name another field already has, whatever the capitals and the accents', function (string $second): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields";

        inventoryCall($this, $team['owner'], 'POST', $uri, ['type' => 'text', 'name' => 'Straße'])->assertCreated();
        inventoryCall($this, $team['owner'], 'POST', $uri, ['type' => 'number', 'name' => $second], ['Accept-Language' => 'en'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'This inventory already has a field with this name.');

        expect(InventoryField::query()->count())->toBe(1);
    })->with(['straße', 'STRASSE', ' Straße ', 'STRAßE']);

    it('accepts the name of a field of another inventory', function (): void {
        $team = inventoryTeam();
        fieldIn(inventoryIn($team['workspace'], 'One'), 'text', 'Name');
        $other = inventoryIn($team['workspace'], 'Two');

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$other->ulid}/fields", ['type' => 'text', 'name' => 'Name'])->assertCreated();
    });

    it('is refused beyond the quota of the inventory, with a code and the limit (409)', function (): void {
        config(['inventories.max_fields' => 2]);
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields";

        inventoryCall($this, $team['owner'], 'POST', $uri, ['type' => 'text', 'name' => 'One'])->assertCreated();
        inventoryCall($this, $team['owner'], 'POST', $uri, ['type' => 'text', 'name' => 'Two'])->assertCreated();
        inventoryCall($this, $team['owner'], 'POST', $uri, ['type' => 'text', 'name' => 'Three'], ['Accept-Language' => 'en'])
            ->assertStatus(409)
            ->assertJson(['code' => 'field_limit_reached', 'limit' => 2, 'message' => 'An inventory cannot have more than 2 fields.']);

        // Another inventory has its own quota; deleting a field frees a place.
        inventoryCall($this, $team['owner'], 'POST', '/api/v1/inventories/'.inventoryIn($team['workspace'], 'Other')->ulid.'/fields', ['type' => 'text', 'name' => 'One'])->assertCreated();
        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/inventories/{$inventory->ulid}/fields/".$inventory->fields()->first()->ulid)->assertNoContent();
        inventoryCall($this, $team['owner'], 'POST', $uri, ['type' => 'text', 'name' => 'Three'])->assertCreated();
    });

    it('is a 404 in an inventory that does not exist', function (): void {
        inventoryCall($this, inventoryPerson(), 'POST', '/api/v1/inventories/01j0000000000000000000000x/fields', ['type' => 'text', 'name' => 'Name'])->assertNotFound();
    });
});

describe('changing a field', function (): void {
    it('renames it, makes it required or not, and changes its options, whichever is sent', function (string $role): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'number', 'Weight', ['decimals' => 1, 'min' => '0', 'unit' => 'kg']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}";

        inventoryCall($this, $team[$role], 'PATCH', $uri, ['name' => ' Mass '])->assertOk()->assertJsonPath('data.name', 'Mass')->assertJsonPath('data.required', false);
        inventoryCall($this, $team[$role], 'PATCH', $uri, ['required' => true])->assertOk()->assertJsonPath('data.name', 'Mass')->assertJsonPath('data.required', true);
        inventoryCall($this, $team[$role], 'PATCH', $uri, ['required' => false])->assertOk()->assertJsonPath('data.required', false);

        // Options merge over the ones the field has: only the max is sent, the rest stays.
        $merged = inventoryCall($this, $team[$role], 'PATCH', $uri, ['options' => ['max' => '100']])->assertOk();
        expect($merged->json('data.options'))->toEqual(['decimals' => 1, 'min' => '0', 'max' => '100', 'unit' => 'kg']);

        // An option sent as null goes back to its default (or is dropped).
        $reset = inventoryCall($this, $team[$role], 'PATCH', $uri, ['options' => ['unit' => null, 'max' => null, 'decimals' => null]])->assertOk();
        expect($reset->json('data.options'))->toEqual(['decimals' => 0, 'min' => '0'])
            ->and($field->fresh()->only(['name', 'required', 'position']))->toBe(['name' => 'Mass', 'required' => false, 'position' => 0]);
    })->with(['an owner' => 'owner', 'an editor' => 'editor']);

    it('is refused to a viewer (403) and a stranger (404)', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'text', 'Name');
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}";

        inventoryCall($this, $team['viewer'], 'PATCH', $uri, ['name' => 'Hacked'])->assertForbidden();
        inventoryCall($this, $team['stranger'], 'PATCH', $uri, ['name' => 'Hacked'])->assertNotFound();

        expect($field->fresh()->name)->toBe('Name');
    });

    it('cannot change its type, but may repeat it', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'text', 'Name');
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}";

        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['type' => 'number'], ['Accept-Language' => 'en'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.type.0', 'The type of a field cannot be changed.');
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['type' => 'text', 'name' => 'Title'])->assertOk()->assertJsonPath('data.type', 'text');

        expect($field->fresh()->type)->toBe('text')->and($field->fresh()->name)->toBe('Title');
    });

    it('judges the options by the type of the field, and refuses the ones the type does not have', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'text', 'Name');
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}";

        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['options' => ['decimals' => 2]])->assertUnprocessable()->assertJsonValidationErrors('options.decimals');
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['options' => ['max_length' => 5000]])->assertUnprocessable()->assertJsonValidationErrors('options.max_length');
        // A minimum above the maximum already there is judged together, whichever of the two was sent now.
        $number = fieldIn($inventory, 'number', 'Weight', ['min' => '10', 'max' => '20']);
        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$number->ulid}", ['options' => ['max' => '5']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.max');

        expect($field->fresh()->options)->toBe(['max_length' => 255])
            ->and($number->fresh()->options)->toEqual(['decimals' => 0, 'min' => '10', 'max' => '20']);
    });

    it('may take the name of its own, but not of another field', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $weight = fieldIn($inventory, 'number', 'Weight');
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields/{$weight->ulid}";

        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['name' => 'weight'])->assertOk()->assertJsonPath('data.name', 'weight');
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['name' => ' NAME '])->assertUnprocessable()->assertJsonValidationErrors('name');
        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$name->ulid}", ['name' => 'Name'])->assertOk();

        expect($weight->fresh()->name)->toBe('weight');
    });

    it('keeps the ids of the choices it had, and gives new ones to the new choices', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'select', 'Condition', ['choices' => [['label' => 'New'], ['label' => 'Used']]]);
        [$new, $used] = $field->options['choices'];

        $response = inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}", [
            'options' => ['choices' => [['id' => $used['id'], 'label' => 'Second hand', 'color' => 'orange'], ['id' => $new['id'], 'label' => 'New'], ['label' => 'Broken']]],
        ])->assertOk();

        $choices = $response->json('data.options.choices');

        expect($choices[0])->toBe(['id' => $used['id'], 'label' => 'Second hand', 'color' => 'orange'])
            ->and($choices[1])->toBe(['id' => $new['id'], 'label' => 'New'])
            ->and($choices[2]['label'])->toBe('Broken')
            ->and($choices[2]['id'])->not->toBeIn([$new['id'], $used['id']]);
    });

    it('is a 404 for a field of another inventory, of the same workspace or another one', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'One');
        $other = inventoryIn($team['workspace'], 'Two');
        $foreign = fieldIn($other, 'text', 'Name');
        $stranger = inventoryPerson('Other');
        $theirs = fieldIn(inventoryIn($stranger->workspaces()->sole()), 'text', 'Name');

        foreach ([$foreign, $theirs] as $field) {
            inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}", ['name' => 'Hacked'])->assertNotFound();
            inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}")->assertNotFound();
        }

        expect($foreign->fresh()->name)->toBe('Name')->and($theirs->fresh()->name)->toBe('Name');
    });

    it('is a 404 for a field that does not exist, and for an item or an inventory used as a field', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $item = itemIn($inventory);

        foreach (['01j0000000000000000000000x', $item->ulid, $inventory->ulid] as $ulid) {
            inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$ulid}", ['name' => 'x'])->assertNotFound();
        }
    });
});

describe('deleting a field', function (): void {
    it('is for owners and editors, and removes its values from every item without touching them otherwise', function (string $role): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $weight = fieldIn($inventory, 'number', 'Weight');
        $tags = fieldIn($inventory, 'tags', 'Tags');
        $with = itemIn($inventory, [$name->ulid => 'Hammer', $weight->ulid => '2', $tags->ulid => ['tool']]);
        $onlyWeight = itemIn($inventory, [$weight->ulid => '5']);
        $empty = itemIn($inventory);
        $only = itemIn($inventory, [$weight->ulid => '9']);
        // The text of a value may contain the ULID of the field without being its key.
        $mention = itemIn($inventory, [$name->ulid => "see {$weight->ulid}"]);
        DB::table('items')->update(['updated_at' => '2020-01-01 00:00:00']);

        inventoryCall($this, $team[$role], 'DELETE', "/api/v1/inventories/{$inventory->ulid}/fields/{$weight->ulid}")->assertNoContent();

        expect(InventoryField::query()->whereKey($weight->id)->exists())->toBeFalse()
            ->and($with->fresh()->values)->toEqual([$name->ulid => 'Hammer', $tags->ulid => ['tool']])
            ->and($onlyWeight->fresh()->values)->toBe([])
            ->and($only->fresh()->values)->toBe([])
            ->and($empty->fresh()->values)->toBe([])
            ->and($mention->fresh()->values)->toBe([$name->ulid => "see {$weight->ulid}"])
            ->and(DB::table('items')->where('updated_at', '!=', '2020-01-01 00:00:00')->count())->toBe(0)
            ->and(json_decode((string) DB::table('items')->where('id', $onlyWeight->id)->value('values')))->toBeObject();
    })->with(['an owner' => 'owner', 'an editor' => 'editor']);

    it('closes up the positions of the fields left', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        [$a, $b, $c] = [fieldIn($inventory, 'text', 'A'), fieldIn($inventory, 'text', 'B'), fieldIn($inventory, 'text', 'C')];

        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/inventories/{$inventory->ulid}/fields/{$a->ulid}")->assertNoContent();

        expect($inventory->fields()->pluck('position', 'ulid')->all())->toBe([$b->ulid => 0, $c->ulid => 1]);

        // And the next field goes after them.
        $response = inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", ['type' => 'text', 'name' => 'D'])->assertCreated();
        expect($response->json('data.position'))->toBe(2);
    });

    it('does not touch the items of another inventory', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'One');
        $field = fieldIn($inventory, 'text', 'Name');
        $other = inventoryIn($team['workspace'], 'Two');
        $otherField = fieldIn($other, 'text', 'Name');
        $kept = itemIn($other, [$otherField->ulid => 'Kept']);
        // A row that holds the key of the deleted field in another inventory (it cannot be written through the application).
        DB::table('items')->where('id', $kept->id)->update(['values' => json_encode([$field->ulid => 'stray', $otherField->ulid => 'Kept'])]);

        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}")->assertNoContent();

        expect($kept->fresh()->values)->toEqual([$field->ulid => 'stray', $otherField->ulid => 'Kept']);
    });

    it('purges more items than one chunk holds', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'text', 'Name');
        $keep = fieldIn($inventory, 'text', 'Keep');

        $rows = [];

        foreach (range(1, 1200) as $number) {
            $rows[] = ['ulid' => (string) Str::ulid(), 'inventory_id' => $inventory->id, 'values' => json_encode([$field->ulid => "n{$number}", $keep->ulid => 'k']), 'created_at' => now(), 'updated_at' => now()];
        }

        DB::table('items')->insert($rows);

        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}")->assertNoContent();

        expect(Item::query()->count())->toBe(1200)
            ->and(Item::query()->get()->every(fn (Item $item): bool => $item->values === [$keep->ulid => 'k']))->toBeTrue();
    });

    it('is refused to a viewer (403) and a stranger (404)', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'text', 'Name');
        $item = itemIn($inventory, [$field->ulid => 'Hammer']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}";

        inventoryCall($this, $team['viewer'], 'DELETE', $uri)->assertForbidden();
        inventoryCall($this, $team['stranger'], 'DELETE', $uri)->assertNotFound();

        expect($field->fresh())->not->toBeNull()
            ->and($item->fresh()->values)->toBe([$field->ulid => 'Hammer']);
    });

    it('is a 404 the second time', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory);
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}";

        inventoryCall($this, $team['owner'], 'DELETE', $uri)->assertNoContent();
        inventoryCall($this, $team['owner'], 'DELETE', $uri)->assertNotFound();
    });
});

describe('ordering the fields', function (): void {
    it('puts them in the order given, for owners and editors', function (string $role): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        [$a, $b, $c] = [fieldIn($inventory, 'text', 'A'), fieldIn($inventory, 'text', 'B'), fieldIn($inventory, 'text', 'C')];

        $response = inventoryCall($this, $team[$role], 'PUT', "/api/v1/inventories/{$inventory->ulid}/fields/order", ['fields' => [$c->ulid, $a->ulid, $b->ulid]])->assertOk();

        expect(array_column($response->json('data'), 'id'))->toBe([$c->ulid, $a->ulid, $b->ulid])
            ->and(array_column($response->json('data'), 'position'))->toBe([0, 1, 2])
            ->and($inventory->fields()->pluck('ulid')->all())->toBe([$c->ulid, $a->ulid, $b->ulid]);

        // The inventory is read in that order afterwards.
        $read = inventoryCall($this, $team[$role], 'GET', "/api/v1/inventories/{$inventory->ulid}")->json('data.fields.*.id');
        expect($read)->toBe([$c->ulid, $a->ulid, $b->ulid]);
    })->with(['an owner' => 'owner', 'an editor' => 'editor']);

    it('accepts the ULIDs in capitals', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        [$a, $b] = [fieldIn($inventory, 'text', 'A'), fieldIn($inventory, 'text', 'B')];

        inventoryCall($this, $team['owner'], 'PUT', "/api/v1/inventories/{$inventory->ulid}/fields/order", ['fields' => [strtoupper($b->ulid), strtoupper($a->ulid)]])->assertOk();

        expect($inventory->fields()->pluck('ulid')->all())->toBe([$b->ulid, $a->ulid]);
    });

    it('needs exactly the fields the inventory has, each once', function (string $case): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        [$a, $b] = [fieldIn($inventory, 'text', 'A'), fieldIn($inventory, 'text', 'B')];
        $foreign = fieldIn(inventoryIn($team['workspace'], 'Other'), 'text', 'A');

        $fields = match ($case) {
            'one missing' => [$a->ulid],
            'one too many' => [$a->ulid, $b->ulid, $foreign->ulid],
            'a foreign one in place of one' => [$a->ulid, $foreign->ulid],
            'a repeat' => [$a->ulid, $a->ulid],
            'an unknown one' => [$a->ulid, '01j0000000000000000000000x'],
            'none' => [],
        };

        inventoryCall($this, $team['owner'], 'PUT', "/api/v1/inventories/{$inventory->ulid}/fields/order", ['fields' => $fields])->assertUnprocessable()->assertJsonValidationErrors('fields');

        expect($inventory->fields()->pluck('ulid')->all())->toBe([$a->ulid, $b->ulid]);
    })->with(['one missing', 'one too many', 'a foreign one in place of one', 'a repeat', 'an unknown one', 'none']);

    it('needs a list of texts', function (array $data): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        fieldIn($inventory);

        inventoryCall($this, $team['owner'], 'PUT', "/api/v1/inventories/{$inventory->ulid}/fields/order", $data)->assertUnprocessable()->assertJsonValidationErrors('fields');
    })->with([
        'nothing' => [[]],
        'text' => [['fields' => 'abc']],
        'an object' => [['fields' => ['a' => 'x']]],
    ]);

    it('is refused to a viewer (403) and a stranger (404)', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        [$a, $b] = [fieldIn($inventory, 'text', 'A'), fieldIn($inventory, 'text', 'B')];
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields/order";

        inventoryCall($this, $team['viewer'], 'PUT', $uri, ['fields' => [$b->ulid, $a->ulid]])->assertForbidden();
        inventoryCall($this, $team['stranger'], 'PUT', $uri, ['fields' => [$b->ulid, $a->ulid]])->assertNotFound();

        expect($inventory->fields()->pluck('ulid')->all())->toBe([$a->ulid, $b->ulid]);
    });

    it('is not mistaken for the field called "order"', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        fieldIn($inventory);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/order", ['name' => 'x'])->assertNotFound();
        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/inventories/{$inventory->ulid}/fields/order")->assertNotFound();
    });
});
