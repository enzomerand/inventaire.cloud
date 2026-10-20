<?php

declare(strict_types=1);

use App\Models\Inventory;
use App\Models\Workspace;
use App\Policies\InventoryPolicy;
use App\Workspaces\WorkspaceRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

describe('what each role may do', function (): void {
    it('follows the role of the member in the workspace', function (WorkspaceRole $role, array $expected): void {
        expect(InventoryPolicy::abilities($role))->toBe($expected);
    })->with([
        'an owner' => [WorkspaceRole::Owner, ['view' => true, 'create' => true, 'update' => true, 'delete' => true, 'manage_fields' => true, 'edit_items' => true, 'share' => true]],
        'an editor' => [WorkspaceRole::Editor, ['view' => true, 'create' => true, 'update' => true, 'delete' => false, 'manage_fields' => true, 'edit_items' => true, 'share' => false]],
        'a viewer' => [WorkspaceRole::Viewer, ['view' => true, 'create' => false, 'update' => false, 'delete' => false, 'manage_fields' => false, 'edit_items' => false, 'share' => false]],
    ]);

    it('is what the gate answers for the members, action by action', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $actions = ['view' => 'view', 'update' => 'update', 'delete' => 'delete', 'manageFields' => 'manage_fields', 'editItems' => 'edit_items', 'share' => 'share'];

        foreach (['owner' => WorkspaceRole::Owner, 'editor' => WorkspaceRole::Editor, 'viewer' => WorkspaceRole::Viewer] as $who => $role) {
            $abilities = InventoryPolicy::abilities($role);

            foreach ($actions as $gateAbility => $ability) {
                expect(Gate::forUser($team[$who])->allows($gateAbility, $inventory))->toBe($abilities[$ability], "{$who} / {$gateAbility}");
            }

            expect(Gate::forUser($team[$who])->allows('create', [Inventory::class, $team['workspace']]))->toBe($abilities['create'], "{$who} / create");
        }
    });

    it('answers a member who lacks the right with a reason, and a stranger as if there were nothing', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $viewer = Gate::forUser($team['viewer'])->inspect('update', $inventory);
        $editor = Gate::forUser($team['editor'])->inspect('delete', $inventory);

        expect($viewer->allowed())->toBeFalse()->and($viewer->message())->not->toBe('')->and($viewer->status())->toBeNull()
            ->and($editor->allowed())->toBeFalse()->and($editor->message())->toBe('Seul un propriétaire de cet espace de travail peut supprimer un inventaire.')
            ->and(Gate::forUser($team['editor'])->inspect('share', $inventory)->message())->toBe('Seul un propriétaire de cet espace de travail peut partager un inventaire.');

        foreach (['view', 'update', 'delete', 'manageFields', 'editItems', 'share'] as $ability) {
            $stranger = Gate::forUser($team['stranger'])->inspect($ability, $inventory);

            expect($stranger->allowed())->toBeFalse()->and($stranger->status())->toBe(404, $ability);
        }

        expect(Gate::forUser($team['stranger'])->inspect('create', [Inventory::class, $team['workspace']])->status())->toBe(404);
    });

    it('takes a role this version does not know for a viewer', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        DB::table('workspace_user')->where('user_id', $team['editor']->id)->where('workspace_id', $team['workspace']->id)->update(['role' => 'moderator']);

        expect(Gate::forUser($team['editor'])->allows('view', $inventory))->toBeTrue()
            ->and(Gate::forUser($team['editor'])->allows('editItems', $inventory))->toBeFalse()
            ->and(Gate::forUser($team['editor'])->allows('create', [Inventory::class, $team['workspace']]))->toBeFalse();
    });

    it('depends on the workspace of the inventory, not on the other workspaces of the person', function (): void {
        $team = inventoryTeam();
        $other = Workspace::factory()->create();
        $theirs = inventoryIn($other);
        // An owner here is nobody there.
        expect(Gate::forUser($team['owner'])->inspect('view', $theirs)->status())->toBe(404);
    });
});
