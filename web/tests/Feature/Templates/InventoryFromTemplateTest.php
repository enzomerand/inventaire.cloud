<?php

declare(strict_types=1);

use App\Http\Requests\Inventories\SaveInventoryRequest;
use App\Inventories\Exceptions\FieldLimitReachedException;
use App\Inventories\Inventories;
use App\Inventories\Templates\Template;
use App\Inventories\Templates\TemplateCatalogue;
use App\Models\Inventory;
use App\Models\InventoryField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/templates.php';

uses(RefreshDatabase::class);

/*
 * Creating an inventory from a template: `POST /api/v1/workspaces/{workspace}/inventories` with a `template`. The inventory
 * and all of its fields are made in one transaction, in the language of the request.
 */

beforeEach(fn () => templateFixtures());

/**
 * Creates an inventory from a template as the owner of a team does, and gives the response.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function createFromTemplate(object $test, array $team, string $template, array $data = [], string $as = 'owner', array $headers = ['Accept-Language' => 'en']): TestResponse
{
    return inventoryCall($test, $team[$as], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories", $data + ['name' => 'My inventory', 'template' => $template], $headers);
}

describe('creating an inventory from a template', function (): void {
    it('gives the inventory the fields of the template, in order, in the language of the request', function (string $language, string $firstName, string $roomName): void {
        $team = inventoryTeam();

        $response = createFromTemplate($this, $team, 'sample-home', ['description' => 'Insurance file'], headers: ['Accept-Language' => $language])->assertCreated();

        expect($response->json('data'))->toMatchArray(['name' => 'My inventory', 'description' => 'Insurance file', 'workspace_id' => $team['workspace']->ulid, 'fields_count' => 13, 'items_count' => 0]);

        $fields = Inventory::query()->where('ulid', $response->json('data.id'))->sole()->fields()->get();

        expect($fields)->toHaveCount(13)
            ->and($fields->pluck('position')->all())->toBe(range(0, 12))
            ->and($fields[0]->name)->toBe($firstName)
            ->and($fields[1]->name)->toBe($roomName)
            ->and($fields->pluck('type')->all())->toBe(['text', 'select', 'multiselect', 'money', 'date', 'date', 'rating', 'boolean', 'number', 'tags', 'textarea', 'photo', 'file'])
            ->and($fields[0]->required)->toBeTrue()
            ->and($fields[1]->required)->toBeFalse();
    })->with([
        'English' => ['en', 'Name', 'Room'],
        'French' => ['fr', 'Nom', 'Pièce'],
    ]);

    it('makes the fields as a person would make them by hand', function (): void {
        $team = inventoryTeam();

        $id = createFromTemplate($this, $team, 'sample-home')->assertCreated()->json('data.id');
        $fields = Inventory::query()->where('ulid', $id)->sole()->fields()->get()->keyBy('name');

        // The choices get the ids the server makes, the colors and the labels of the language.
        $room = $fields['Room']->options['choices'];

        expect(array_column($room, 'label'))->toBe(['Kitchen', 'Living room', 'Bedroom', 'Garage'])
            ->and(array_column($room, 'color'))->toBe(['blue', 'green', 'purple'])
            ->and($room[3])->not->toHaveKey('color')
            ->and($room[0]['id'])->toMatch('/^[a-z0-9]{8}$/D')
            ->and(array_unique(array_column($room, 'id')))->toHaveCount(4)
            // The options are the canonical form of the type: the defaults are filled in.
            ->and($fields['Purchase price']->options)->toBe(['currency' => 'EUR', 'decimals' => 2])
            ->and($fields['Condition']->options)->toBe(['max' => 5])
            ->and($fields['Weight']->options)->toMatchArray(['decimals' => 1, 'min' => '0', 'unit' => 'kg'])
            ->and($fields['Photo']->options)->toBe(['max_files' => 3])
            ->and($fields['Tags']->options)->toBeNull();

        // The same field made by hand has the same options (but for the ids of the choices).
        $manual = inventoryIn($team['workspace'], 'By hand');

        expect(fieldIn($manual, 'money', 'Price', ['currency' => 'EUR'])->options)->toBe($fields['Purchase price']->options);
    });

    it('makes inventories that can be used: the examples of the template are valid items', function (): void {
        $team = inventoryTeam();
        $id = createFromTemplate($this, $team, 'sample-shop', headers: ['Accept-Language' => 'fr'])->assertCreated()->json('data.id');
        $inventory = Inventory::query()->where('ulid', $id)->sole();
        $template = app(TemplateCatalogue::class)->find('sample-shop');
        $fields = $inventory->fields()->get()->keyBy('name');

        foreach ($template->examples('fr') as $row) {
            $values = [];

            foreach ($template->fields('fr') as $definition) {
                if (! array_key_exists($definition->key, $row)) {
                    continue;
                }

                $field = $fields[$definition->name];
                $value = $row[$definition->key];

                if ($definition->choices !== []) {
                    $position = array_search($value, array_column($definition->choices, 'key'), true);
                    $value = $field->options['choices'][$position]['id'];
                }

                $values[$field->ulid] = $value;
            }

            inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$id}/items", ['values' => $values])->assertCreated();
        }

        expect($inventory->refresh()->items_count)->toBe(3);
    });

    it('is possible for editors, and not for viewers, strangers and guests', function (string $role, int $status): void {
        $team = inventoryTeam();

        createFromTemplate($this, $team, 'sample-tools', as: $role)->assertStatus($status);

        expect(Inventory::query()->count())->toBe($status === 201 ? 1 : 0);
    })->with([['owner', 201], ['editor', 201], ['viewer', 403], ['stranger', 404]]);

    it('needs an account', function (): void {
        $team = inventoryTeam();

        $this->postJson("/api/v1/workspaces/{$team['workspace']->ulid}/inventories", ['name' => 'X', 'template' => 'sample-tools'])->assertUnauthorized();
    });

    it('still needs a name', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories", ['template' => 'sample-tools'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        expect(Inventory::query()->count())->toBe(0);
    });

    it('is optional: without a template the inventory is empty, as before', function (mixed $template): void {
        $team = inventoryTeam();

        createFromTemplate($this, $team, 'ignored', ['template' => $template])->assertCreated()->assertJsonPath('data.fields_count', 0);
    })->with(['null' => [null], 'an empty text' => ['']]);

    it('is a 422 for a template that does not exist', function (mixed $template): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories", ['name' => 'X', 'template' => $template])
            ->assertUnprocessable()->assertJsonValidationErrors('template');

        expect(Inventory::query()->count())->toBe(0);
    })->with(['an unknown slug' => ['nothing'], 'capitals' => ['SAMPLE-TOOLS'], 'a number' => [12], 'a list' => [['sample-tools']], 'too long' => [str_repeat('a', 300)]]);

    it('is a 422, never an empty inventory, when the catalogue loses the template between the validation and the use', function (): void {
        $request = SaveInventoryRequest::create('/api/v1/workspaces/any/inventories', 'POST', ['name' => 'Shop', 'template' => 'sample-shop']);
        $validator = Validator::make($request->all(), $request->rules());

        expect($validator->passes())->toBeTrue()
            ->and($request->template()?->slug)->toBe('sample-shop');

        // The file went away, or the cache was emptied, between the rule and the use.
        templateCatalogueFrom(storage_path('framework/testing/no-such-folder'));

        expect(app(TemplateCatalogue::class)->find('sample-shop'))->toBeNull()
            ->and(fn () => $request->template())->toThrow(ValidationException::class, "Ce modèle n'existe pas.");
    });

    it('is never an empty inventory for a template that is named and cannot be found: it is a 422 like the validation', function (): void {
        $request = SaveInventoryRequest::create('/api/v1/workspaces/any/inventories', 'POST', ['name' => 'Shop', 'template' => 'nothing']);

        expect(fn () => $request->template())->toThrow(ValidationException::class, "Ce modèle n'existe pas.");

        $none = SaveInventoryRequest::create('/api/v1/workspaces/any/inventories', 'POST', ['name' => 'Shop']);

        expect($none->template())->toBeNull();
    });

    it('cannot be asked for when an inventory is changed', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/inventories/{$inventory->ulid}", ['template' => 'sample-tools'])
            ->assertUnprocessable()->assertJsonValidationErrors('template');
    });
});

describe('the quotas', function (): void {
    it('answer 409 when the workspace holds as many inventories as allowed, and make nothing', function (): void {
        $team = inventoryTeam();
        config(['inventories.max_per_workspace' => 1]);
        inventoryIn($team['workspace']);

        createFromTemplate($this, $team, 'sample-tools')->assertStatus(409)->assertJson(['code' => 'inventory_limit_reached', 'limit' => 1]);

        expect(Inventory::query()->count())->toBe(1)->and(InventoryField::query()->count())->toBe(0);
    });

    it('answer 409 (field_limit_reached) when the template has more fields than an inventory may hold, and make nothing', function (): void {
        $team = inventoryTeam();
        config(['inventories.max_fields' => 7]);

        createFromTemplate($this, $team, 'sample-shop', headers: ['Accept-Language' => 'fr'])
            ->assertStatus(409)
            ->assertJson(['code' => 'field_limit_reached', 'limit' => 7, 'message' => 'Un inventaire ne peut pas avoir plus de 7 champs.']);

        expect(Inventory::query()->count())->toBe(0)->and(InventoryField::query()->count())->toBe(0);

        // The limit is the number of fields: a template that fits exactly is made.
        config(['inventories.max_fields' => 8]);

        createFromTemplate($this, $team, 'sample-shop')->assertCreated();

        expect(InventoryField::query()->count())->toBe(8);
    });

    it('count the fields an inventory may hold for each inventory', function (): void {
        $team = inventoryTeam();
        config(['inventories.max_fields' => 8]);

        createFromTemplate($this, $team, 'sample-shop')->assertCreated();
        createFromTemplate($this, $team, 'sample-shop')->assertCreated();

        expect(Inventory::query()->count())->toBe(2)->and(InventoryField::query()->count())->toBe(16);
    });
});

describe('the transaction', function (): void {
    /**
     * A template whose last field cannot be created: two fields have the same name in English.
     */
    function templateWithAClashingLastField(): Template
    {
        $data = templateData('sample-tools');
        $data['fields'][5]['name']['en'] = 'tool';

        return Template::fromArray('clash', $data);
    }

    it('makes the inventory and all of its fields, or nothing', function (): void {
        $this->useLanguage('en');
        $team = inventoryTeam();

        expect(fn () => app(Inventories::class)->createFromTemplate($team['workspace'], templateWithAClashingLastField(), 'Clash', null, 'en'))
            ->toThrow(ValidationException::class, 'This inventory already has a field with this name.');

        expect(Inventory::query()->count())->toBe(0)->and(InventoryField::query()->count())->toBe(0);
    });

    it('leaves nothing either when the first field is the one that fails', function (): void {
        $team = inventoryTeam();
        $data = templateData('sample-tools');
        // A choice field without a choice: the options are refused when the field is made.
        $data['fields'][0]['type'] = 'select';
        $data['fields'][0]['options'] = [];

        expect(fn () => app(Inventories::class)->createFromTemplate($team['workspace'], Template::fromArray('broken', $data), 'Broken', null, 'en'))
            ->toThrow(ValidationException::class);

        expect(Inventory::query()->count())->toBe(0);
    });

    it('refuses a required field that is filled by uploading files, whichever way the field is added, and makes nothing', function (string $type): void {
        $this->useLanguage('en');
        $team = inventoryTeam();
        $data = templateData('sample-tools');
        // The validator would never let this file in: the template is built by hand to reach the creation.
        $data['fields'][5]['type'] = $type;
        $data['fields'][5]['required'] = true;

        expect(fn () => app(Inventories::class)->createFromTemplate($team['workspace'], Template::fromArray('uploads', $data), 'Uploads', null, 'en'))
            ->toThrow(ValidationException::class, 'A field filled by uploading files cannot be required.');

        expect(Inventory::query()->count())->toBe(0)->and(InventoryField::query()->count())->toBe(0);

        $inventory = inventoryIn($team['workspace']);

        expect(fn () => fieldIn($inventory, $type, 'Attachments', [], true))->toThrow(ValidationException::class, 'A field filled by uploading files cannot be required.')
            ->and(fieldIn($inventory, $type, 'Attachments')->required)->toBeFalse();
    })->with(['file', 'photo']);

    it('keeps the behaviour of a field added by hand: position, quota and name', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        // The factored insertion keeps the behaviour of a single field: position, quota and name check.
        $first = fieldIn($inventory, 'text', 'One');
        $second = fieldIn($inventory, 'text', 'Two');

        expect([$first->position, $second->position])->toBe([0, 1]);

        config(['inventories.max_fields' => 2]);

        expect(fn () => fieldIn($inventory, 'text', 'Three'))->toThrow(FieldLimitReachedException::class);
        expect(fn () => fieldIn($inventory, 'text', 'one'))->toThrow(FieldLimitReachedException::class);

        config(['inventories.max_fields' => 3]);

        expect(fn () => fieldIn($inventory, 'text', 'ONE'))->toThrow(ValidationException::class);
    });
});
