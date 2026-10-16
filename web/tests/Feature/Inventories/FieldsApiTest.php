<?php

declare(strict_types=1);

use App\Inventories\Fields\FieldTypes;
use App\Models\Inventory;
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

    it('says how the list of items treats each type of field: its query kind and whether it adds up', function (string $type, string $kind, bool $summable): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $options = in_array($type, ['select', 'multiselect'], true) ? ['choices' => [['label' => 'One']]] : [];

        $created = inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", ['type' => $type, 'name' => 'Field', 'options' => $options])->assertCreated();
        $listed = inventoryCall($this, $team['viewer'], 'GET', "/api/v1/inventories/{$inventory->ulid}")->assertOk();

        // The same in the answer to the creation and in the fields of the inventory.
        expect($created->json('data.query_kind'))->toBe($kind)
            ->and($created->json('data.summable'))->toBe($summable)
            ->and($listed->json('data.fields.0.query_kind'))->toBe($kind)
            ->and($listed->json('data.fields.0.summable'))->toBe($summable);
    })->with([
        'text' => ['text', 'text', false],
        'textarea' => ['textarea', 'text', false],
        'number' => ['number', 'number', true],
        'money' => ['money', 'number', true],
        'percentage' => ['percentage', 'number', false],
        'rating' => ['rating', 'number', false],
        'boolean' => ['boolean', 'boolean', false],
        'date' => ['date', 'temporal', false],
        'datetime' => ['datetime', 'temporal', false],
        'time' => ['time', 'temporal', false],
        'select' => ['select', 'choice', false],
        'multiselect' => ['multiselect', 'choices', false],
        'tags' => ['tags', 'tags', false],
        'url' => ['url', 'text', false],
        'email' => ['email', 'text', false],
        'phone' => ['phone', 'text', false],
        'color' => ['color', 'text', false],
        'barcode' => ['barcode', 'text', false],
    ]);

    it('gives no query kind to a field whose type this version does not know, and does not add it up', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        InventoryField::factory()->for($inventory)->create(['name' => 'Odd', 'type' => 'hologram', 'position' => 0]);

        $field = inventoryCall($this, $team['viewer'], 'GET', "/api/v1/inventories/{$inventory->ulid}")->assertOk()->json('data.fields.0');

        expect($field['type'])->toBe('hologram')->and($field['query_kind'])->toBeNull()->and($field['summable'])->toBeFalse();
    });

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
        'money' => ['money', ['currency' => 'USD'], ['currency' => 'USD', 'decimals' => 2]],
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
        // The datasets above cover all of them: 16 here, select and multiselect separately, file and photo in FileFieldsTest.php.
        expect(app(FieldTypes::class)->keys())->toHaveCount(20);
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

    it('counts the accents in a name, but not the way they are typed: one Unicode form', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields";
        $composed = "Pr\u{00E9}nom";
        $decomposed = "Pre\u{0301}nom";

        $first = inventoryCall($this, $team['owner'], 'POST', $uri, ['type' => 'text', 'name' => $decomposed])->assertCreated();

        // Kept in one form...
        expect($first->json('data.name'))->toBe($composed)
            ->and(InventoryField::query()->sole()->name)->toBe($composed);

        // ...so the same name typed either way is taken, in capitals too...
        foreach ([$composed, $decomposed, "PR\u{00C9}NOM", "PRE\u{0301}NOM"] as $name) {
            inventoryCall($this, $team['owner'], 'POST', $uri, ['type' => 'number', 'name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        }

        // ...but "Prenom" is another name.
        $second = inventoryCall($this, $team['owner'], 'POST', $uri, ['type' => 'number', 'name' => 'Prenom'])->assertCreated();

        // And renaming works the same way.
        inventoryCall($this, $team['owner'], 'PATCH', "{$uri}/".$second->json('data.id'), ['name' => $decomposed])->assertUnprocessable()->assertJsonValidationErrors('name');
        inventoryCall($this, $team['owner'], 'PATCH', "{$uri}/".$first->json('data.id'), ['name' => "PRE\u{0301}NOM"])->assertOk()->assertJsonPath('data.name', "PR\u{00C9}NOM");

        expect(InventoryField::query()->count())->toBe(2);
    });

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

    it('gives an amount a currency in use today, not one that went out of use', function (string $code, bool $accepted): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $response = inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", ['type' => 'money', 'name' => 'Price', 'options' => ['currency' => $code]]);

        if ($accepted) {
            $response->assertCreated()->assertJsonPath('data.options.currency', $code);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('options.currency');
        }
    })->with([
        'the euro' => ['EUR', true],
        'the dollar' => ['USD', true],
        'the yen' => ['JPY', true],
        'the Andorran peseta' => ['ADP', false],
        'the French franc' => ['FRF', false],
        'the old Afghani' => ['AFA', false],
        'gold' => ['XAU', false],
    ]);

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

describe('changing the currency of an amount field', function (): void {
    it('lets a field that has a currency which went out of use keep it, and refuses to give it to another', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $old = fieldIn($inventory, 'money', 'Old price', ['currency' => 'ADP']);
        $other = fieldIn($inventory, 'money', 'Price');
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields/{$old->ulid}";

        // The whole form is sent back as it was: the currency it has is accepted.
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['name' => 'Older price', 'options' => ['currency' => 'ADP']])->assertOk()->assertJsonPath('data.options.currency', 'ADP');
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['required' => true])->assertOk()->assertJsonPath('data.options.currency', 'ADP');
        // Another currency out of use is not, for this field or for another one.
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['options' => ['currency' => 'FRF']])->assertUnprocessable()->assertJsonValidationErrors('options.currency');
        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$other->ulid}", ['options' => ['currency' => 'ADP']])->assertUnprocessable()->assertJsonValidationErrors('options.currency');
        // Moving to a currency in use is fine, and there is no way back.
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['options' => ['currency' => 'EUR']])->assertOk()->assertJsonPath('data.options.currency', 'EUR');
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['options' => ['currency' => 'ADP']])->assertUnprocessable();

        expect($old->fresh()->options)->toEqual(['currency' => 'EUR', 'decimals' => 2])->and($old->fresh()->name)->toBe('Older price');
    });

    it('keeps writing the amounts of such a field with the decimals of its currency', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $old = fieldIn($inventory, 'money', 'Old price', ['currency' => 'ADP']);

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => [$old->ulid => '1500']])->assertCreated()->assertJsonPath('data.values', [$old->ulid => '1500']);
        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => [$old->ulid => '1500.5']])->assertUnprocessable();
    });
});

describe('changing the options of a field that items have values for', function (): void {
    /**
     * An inventory whose items all hold a value for the fields given, with their dates set far back to show they are not touched.
     *
     * @param  list<array<string, mixed>>  $items  The values of each item, by ULID of field.
     * @return list<Item>
     */
    function fieldsItemsWith(Inventory $inventory, array $items): array
    {
        $made = array_map(fn (array $values): Item => Item::factory()->for($inventory)->create(['values' => $values]), $items);
        DB::table('items')->update(['updated_at' => '2020-01-01 00:00:00']);

        return $made;
    }

    /**
     * What the items hold now, by position in the list given, and how many of them were rewritten (their date moved).
     */
    function fieldsItemsUntouched(): bool
    {
        return DB::table('items')->where('updated_at', '!=', '2020-01-01 00:00:00')->count() === 0;
    }

    it('removes the ids of the choices deleted from a select from every item, and nothing else', function (string $role): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $condition = fieldIn($inventory, 'select', 'Condition', ['choices' => [['label' => 'New'], ['label' => 'Used'], ['label' => 'Broken']]]);
        [$new, $used, $broken] = array_column($condition->options['choices'], 'id');
        [$a, $b, $c, $d] = fieldsItemsWith($inventory, [
            [$name->ulid => 'Hammer', $condition->ulid => $used],
            [$condition->ulid => $broken],
            [$name->ulid => 'Saw', $condition->ulid => $new],
            [$name->ulid => 'Plane'],
        ]);

        inventoryCall($this, $team[$role], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$condition->ulid}", ['options' => ['choices' => [['id' => $new, 'label' => 'New']]]])->assertOk();

        expect($a->fresh()->values)->toEqual([$name->ulid => 'Hammer'])
            ->and($b->fresh()->values)->toBe([])
            ->and($c->fresh()->values)->toEqual([$name->ulid => 'Saw', $condition->ulid => $new])
            ->and($d->fresh()->values)->toEqual([$name->ulid => 'Plane'])
            ->and(fieldsItemsUntouched())->toBeTrue()
            ->and(json_decode((string) DB::table('items')->where('id', $b->id)->value('values')))->toBeObject();
    })->with(['an owner' => 'owner', 'an editor' => 'editor']);

    it('removes the deleted ids from a multiple choice and keeps the others, in their order', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $colors = fieldIn($inventory, 'multiselect', 'Colors', ['choices' => [['label' => 'Red'], ['label' => 'Green'], ['label' => 'Blue']]]);
        [$red, $green, $blue] = array_column($colors->options['choices'], 'id');
        [$a, $b, $c] = fieldsItemsWith($inventory, [
            [$colors->ulid => [$blue, $green, $red]],
            [$colors->ulid => [$green]],
            [$colors->ulid => [$red, $blue]],
        ]);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$colors->ulid}", ['options' => ['choices' => [['id' => $red, 'label' => 'Red'], ['id' => $blue, 'label' => 'Blue']]]])->assertOk();

        expect($a->fresh()->values)->toBe([$colors->ulid => [$blue, $red]])
            ->and($b->fresh()->values)->toBe([])
            ->and($c->fresh()->values)->toBe([$colors->ulid => [$red, $blue]])
            ->and(fieldsItemsUntouched())->toBeTrue();
    });

    it('does not bring a deleted choice back when a choice of the same name is added', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $condition = fieldIn($inventory, 'select', 'Condition', ['choices' => [['label' => 'New'], ['label' => 'Used']]]);
        [$new, $used] = array_column($condition->options['choices'], 'id');
        [$item] = fieldsItemsWith($inventory, [[$condition->ulid => $used]]);
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields/{$condition->ulid}";

        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['options' => ['choices' => [['id' => $new, 'label' => 'New']]]])->assertOk();
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['options' => ['choices' => [['id' => $new, 'label' => 'New'], ['id' => $used, 'label' => 'Used']]]])->assertOk();

        expect($item->fresh()->values)->toBe([])
            ->and(inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}")->getContent())->toContain('"values":{}');
    });

    it('removes the grades above a lower maximum of a rating, and keeps the others', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $quality = fieldIn($inventory, 'rating', 'Quality', ['max' => 10]);
        [$a, $b, $c, $d] = fieldsItemsWith($inventory, [[$quality->ulid => 10], [$quality->ulid => 6], [$quality->ulid => 5], [$quality->ulid => 1]]);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$quality->ulid}", ['options' => ['max' => 5]])->assertOk()->assertJsonPath('data.options.max', 5);

        expect($a->fresh()->values)->toBe([])
            ->and($b->fresh()->values)->toBe([])
            ->and($c->fresh()->values)->toBe([$quality->ulid => 5])
            ->and($d->fresh()->values)->toBe([$quality->ulid => 1])
            ->and(fieldsItemsUntouched())->toBeTrue();
    });

    it('keeps every stored value when the change cannot make one wrong', function (string $type, array $before, array $after, mixed $value): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, $type, 'Field', $before);
        [$item] = fieldsItemsWith($inventory, [[$field->ulid => $value]]);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}", ['options' => $after])->assertOk();

        expect($item->fresh()->values)->toBe([$field->ulid => $value])
            ->and(fieldsItemsUntouched())->toBeTrue();
    })->with([
        'a higher maximum of a rating' => ['rating', ['max' => 5], ['max' => 10], 5],
        'the same maximum of a rating' => ['rating', ['max' => 5], ['max' => 5], 5],
        'fewer decimals of a number' => ['number', ['decimals' => 3], ['decimals' => 0], '12.500'],
        'tighter bounds of a number' => ['number', ['min' => '0', 'max' => '100'], ['min' => '10', 'max' => '20'], '50'],
        'another unit of a number' => ['number', ['unit' => 'kg'], ['unit' => 'lb'], '5'],
        'another currency of an amount' => ['money', ['currency' => 'EUR'], ['currency' => 'JPY'], '12.50'],
        'fewer decimals of a percentage' => ['percentage', ['decimals' => 4], ['decimals' => 0], '12.5000'],
        'a shorter text' => ['text', ['max_length' => 500], ['max_length' => 5], 'a long enough text'],
        'another format of a barcode' => ['barcode', ['format' => 'any'], ['format' => 'ean13'], 'ABC-123'],
    ]);

    it('keeps every value when choices are renamed, recolored, added or reordered', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $colors = fieldIn($inventory, 'multiselect', 'Colors', ['choices' => [['label' => 'Red'], ['label' => 'Green']]]);
        [$red, $green] = array_column($colors->options['choices'], 'id');
        [$item] = fieldsItemsWith($inventory, [[$colors->ulid => [$green, $red]]]);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$colors->ulid}", ['options' => ['choices' => [
            ['id' => $green, 'label' => 'Lime', 'color' => 'green'], ['label' => 'Blue'], ['id' => $red, 'label' => 'Crimson', 'color' => 'red'],
        ]]])->assertOk();

        expect($item->fresh()->values)->toBe([$colors->ulid => [$green, $red]])
            ->and(fieldsItemsUntouched())->toBeTrue();
    });

    it('leaves the values alone when the change is refused', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $quality = fieldIn($inventory, 'rating', 'Quality', ['max' => 10]);
        [$item] = fieldsItemsWith($inventory, [[$quality->ulid => 9]]);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$quality->ulid}", ['options' => ['max' => 2]])->assertUnprocessable();
        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$quality->ulid}", ['name' => 'Quality', 'options' => ['max' => 3, 'decimals' => 1]])->assertUnprocessable();

        expect($item->fresh()->values)->toBe([$quality->ulid => 9])
            ->and($quality->fresh()->options)->toBe(['max' => 10]);
    });

    it('cleans more items than one chunk holds, and the items of that inventory only', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $other = inventoryIn($team['workspace'], 'Other');
        $quality = fieldIn($inventory, 'rating', 'Quality', ['max' => 10]);
        $theirs = fieldIn($other, 'rating', 'Quality', ['max' => 10]);
        $rows = [];

        foreach (range(1, 1200) as $number) {
            $rows[] = ['ulid' => (string) Str::ulid(), 'inventory_id' => $inventory->id, 'values' => json_encode([$quality->ulid => $number % 10 + 1]), 'created_at' => now(), 'updated_at' => '2020-01-01 00:00:00'];
        }

        DB::table('items')->insert($rows);
        $kept = fieldsItemsWith($other, [[$theirs->ulid => 9]])[0];

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$quality->ulid}", ['options' => ['max' => 4]])->assertOk();

        $values = Item::query()->where('inventory_id', $inventory->id)->get()->map(fn (Item $item): ?int => $item->values[$quality->ulid] ?? null);

        expect($values)->toHaveCount(1200)
            ->and($values->filter(fn (?int $grade): bool => $grade !== null && $grade > 4))->toHaveCount(0)
            ->and($values->filter(fn (?int $grade): bool => $grade !== null))->toHaveCount(480)
            ->and($kept->fresh()->values)->toBe([$theirs->ulid => 9])
            ->and(fieldsItemsUntouched())->toBeTrue();
    });
});

describe('the decimals of an amount field', function (): void {
    it('are given by the API with the currency, frozen when the field is created', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields";

        $euro = inventoryCall($this, $team['owner'], 'POST', $uri, ['type' => 'money', 'name' => 'Price'])->assertCreated();
        $yen = inventoryCall($this, $team['owner'], 'POST', $uri, ['type' => 'money', 'name' => 'Yen price', 'options' => ['currency' => 'JPY']])->assertCreated();

        expect($euro->json('data.options'))->toBe(['currency' => 'EUR', 'decimals' => 2])
            ->and($yen->json('data.options'))->toBe(['currency' => 'JPY', 'decimals' => 0])
            ->and(InventoryField::query()->where('ulid', $euro->json('data.id'))->sole()->options)->toEqual(['currency' => 'EUR', 'decimals' => 2]);
    });

    it('cannot be sent: they follow the currency', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'money', 'Price');

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", ['type' => 'money', 'name' => 'Other', 'options' => ['currency' => 'EUR', 'decimals' => 5]])
            ->assertUnprocessable()->assertJsonValidationErrors('options.decimals');
        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}", ['options' => ['decimals' => 5]])
            ->assertUnprocessable()->assertJsonValidationErrors('options.decimals');

        expect($field->fresh()->options)->toEqual(['currency' => 'EUR', 'decimals' => 2]);
    });

    it('stay what the field has while its currency stays, are read again when the currency changes', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'money', 'Price');
        // ICU would say two for the euro; this field was frozen with three.
        $field->forceFill(['options' => ['currency' => 'EUR', 'decimals' => 3]])->save();
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}";

        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['name' => 'Cost'])->assertOk()->assertJsonPath('data.options', ['currency' => 'EUR', 'decimals' => 3]);
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['options' => ['currency' => 'EUR']])->assertOk()->assertJsonPath('data.options', ['currency' => 'EUR', 'decimals' => 3]);
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['options' => ['currency' => 'JPY']])->assertOk()->assertJsonPath('data.options', ['currency' => 'JPY', 'decimals' => 0]);
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['options' => ['currency' => 'EUR']])->assertOk()->assertJsonPath('data.options', ['currency' => 'EUR', 'decimals' => 2]);
    });

    it('judge and write the amounts of items', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'money', 'Price');
        $field->forceFill(['options' => ['currency' => 'EUR', 'decimals' => 3]])->save();
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";

        inventoryCall($this, $team['owner'], 'POST', $uri, ['values' => [$field->ulid => '1.234']])->assertCreated()->assertJsonPath('data.values', [$field->ulid => '1.234']);
        inventoryCall($this, $team['owner'], 'POST', $uri, ['values' => [$field->ulid => '1.5']])->assertCreated()->assertJsonPath('data.values', [$field->ulid => '1.500']);
        inventoryCall($this, $team['owner'], 'POST', $uri, ['values' => [$field->ulid => '1.2345']])->assertUnprocessable();
    });

    it('are read from ICU for a field stored without them, and kept from the next time its options are saved', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'money', 'Price');
        $field->forceFill(['options' => ['currency' => 'EUR']])->save();
        $uri = "/api/v1/inventories/{$inventory->ulid}/fields/{$field->ulid}";

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => [$field->ulid => '1.5']])->assertCreated()->assertJsonPath('data.values', [$field->ulid => '1.50']);
        inventoryCall($this, $team['owner'], 'PATCH', $uri, ['options' => ['currency' => 'EUR']])->assertOk()->assertJsonPath('data.options', ['currency' => 'EUR', 'decimals' => 2]);

        expect($field->fresh()->options)->toEqual(['currency' => 'EUR', 'decimals' => 2]);
    });
});
