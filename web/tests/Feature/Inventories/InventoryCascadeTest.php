<?php

declare(strict_types=1);

use App\Admin\UserAdministration;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/**
 * Puts an inventory with a field and an item in a workspace.
 *
 * @return array{inventory: Inventory, field: InventoryField, item: Item}
 */
function inventoryWithContent(Workspace $workspace, string $name = 'Tools', ?User $author = null): array
{
    $inventory = inventoryIn($workspace, $name);
    $field = fieldIn($inventory, 'text', 'Name');

    return ['inventory' => $inventory, 'field' => $field, 'item' => itemIn($inventory, [$field->ulid => 'Hammer'], $author)];
}

describe('deleting a workspace', function (): void {
    it('deletes its inventories with their fields and items, through the API, and no other', function (): void {
        $team = inventoryTeam();
        inventoryWithContent($team['workspace']);
        inventoryWithContent($team['workspace'], 'More');
        $kept = inventoryWithContent($team['owner']->workspaces()->orderBy('workspace_user.id')->firstOrFail());
        $strangers = inventoryWithContent($team['stranger']->workspaces()->sole());

        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/workspaces/{$team['workspace']->ulid}")->assertNoContent();

        expect(Inventory::query()->orderBy('id')->pluck('id')->all())->toBe([$kept['inventory']->id, $strangers['inventory']->id])
            ->and(InventoryField::query()->count())->toBe(2)
            ->and(Item::query()->count())->toBe(2);
    });

    it('does it through the service too, which deletes the workspace through its own model', function (): void {
        $team = inventoryTeam();
        inventoryWithContent($team['workspace']);

        app(Workspaces::class)->delete($team['owner'], $team['workspace']);

        expect(Inventory::query()->count())->toBe(0)
            ->and(InventoryField::query()->count())->toBe(0)
            ->and(Item::query()->count())->toBe(0);
    });
});

describe('deleting an account', function (): void {
    it('deletes the inventories of the workspaces nobody else belongs to', function (): void {
        $person = inventoryPerson('Alone');
        $personal = $person->workspaces()->sole();
        inventoryWithContent($personal);
        $stranger = inventoryWithContent(inventoryPerson('Other')->workspaces()->sole());

        $person->delete();

        expect(Inventory::query()->pluck('id')->all())->toBe([$stranger['inventory']->id])
            ->and(InventoryField::query()->count())->toBe(1)
            ->and(Item::query()->count())->toBe(1);
    });

    it('leaves the inventories of a shared workspace, and forgets who wrote the items', function (): void {
        $team = inventoryTeam();
        $content = inventoryWithContent($team['workspace'], 'Tools', $team['editor']);
        $content['item']->forceFill(['updated_by' => $team['viewer']->id])->save();

        $team['editor']->delete();

        expect(Inventory::query()->count())->toBe(1)
            ->and(Item::query()->count())->toBe(1)
            ->and($content['item']->fresh()->created_by)->toBeNull()
            ->and($content['item']->fresh()->updated_by)->toBe($team['viewer']->id);

        $team['viewer']->delete();

        expect($content['item']->fresh()->updated_by)->toBeNull();
    });

    it('is the same when an administrator deletes the account', function (): void {
        $admin = User::factory()->admin()->withPersonalWorkspace()->create();
        $person = inventoryPerson('Alone');
        inventoryWithContent($person->workspaces()->sole());

        app(UserAdministration::class)->delete($admin, $person);

        expect(Inventory::query()->count())->toBe(0)
            ->and(Item::query()->count())->toBe(0);
    });

    it('keeps the inventories of a workspace handed over to another owner', function (): void {
        $owner = inventoryPerson('Owner');
        $editor = inventoryPerson('Editor');
        $workspace = app(Workspaces::class)->create($owner, 'Shop');
        $workspace->members()->attach($editor->id, ['role' => WorkspaceRole::Editor->value]);
        $content = inventoryWithContent($workspace, 'Tools', $owner);
        $admin = User::factory()->admin()->withPersonalWorkspace()->create();

        app(UserAdministration::class)->delete($admin, $owner);

        expect(Inventory::query()->pluck('id')->all())->toBe([$content['inventory']->id])
            ->and($content['item']->fresh()->created_by)->toBeNull();
    });
});
