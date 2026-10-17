<?php

declare(strict_types=1);

use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../../Support/inventories.php';
require_once __DIR__.'/../../../Support/tokens.php';

uses(RefreshDatabase::class);

/**
 * A workspace with a person in each role, an inventory with a text field and an item.
 *
 * @return array{owner: User, editor: User, viewer: User, stranger: User, workspace: Workspace, inventory: Inventory, field: InventoryField, item: Item}
 */
function tokenWorld(): array
{
    $team = inventoryTeam();
    $inventory = inventoryIn($team['workspace']);
    $field = fieldIn($inventory, 'text', 'Name');
    $item = itemIn($inventory, [$field->ulid => 'Hammer'], $team['owner']);

    return $team + ['inventory' => $inventory, 'field' => $field, 'item' => $item];
}

describe('a token with an ability', function (): void {
    it('reads with "read" and does not write or manage', function (): void {
        $world = tokenWorld();
        $token = tokenFor($world['owner'], ['read']);
        $inventory = $world['inventory']->ulid;

        tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk()->assertJsonPath('data.id', $world['owner']->id);
        tokenCall($this, $token, 'GET', '/api/v1/workspaces')->assertOk();
        tokenCall($this, $token, 'GET', "/api/v1/workspaces/{$world['workspace']->ulid}/inventories")->assertOk();
        tokenCall($this, $token, 'GET', "/api/v1/inventories/{$inventory}")->assertOk()->assertJsonPath('data.id', $inventory);
        tokenCall($this, $token, 'GET', "/api/v1/inventories/{$inventory}/items")->assertOk()->assertJsonCount(1, 'data');
        tokenCall($this, $token, 'GET', "/api/v1/inventories/{$inventory}/items/{$world['item']->ulid}")->assertOk();
        tokenCall($this, $token, 'GET', '/api/v1/field-types')->assertOk();

        tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory}/items", ['values' => [$world['field']->ulid => 'Saw']])
            ->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, $token, 'PATCH', "/api/v1/inventories/{$inventory}", ['name' => 'Renamed'])
            ->assertForbidden()->assertJsonPath('code', 'token_ability_missing');

        expect(Item::query()->count())->toBe(1)->and($world['inventory']->fresh()?->name)->toBe('Tools');
    });

    it('writes items with "write", and neither reads nor manages', function (): void {
        $world = tokenWorld();
        $token = tokenFor($world['owner'], ['write']);
        $inventory = $world['inventory']->ulid;

        $created = tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory}/items", ['values' => [$world['field']->ulid => 'Saw']])
            ->assertCreated();
        $item = $created->json('data.id');

        tokenCall($this, $token, 'PATCH', "/api/v1/inventories/{$inventory}/items/{$item}", ['values' => [$world['field']->ulid => 'Chisel']])->assertOk();
        tokenCall($this, $token, 'DELETE', "/api/v1/inventories/{$inventory}/items/{$item}")->assertNoContent();

        tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory}/fields", ['type' => 'text', 'name' => 'Colour'])
            ->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, $token, 'GET', "/api/v1/inventories/{$inventory}/items")
            ->assertForbidden()->assertJsonPath('code', 'token_ability_missing');

        expect(InventoryField::query()->count())->toBe(1)->and(Item::query()->count())->toBe(1);
    });

    it('manages workspaces, inventories and fields with "manage", and does not touch the items', function (): void {
        $world = tokenWorld();
        $token = tokenFor($world['owner'], ['manage']);
        $inventory = $world['inventory']->ulid;

        tokenCall($this, $token, 'POST', '/api/v1/workspaces', ['name' => 'Garage'])->assertCreated();
        tokenCall($this, $token, 'POST', "/api/v1/workspaces/{$world['workspace']->ulid}/inventories", ['name' => 'Books'])->assertCreated();
        tokenCall($this, $token, 'PATCH', "/api/v1/inventories/{$inventory}", ['name' => 'Renamed'])->assertOk();
        tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory}/fields", ['type' => 'text', 'name' => 'Colour'])->assertCreated();

        tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory}/items", ['values' => [$world['field']->ulid => 'Saw']])
            ->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, $token, 'DELETE', "/api/v1/inventories/{$inventory}/items/{$world['item']->ulid}")
            ->assertForbidden()->assertJsonPath('code', 'token_ability_missing');

        expect(Item::query()->count())->toBe(1)->and($world['inventory']->fresh()?->name)->toBe('Renamed');
    });

    it('does everything a token can with "*", the way a token made before the abilities did', function (): void {
        $world = tokenWorld();
        $token = tokenFor($world['owner'], ['*']);
        $inventory = $world['inventory']->ulid;

        tokenCall($this, $token, 'GET', "/api/v1/inventories/{$inventory}/items")->assertOk();
        tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory}/items", ['values' => [$world['field']->ulid => 'Saw']])->assertCreated();
        tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory}/fields", ['type' => 'text', 'name' => 'Colour'])->assertCreated();
    });

    it('has the abilities it was given and no more: one of three is not two', function (): void {
        $world = tokenWorld();
        $token = tokenFor($world['owner'], ['read', 'write']);
        $inventory = $world['inventory']->ulid;

        tokenCall($this, $token, 'GET', "/api/v1/inventories/{$inventory}/items")->assertOk();
        tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory}/items", ['values' => [$world['field']->ulid => 'Saw']])->assertCreated();
        tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory}/fields", ['type' => 'text', 'name' => 'Colour'])
            ->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
    });

    it('says which permission is missing, in the language of the request', function (): void {
        $world = tokenWorld();
        $token = tokenFor($world['owner'], ['read']);
        $uri = "/api/v1/inventories/{$world['inventory']->ulid}/items";

        tokenCall($this, $token, 'POST', $uri, headers: ['Accept-Language' => 'fr'])->assertForbidden()->assertExactJson([
            'message' => "Ce jeton d'API n'a pas la permission « write ».",
            'code' => 'token_ability_missing',
        ]);
        tokenCall($this, $token, 'POST', $uri, headers: ['Accept-Language' => 'en'])->assertForbidden()->assertExactJson([
            'message' => 'This API token lacks the write permission.',
            'code' => 'token_ability_missing',
        ]);
    });
});

describe('the abilities of a token, route by route', function (): void {
    it('are asked for on every route that needs one, before anything else is looked up', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $failures = [];

        foreach (tokenRouteClassification() as $name => $needed) {
            $route = apiRoutes()[$name];
            $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $uri = tokenRouteUri($route);

            // A token with the two other abilities is refused, for an inventory that does not exist as for one that does.
            $others = array_values(array_diff(['read', 'write', 'manage'], [$needed]));
            $refused = tokenCall($this, tokenFor($user, $others), $method, $uri);

            if ($refused->status() !== 403 || $refused->json('code') !== 'token_ability_missing') {
                $failures[] = "{$name}: a token without {$needed} got {$refused->status()}";
            }

            // The one with the ability gets past the check (what it gets then is the route's business: 404 for a row made up).
            $allowed = tokenCall($this, tokenFor($user, [$needed]), $method, $uri);

            if ($allowed->status() === 401 || $allowed->json('code') === 'token_ability_missing') {
                $failures[] = "{$name}: a token with {$needed} got {$allowed->status()}";
            }
        }

        expect($failures)->toBe([]);
    });
});

describe('a token', function (): void {
    it('never goes beyond the role of its owner: a viewer with every ability cannot write', function (): void {
        $world = tokenWorld();
        $token = tokenFor($world['viewer'], ['*']);
        $inventory = $world['inventory']->ulid;

        tokenCall($this, $token, 'GET', "/api/v1/inventories/{$inventory}/items")->assertOk();

        $refused = tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory}/items", ['values' => [$world['field']->ulid => 'Saw']])->assertForbidden();
        tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory}/fields", ['type' => 'text', 'name' => 'Colour'])->assertForbidden();
        tokenCall($this, $token, 'DELETE', "/api/v1/inventories/{$inventory}")->assertForbidden();

        expect($refused->json('code'))->not->toBe('token_ability_missing')
            ->and(Item::query()->count())->toBe(1)->and(InventoryField::query()->count())->toBe(1)->and(Inventory::query()->count())->toBe(1);
    });

    it('never goes beyond the role of its owner: an editor with every ability cannot do what only owners do', function (): void {
        $world = tokenWorld();
        $token = tokenFor($world['editor'], ['*']);
        $inventory = $world['inventory']->ulid;

        tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory}/items", ['values' => [$world['field']->ulid => 'Saw']])->assertCreated();
        tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory}/fields", ['type' => 'text', 'name' => 'Colour'])->assertCreated();
        tokenCall($this, $token, 'DELETE', "/api/v1/inventories/{$inventory}")->assertForbidden();
        tokenCall($this, $token, 'PATCH', "/api/v1/workspaces/{$world['workspace']->ulid}", ['name' => 'Mine now'])->assertForbidden();

        expect(Inventory::query()->count())->toBe(1)->and($world['workspace']->fresh()?->name)->toBe('Shop');
    });

    it('does not see what its owner does not: the inventory of a stranger is a 404', function (): void {
        $world = tokenWorld();
        $token = tokenFor($world['stranger'], ['*']);

        tokenCall($this, $token, 'GET', "/api/v1/inventories/{$world['inventory']->ulid}")->assertNotFound();
        tokenCall($this, $token, 'GET', "/api/v1/inventories/{$world['inventory']->ulid}/items")->assertNotFound();
    });

    it('is told what it lacks whether or not the row exists: the ability is no way to find out', function (): void {
        $world = tokenWorld();
        $token = tokenFor($world['stranger'], ['write']);

        foreach ([$world['inventory']->ulid, '01j0000000000000000000000x'] as $inventory) {
            tokenCall($this, $token, 'GET', "/api/v1/inventories/{$inventory}")
                ->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        }
    });
});

describe('the web front, which has a session and no token', function (): void {
    it('is not restricted by the abilities: an owner reads, writes and manages', function (): void {
        $world = tokenWorld();
        $inventory = $world['inventory']->ulid;

        inventoryCall($this, $world['owner'], 'GET', "/api/v1/inventories/{$inventory}/items")->assertOk();
        inventoryCall($this, $world['owner'], 'POST', "/api/v1/inventories/{$inventory}/items", ['values' => [$world['field']->ulid => 'Saw']])->assertCreated();
        inventoryCall($this, $world['owner'], 'POST', "/api/v1/inventories/{$inventory}/fields", ['type' => 'text', 'name' => 'Colour'])->assertCreated();
        inventoryCall($this, $world['owner'], 'POST', '/api/v1/workspaces', ['name' => 'Garage'])->assertCreated();
    });

    it('keeps its session when the same person also has a token', function (): void {
        $world = tokenWorld();
        tokenFor($world['owner'], ['read']);

        inventoryCall($this, $world['owner'], 'POST', "/api/v1/inventories/{$world['inventory']->ulid}/items", ['values' => [$world['field']->ulid => 'Saw']])->assertCreated();
    });
});

describe('a token that is no longer good', function (): void {
    it('is refused with a 401 once it has expired, and works until then', function (): void {
        $world = tokenWorld();
        $token = tokenFor($world['owner'], ['read'], now()->addDays(7));

        tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk();

        $this->travel(7)->days();
        $this->travel(1)->minutes();

        tokenCall($this, $token, 'GET', '/api/v1/me')->assertUnauthorized();
    });

    it('is refused with a 401 when it was made to expire in the past', function (): void {
        $world = tokenWorld();

        tokenCall($this, tokenFor($world['owner'], ['read'], now()->subSecond()), 'GET', '/api/v1/me')->assertUnauthorized();
    });

    it('is refused with a 401 once it has been revoked', function (): void {
        $world = tokenWorld();
        $new = $world['owner']->createToken('script', ['read']);

        tokenCall($this, $new->plainTextToken, 'GET', '/api/v1/me')->assertOk();

        DB::table('personal_access_tokens')->where('id', $new->accessToken->id)->delete();

        tokenCall($this, $new->plainTextToken, 'GET', '/api/v1/me')->assertUnauthorized();
    });

    it('is refused with a 401 when it never existed', function (): void {
        tokenCall($this, 'inv_'.str_repeat('a', 48), 'GET', '/api/v1/me')->assertUnauthorized();
    });
});
