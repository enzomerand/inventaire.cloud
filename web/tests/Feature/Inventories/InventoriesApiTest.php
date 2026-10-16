<?php

declare(strict_types=1);

use App\Inventories\Fields\FieldTypes;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

describe('without signing in', function (): void {
    it('answers 401 in JSON on every endpoint', function (string $method, string $path): void {
        $workspace = Workspace::factory()->create();
        $inventory = Inventory::factory()->for($workspace)->create();
        $field = InventoryField::factory()->for($inventory)->create();
        $item = Item::factory()->for($inventory)->create();

        $uri = str_replace(['{workspace}', '{inventory}', '{field}', '{item}'], [$workspace->ulid, $inventory->ulid, $field->ulid, $item->ulid], $path);

        $this->call($method, $uri)->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    })->with([
        ['GET', '/api/v1/field-types'],
        ['GET', '/api/v1/workspaces/{workspace}/inventories'],
        ['POST', '/api/v1/workspaces/{workspace}/inventories'],
        ['GET', '/api/v1/inventories/{inventory}'],
        ['PATCH', '/api/v1/inventories/{inventory}'],
        ['DELETE', '/api/v1/inventories/{inventory}'],
        ['POST', '/api/v1/inventories/{inventory}/fields'],
        ['PUT', '/api/v1/inventories/{inventory}/fields/order'],
        ['PATCH', '/api/v1/inventories/{inventory}/fields/{field}'],
        ['DELETE', '/api/v1/inventories/{inventory}/fields/{field}'],
        ['GET', '/api/v1/inventories/{inventory}/items'],
        ['POST', '/api/v1/inventories/{inventory}/items'],
        ['GET', '/api/v1/inventories/{inventory}/items/{item}'],
        ['PATCH', '/api/v1/inventories/{inventory}/items/{item}'],
        ['DELETE', '/api/v1/inventories/{inventory}/items/{item}'],
    ]);
});

describe('the field types', function (): void {
    it('are listed with their label in the language of the request', function (): void {
        $person = inventoryPerson();

        $english = inventoryCall($this, $person, 'GET', '/api/v1/field-types', headers: ['Accept-Language' => 'en'])->assertOk();
        $french = inventoryCall($this, $person, 'GET', '/api/v1/field-types', headers: ['Accept-Language' => 'fr'])->assertOk();

        expect($english->json('data'))->toHaveCount(18)
            ->and($english->json('data.0'))->toBe(['type' => 'text', 'label' => 'Text'])
            ->and($english->json('data.17'))->toBe(['type' => 'barcode', 'label' => 'Barcode'])
            ->and($french->json('data.1'))->toBe(['type' => 'textarea', 'label' => 'Texte long'])
            ->and(array_column($english->json('data'), 'type'))->toBe(app(FieldTypes::class)->keys());
    });
});

describe('listing the inventories of a workspace', function (): void {
    it('shows them to every member, by name, with the counts and what the member may do', function (string $role, array $abilities): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');
        fieldIn($inventory, 'text', 'Name');
        fieldIn($inventory, 'number', 'Weight');
        itemIn($inventory);
        inventoryIn($team['workspace'], 'Archive');

        $response = inventoryCall($this, $team[$role], 'GET', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories")->assertOk();

        expect(array_column($response->json('data'), 'name'))->toBe(['Archive', 'Tools'])
            ->and($response->json('data.1'))->toMatchArray([
                'id' => $inventory->ulid,
                'workspace_id' => $team['workspace']->ulid,
                'name' => 'Tools',
                'description' => null,
                'fields_count' => 2,
                'items_count' => 1,
                'abilities' => $abilities,
            ])
            ->and($response->json('data.0'))->toMatchArray(['fields_count' => 0, 'items_count' => 0])
            ->and($response->json('data.1'))->not->toHaveKey('fields');
    })->with([
        'an owner' => ['owner', ['update' => true, 'delete' => true, 'manage_fields' => true, 'edit_items' => true]],
        'an editor' => ['editor', ['update' => true, 'delete' => false, 'manage_fields' => true, 'edit_items' => true]],
        'a viewer' => ['viewer', ['update' => false, 'delete' => false, 'manage_fields' => false, 'edit_items' => false]],
    ]);

    it('is a 404 for somebody who does not belong to the workspace', function (): void {
        $team = inventoryTeam();
        inventoryIn($team['workspace']);

        inventoryCall($this, $team['stranger'], 'GET', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories")->assertNotFound();
    });

    it('is a 404 for a workspace that does not exist', function (): void {
        inventoryCall($this, inventoryPerson(), 'GET', '/api/v1/workspaces/01j0000000000000000000000x/inventories')->assertNotFound();
    });

    it('never shows the inventories of another workspace', function (): void {
        $team = inventoryTeam();
        inventoryIn($team['workspace'], 'Mine');
        inventoryIn(inventoryPerson('Other')->workspaces()->sole(), 'Theirs');

        $names = inventoryCall($this, $team['owner'], 'GET', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories")->json('data.*.name');

        expect($names)->toBe(['Mine']);
    });

    it('is an empty list for a workspace with none', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['viewer'], 'GET', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories")->assertOk()->assertExactJson(['data' => []]);
    });

    it('sorts the names the way a person reads them: capitals ignored, accents counted last, numbers as numbers', function (): void {
        $team = inventoryTeam();

        foreach (['box 10', 'Box 2', 'zebra', 'Élan', 'alpha', 'Box 1', 'Alpha', 'Elbe', 'Elan'] as $name) {
            inventoryIn($team['workspace'], $name);
        }

        $names = inventoryCall($this, $team['owner'], 'GET', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories")->json('data.*.name');

        // The accent only decides between names that are otherwise the same: "Elan" and "Élan" both come before "Elbe".
        expect($names)->toBe(['alpha', 'Alpha', 'Box 1', 'Box 2', 'box 10', 'Elan', 'Élan', 'Elbe', 'zebra']);
    });

    it('accepts the ULID of the workspace in capitals', function (): void {
        $team = inventoryTeam();
        inventoryIn($team['workspace']);

        inventoryCall($this, $team['owner'], 'GET', '/api/v1/workspaces/'.strtoupper($team['workspace']->ulid).'/inventories')->assertOk()->assertJsonCount(1, 'data');
    });
});

describe('creating an inventory', function (): void {
    it('is for owners and editors', function (string $role): void {
        $team = inventoryTeam();

        $response = inventoryCall($this, $team[$role], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories", ['name' => '  Tools  ', 'description' => "Line one\r\nLine two"])
            ->assertCreated();

        $inventory = Inventory::query()->sole();

        expect($response->json('data'))->toMatchArray([
            'id' => $inventory->ulid,
            'workspace_id' => $team['workspace']->ulid,
            'name' => 'Tools',
            'description' => "Line one\nLine two",
            'fields_count' => 0,
            'items_count' => 0,
        ])->and($response->json('data'))->not->toHaveKey('fields')
            ->and($inventory->workspace_id)->toBe($team['workspace']->id);
    })->with(['an owner' => 'owner', 'an editor' => 'editor']);

    it('is refused to a viewer (403) and a stranger (404), who learn nothing from the validation', function (): void {
        $team = inventoryTeam();
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/inventories";

        inventoryCall($this, $team['viewer'], 'POST', $uri, ['name' => 'Tools'])->assertForbidden()->assertJsonPath('message', fn (string $message) => $message !== '');
        inventoryCall($this, $team['viewer'], 'POST', $uri, [])->assertForbidden();
        inventoryCall($this, $team['stranger'], 'POST', $uri, ['name' => 'Tools'])->assertNotFound();
        inventoryCall($this, $team['stranger'], 'POST', $uri, [])->assertNotFound();

        expect(Inventory::query()->count())->toBe(0);
    });

    it('needs a name that is one line of at most 120 characters', function (array $data, string $field): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories", $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(Inventory::query()->count())->toBe(0);
    })->with([
        'no name' => [[], 'name'],
        'an empty name' => [['name' => ''], 'name'],
        'a name of spaces' => [['name' => '   '], 'name'],
        'a name on two lines' => [['name' => "Tools\nbox"], 'name'],
        'a name of 121 characters' => [['name' => str_repeat('a', 121)], 'name'],
        'a name that is a list' => [['name' => ['Tools']], 'name'],
        'a description that is too long' => [['name' => 'Tools', 'description' => str_repeat('a', 2001)], 'description'],
        'a description with a control character' => [['name' => 'Tools', 'description' => "a\x07b"], 'description'],
        'a description that is a list' => [['name' => 'Tools', 'description' => ['a']], 'description'],
    ]);

    it('keeps the name in one Unicode form, however the accent was typed', function (): void {
        $team = inventoryTeam();
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/inventories";

        $created = inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => "Caf\u{0065}\u{0301}"])->assertCreated();
        $renamed = inventoryCall($this, $team['owner'], 'PATCH', '/api/v1/inventories/'.$created->json('data.id'), ['name' => "Pre\u{0301}nom"])->assertOk();

        expect($created->json('data.name'))->toBe("Caf\u{00E9}")
            ->and($renamed->json('data.name'))->toBe("Pr\u{00E9}nom")
            ->and(Inventory::query()->sole()->name)->toBe("Pr\u{00E9}nom");
    });

    it('accepts a name of 120 characters and a description of 2000', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories", ['name' => str_repeat('é', 120), 'description' => str_repeat('x', 2000)])->assertCreated();
    });

    it('stores an empty description as none', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories", ['name' => 'Tools', 'description' => '  '])
            ->assertCreated()
            ->assertJsonPath('data.description', null);
    });

    it('is refused beyond the quota of the workspace, with a code and the limit (409)', function (): void {
        config(['inventories.max_per_workspace' => 2]);
        $team = inventoryTeam();
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/inventories";

        inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => 'One'])->assertCreated();
        inventoryCall($this, $team['editor'], 'POST', $uri, ['name' => 'Two'])->assertCreated();
        inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => 'Three'], ['Accept-Language' => 'en'])
            ->assertStatus(409)
            ->assertJson(['code' => 'inventory_limit_reached', 'limit' => 2, 'message' => 'A workspace cannot hold more than 2 inventories.']);

        // The quota is the workspace's: another workspace of the same person has its own.
        $other = inventoryPerson('Other')->workspaces()->sole();
        inventoryCall($this, $other->members()->sole(), 'POST', "/api/v1/workspaces/{$other->ulid}/inventories", ['name' => 'One'])->assertCreated();

        expect(Inventory::query()->where('workspace_id', $team['workspace']->id)->count())->toBe(2);
    });

    it('speaks French by default and in the singular when the limit is one', function (): void {
        config(['inventories.max_per_workspace' => 1]);
        $team = inventoryTeam();
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/inventories";

        inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => 'One'])->assertCreated();
        inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => 'Two'], ['Accept-Language' => 'fr'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Un espace de travail ne peut pas contenir plus de 1 inventaire.');
    });
});

describe('reading an inventory', function (): void {
    it('gives its fields in order and what the member may do with it', function (string $role, bool $canEdit): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');
        itemIn($inventory);
        $second = fieldIn($inventory, 'number', 'Weight', ['decimals' => 1, 'unit' => 'kg']);
        $first = fieldIn($inventory, 'text', 'Name', required: true);

        $response = inventoryCall($this, $team[$role], 'GET', "/api/v1/inventories/{$inventory->ulid}")->assertOk();

        expect($response->json('data'))->toMatchArray([
            'id' => $inventory->ulid,
            'workspace_id' => $team['workspace']->ulid,
            'name' => 'Tools',
            'fields_count' => 2,
            'items_count' => 1,
            'abilities' => ['update' => $canEdit, 'delete' => $role === 'owner', 'manage_fields' => $canEdit, 'edit_items' => $canEdit],
        ])->and(array_column($response->json('data.fields'), 'id'))->toBe([$second->ulid, $first->ulid])
            // MySQL keeps the keys of a JSON object in an order of its own: the options are compared as a set.
            ->and($response->json('data.fields.0'))->toEqual([
                'id' => $second->ulid, 'name' => 'Weight', 'type' => 'number', 'options' => ['decimals' => 1, 'unit' => 'kg'], 'required' => false, 'position' => 0,
                'query_kind' => 'number', 'summable' => true,
                'created_at' => $second->created_at->toIso8601String(), 'updated_at' => $second->updated_at->toIso8601String(),
            ])
            ->and($response->json('data.fields.1.required'))->toBeTrue()
            ->and($response->json('data.fields.1.query_kind'))->toBe('text')
            ->and($response->json('data.fields.1.summable'))->toBeFalse();
    })->with([
        'an owner' => ['owner', true],
        'an editor' => ['editor', true],
        'a viewer' => ['viewer', false],
    ]);

    it('is a 404 for a stranger, for an inventory that does not exist and for the ULID of another kind of row', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory);

        inventoryCall($this, $team['stranger'], 'GET', "/api/v1/inventories/{$inventory->ulid}")->assertNotFound();
        inventoryCall($this, $team['owner'], 'GET', '/api/v1/inventories/01j0000000000000000000000x')->assertNotFound();
        inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$field->ulid}")->assertNotFound();
        inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$team['workspace']->ulid}")->assertNotFound();
    });

    it('answers a row that is not there and a row that is not theirs in the same words', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory);

        $missing = inventoryCall($this, $team['stranger'], 'GET', '/api/v1/inventories/01j0000000000000000000000x')->assertNotFound();
        $foreign = inventoryCall($this, $team['stranger'], 'GET', "/api/v1/inventories/{$inventory->ulid}")->assertNotFound();
        $missingField = inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}/fields/01j0000000000000000000000x", ['name' => 'x'])->assertNotFound();

        expect($missing->getContent())->toBe($foreign->getContent())
            ->and($missingField->getContent())->toBe($foreign->getContent())
            ->and($missing->getContent())->not->toContain('App\\Models')
            ->and($missing->getContent())->not->toContain($field->ulid);
    });

    it('accepts the ULID in capitals', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        inventoryCall($this, $team['owner'], 'GET', '/api/v1/inventories/'.strtoupper($inventory->ulid))->assertOk()->assertJsonPath('data.id', $inventory->ulid);
    });

    it('never exposes a numeric key', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        fieldIn($inventory);

        $keys = array_keys(inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}")->json('data'));

        expect($keys)->toBe(['id', 'workspace_id', 'name', 'description', 'fields_count', 'items_count', 'fields', 'abilities', 'created_at', 'updated_at']);
    });
});

describe('changing an inventory', function (): void {
    it('renames and describes it, whichever is sent', function (string $role): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');
        $inventory->forceFill(['description' => 'Old'])->save();
        $uri = "/api/v1/inventories/{$inventory->ulid}";

        inventoryCall($this, $team[$role], 'PATCH', $uri, ['name' => ' Hardware '])->assertOk()->assertJsonPath('data.name', 'Hardware')->assertJsonPath('data.description', 'Old');
        inventoryCall($this, $team[$role], 'PATCH', $uri, ['description' => 'New text'])->assertOk()->assertJsonPath('data.name', 'Hardware')->assertJsonPath('data.description', 'New text');
        inventoryCall($this, $team[$role], 'PATCH', $uri, ['description' => null])->assertOk()->assertJsonPath('data.description', null);
        inventoryCall($this, $team[$role], 'PATCH', $uri, [])->assertOk()->assertJsonPath('data.name', 'Hardware');

        expect($inventory->fresh()->only(['name', 'description']))->toBe(['name' => 'Hardware', 'description' => null]);
    })->with(['an owner' => 'owner', 'an editor' => 'editor']);

    it('is refused to a viewer (403) and a stranger (404)', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');
        $uri = "/api/v1/inventories/{$inventory->ulid}";

        inventoryCall($this, $team['viewer'], 'PATCH', $uri, ['name' => 'Hacked'])->assertForbidden();
        inventoryCall($this, $team['stranger'], 'PATCH', $uri, ['name' => 'Hacked'])->assertNotFound();

        expect($inventory->fresh()->name)->toBe('Tools');
    });

    it('cannot be given an empty name or a name on two lines', function (array $data): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}", $data)->assertUnprocessable()->assertJsonValidationErrors('name');
    })->with([
        'empty' => [['name' => '']],
        'spaces' => [['name' => '  ']],
        'null' => [['name' => null]],
        'two lines' => [['name' => "a\nb"]],
        'too long' => [['name' => str_repeat('a', 121)]],
    ]);

    it('does not move to another workspace or change its identifier', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');
        $other = inventoryPerson('Other')->workspaces()->sole();

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}", ['workspace_id' => $other->ulid, 'id' => 'x', 'ulid' => 'x'])->assertOk();

        expect($inventory->fresh()->workspace_id)->toBe($team['workspace']->id)
            ->and($inventory->fresh()->ulid)->toBe($inventory->ulid);
    });
});

describe('deleting an inventory', function (): void {
    it('is for owners, and takes its fields and items with it', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $kept = inventoryIn($team['workspace'], 'Kept');
        $field = fieldIn($inventory);
        itemIn($inventory, [$field->ulid => 'x']);
        fieldIn($kept);
        itemIn($kept);

        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/inventories/{$inventory->ulid}")->assertNoContent();

        expect(Inventory::query()->pluck('id')->all())->toBe([$kept->id])
            ->and(InventoryField::query()->count())->toBe(1)
            ->and(Item::query()->count())->toBe(1);

        inventoryCall($this, $team['owner'], 'GET', "/api/v1/inventories/{$inventory->ulid}")->assertNotFound();
        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/inventories/{$inventory->ulid}")->assertNotFound();
    });

    it('is refused to editors and viewers (403) and strangers (404)', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}";

        inventoryCall($this, $team['editor'], 'DELETE', $uri)->assertForbidden();
        inventoryCall($this, $team['viewer'], 'DELETE', $uri)->assertForbidden();
        inventoryCall($this, $team['stranger'], 'DELETE', $uri)->assertNotFound();

        expect(Inventory::query()->count())->toBe(1);
    });

    it('frees a place in the quota of the workspace', function (): void {
        config(['inventories.max_per_workspace' => 1]);
        $team = inventoryTeam();
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/inventories";
        $created = inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => 'One'])->assertCreated()->json('data.id');

        inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => 'Two'])->assertStatus(409);
        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/inventories/{$created}")->assertNoContent();
        inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => 'Two'])->assertCreated();
    });
});
