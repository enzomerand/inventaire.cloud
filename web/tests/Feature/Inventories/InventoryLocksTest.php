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
    it('lock the account that writes, if any, then the inventory, then the item, and nothing else: the fields are only read', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'text', 'Name');
        $second = fieldIn($inventory, 'text', 'Second');
        $item = itemIn($inventory);
        $order = [$second->ulid, $field->ulid];
        $fields = app(InventoryFields::class);
        $items = app(Items::class);

        // What each write locks, in this order. Ordering comes first among the field writes: it needs the list to be
        // exactly the fields there are, and the writes after it add and delete some.
        $writes = [
            'order the fields' => [fn () => $fields->reorder($inventory, $order), ['inventories']],
            'create a field' => [fn () => $fields->create($inventory, 'text', 'Other'), ['inventories']],
            'change a field' => [fn () => $fields->update($inventory, $field, ['name' => 'Renamed']), ['inventories']],
            'add an item' => [fn () => $items->create($inventory, [], $team['owner']), ['users', 'inventories']],
            'change an item' => [fn () => $items->update($inventory, $item, [$field->ulid => 'x'], $team['editor']), ['users', 'inventories', 'items']],
            // The workspace first: the files of the item are counted out of its storage (see Locks).
            'delete an item' => [fn () => $items->delete($inventory, $item), ['workspaces', 'inventories', 'items']],
            'change the inventory' => [fn () => app(Inventories::class)->update($inventory, ['name' => 'Renamed']), ['inventories']],
            'delete a field' => [fn () => $fields->delete($inventory, $field), ['inventories']],
        ];

        foreach ($writes as $what => [$write, $locked]) {
            $reads = array_values(array_filter(statementsOf($write), fn (string $statement): bool => str_starts_with(strtolower($statement), 'select')));
            $locking = array_values(array_filter($reads, inventoryLockStatement(...)));
            $tables = array_map(fn (string $statement): string => preg_match('/from\s+["`]?(\w+)["`]?/i', $statement, $found) === 1 ? $found[1] : '?', $locking);

            // Exactly these rows are locked, in this order: a locking read of the fields would lock the gap after the last of
            // them on MySQL and MariaDB, where the first field of the next inventory goes.
            expect($tables)->toBe($locked, $what);

            // Nothing is read before the locks: the first plain read opens the snapshot of the transaction.
            expect(array_slice($reads, 0, count($locking)))->toBe($locking, $what);
        }
    });

    it('read the fields of an inventory without locking them, in the order they are shown', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $second = InventoryField::factory()->for($inventory)->create(['name' => 'Second', 'position' => 1]);
        $first = InventoryField::factory()->for($inventory)->create(['name' => 'First', 'position' => 0]);
        InventoryField::factory()->for(inventoryIn($team['workspace'], 'Other'))->create(['name' => 'Elsewhere']);
        $fields = null;

        $statements = statementsOf(function () use ($inventory, &$fields): void {
            $fields = $inventory->fields()->get();
        });

        expect($fields->pluck('id')->all())->toBe([$first->id, $second->id])
            ->and($statements)->toHaveCount(1)
            ->and(inventoryLockStatement($statements[0]))->toBeFalse();
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
