<?php

declare(strict_types=1);

use App\Inventories\Inventories;
use App\Inventories\InventoryFields;
use App\Inventories\Items;
use App\Models\InventoryField;
use App\Models\Item;
use App\Workspaces\Locks;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/**
 * Whether a statement asks the database to lock the rows it reads.
 */
function inventoryLockStatement(string $statement): bool
{
    return str_contains(strtolower($statement), ' for update');
}

describe('the locks on the content of a workspace', function (): void {
    it('lock the inventories asked for, lowest id first, and skip the ones that are gone', function (): void {
        $team = inventoryTeam();
        $first = inventoryIn($team['workspace'], 'First');
        $second = inventoryIn($team['workspace'], 'Second');
        $locked = null;

        $statements = statementsOf(function () use ($first, $second, &$locked): void {
            $locked = app(Locks::class)->inventories([$second->id, $first->id, $second->id, 999999]);
        });

        expect($locked->keys()->all())->toBe([$first->id, $second->id])
            ->and($statements)->toHaveCount(1)
            ->and(inventoryLockStatement($statements[0]))->toBeTrue();
    });

    it('lock nothing when asked for nothing', function (): void {
        $statements = statementsOf(fn () => app(Locks::class)->inventories([]));

        expect($statements)->toBe([])
            ->and(statementsOf(fn () => app(Locks::class)->items([])))->toBe([]);
    });

    it('lock one inventory, or fail like a missing route binding when it is gone', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        expect(app(Locks::class)->inventory($inventory->id)->is($inventory))->toBeTrue();

        $inventory->delete();

        expect(fn () => app(Locks::class)->inventory($inventory->id))->toThrow(ModelNotFoundException::class);
    });

    it('lock the fields of one inventory in the order they are shown', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $other = inventoryIn($team['workspace'], 'Other');
        $second = InventoryField::factory()->for($inventory)->create(['name' => 'Second', 'position' => 1]);
        $first = InventoryField::factory()->for($inventory)->create(['name' => 'First', 'position' => 0]);
        InventoryField::factory()->for($other)->create(['name' => 'Elsewhere']);
        $fields = null;

        $statements = statementsOf(function () use ($inventory, &$fields): void {
            $fields = app(Locks::class)->fields($inventory->id);
        });

        expect($fields->pluck('id')->all())->toBe([$first->id, $second->id])
            ->and($statements)->toHaveCount(1)
            ->and(inventoryLockStatement($statements[0]))->toBeTrue();
    });

    it('lock items by id', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $first = Item::factory()->for($inventory)->create();
        $second = Item::factory()->for($inventory)->create();
        $items = null;

        $statements = statementsOf(function () use ($first, $second, &$items): void {
            $items = app(Locks::class)->items([$second->id, $first->id, 999999]);
        });

        expect($items->keys()->all())->toBe([$first->id, $second->id])
            ->and(inventoryLockStatement($statements[0]))->toBeTrue();
    });
});

describe('the writes that depend on them', function (): void {
    it('take the lock of the account that writes, if any, then the one of the inventory, before they read anything else', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'text', 'Name');
        $second = fieldIn($inventory, 'text', 'Second');
        $item = itemIn($inventory);
        $order = [$second->ulid, $field->ulid];
        $fields = app(InventoryFields::class);
        $items = app(Items::class);

        // What each write reads first, with a lock, in this order. Ordering comes first among the field writes: it needs
        // the list to be exactly the fields there are, and the writes after it add and delete some.
        $writes = [
            'order the fields' => [fn () => $fields->reorder($inventory, $order), ['inventories']],
            'create a field' => [fn () => $fields->create($inventory, 'text', 'Other'), ['inventories']],
            'change a field' => [fn () => $fields->update($inventory, $field, ['name' => 'Renamed']), ['inventories']],
            'add an item' => [fn () => $items->create($inventory, [], $team['owner']), ['users', 'inventories']],
            'change an item' => [fn () => $items->update($inventory, $item, [$field->ulid => 'x'], $team['editor']), ['users', 'inventories']],
            'delete an item' => [fn () => $items->delete($inventory, $item), ['inventories']],
            'change the inventory' => [fn () => app(Inventories::class)->update($inventory, ['name' => 'Renamed']), ['inventories']],
            'delete a field' => [fn () => $fields->delete($inventory, $field), ['inventories']],
        ];

        foreach ($writes as $what => [$write, $tables]) {
            $statements = statementsOf($write);
            $reads = array_values(array_filter($statements, fn (string $statement): bool => str_starts_with(strtolower($statement), 'select')));

            // Nothing is read before the locks, and each of them is a locking read.
            foreach ($tables as $position => $table) {
                expect($reads[$position] ?? '')->toContain($table)
                    ->and(inventoryLockStatement($reads[$position] ?? ''))->toBeTrue("{$what}: {$table}");
            }
        }
    });

    it('refuse an account that was deleted meanwhile, instead of failing on a foreign key', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $ghost = inventoryPerson('Ghost');
        $item = itemIn($inventory);
        $ghost->delete();

        expect(fn () => app(Items::class)->create($inventory, [], $ghost))->toThrow(ModelNotFoundException::class)
            ->and(fn () => app(Items::class)->update($inventory, $item, [], $ghost))->toThrow(ModelNotFoundException::class)
            ->and(Item::query()->count())->toBe(1);
    });

    it('lock the workspace, not the inventory, to add an inventory', function (): void {
        $team = inventoryTeam();

        $statements = statementsOf(fn () => app(Inventories::class)->create($team['workspace'], 'More', null));
        $reads = array_values(array_filter($statements, fn (string $statement): bool => str_starts_with(strtolower($statement), 'select')));

        expect($reads[0])->toContain('workspaces')->and(inventoryLockStatement($reads[0]))->toBeTrue();
    });
});
