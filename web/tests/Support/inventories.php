<?php

declare(strict_types=1);

// Helpers shared by the tests of inventories, fields and items. Each test file loads them with require_once, so a file
// can be run alone. Their names all start with "inventory" or "field" or "item" so that they never collide with the
// helpers of tests/Pest.php or of other test files.

use App\Inventories\InventoryFields;
use App\Inventories\Items;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Testing\TestResponse;

/**
 * A person with a personal workspace.
 */
function inventoryPerson(string $name = 'Ada'): User
{
    return User::factory()->withPersonalWorkspace()->create(['name' => $name]);
}

/**
 * A workspace with one person in each role, and a stranger who belongs to none of its workspaces.
 *
 * @return array{owner: User, editor: User, viewer: User, stranger: User, workspace: Workspace}
 */
function inventoryTeam(): array
{
    $owner = inventoryPerson('Owner');
    $editor = inventoryPerson('Editor');
    $viewer = inventoryPerson('Viewer');
    $workspace = app(Workspaces::class)->create($owner, 'Shop');
    $workspace->members()->attach($editor->id, ['role' => WorkspaceRole::Editor->value]);
    $workspace->members()->attach($viewer->id, ['role' => WorkspaceRole::Viewer->value]);

    return ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'stranger' => inventoryPerson('Stranger'), 'workspace' => $workspace];
}

/**
 * An inventory in a workspace.
 */
function inventoryIn(Workspace $workspace, string $name = 'Tools'): Inventory
{
    return Inventory::factory()->for($workspace)->create(['name' => $name]);
}

/**
 * A field added to an inventory the way the application adds one (checks, canonical options, position).
 *
 * @param  array<string, mixed>  $options
 */
function fieldIn(Inventory $inventory, string $type = 'text', string $name = '', array $options = [], bool $required = false): InventoryField
{
    return app(InventoryFields::class)->create($inventory, $type, $name !== '' ? $name : ucfirst($type).' field', $options, $required);
}

/**
 * An item added the way the application adds one (validated, canonical values).
 *
 * @param  array<string, mixed>  $values  Keyed by the ULID of the field.
 */
function itemIn(Inventory $inventory, array $values = [], ?User $author = null): Item
{
    return app(Items::class)->create($inventory, $values, $author ?? inventoryPerson('Author'));
}

/**
 * A call to the API made by the web front.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function inventoryCall(object $test, ?User $as, string $method, string $uri, array $data = [], array $headers = []): TestResponse
{
    if ($as !== null) {
        // Every call starts a session of its own: the one of the previous call, whoever it was for, is not this person's.
        $test->flushSession();
        app('auth')->forgetGuards();
        $test->actingAs($as);
    }

    return $test->json($method, $uri, $data, frontend($headers));
}
