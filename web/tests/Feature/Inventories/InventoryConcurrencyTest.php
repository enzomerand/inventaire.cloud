<?php

declare(strict_types=1);

use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsDatabaseChanges;

require_once __DIR__.'/../../Support/inventories.php';

/**
 * The counter of items kept on an inventory, as the database holds it (not as a model read earlier says).
 */
function concurrencyItemsCount(Inventory $inventory): int
{
    return (int) DB::table('inventories')->where('id', $inventory->id)->value('items_count');
}

/*
 * What several requests do to one inventory at the same moment, in separate PHP processes against the real database (a
 * single process cannot show it: see tests/Feature/Admin/AdminConcurrencyTest.php). Every write to an inventory locks it
 * first, so that the quotas, the uniqueness of a field name, the deletion of a field and the merge of an update are judged
 * one request after the other, on every engine. Each scenario is repeated, as one lucky ordering proves nothing.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(fn () => removeConcurrencyRows());
afterEach(fn () => removeConcurrencyRows());

describe('requests for the last place of a quota', function (): void {
    it('give it to one of two items created at the same moment', function (): void {
        foreach (range(1, 4) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);
            itemIn($inventory);
            itemIn($inventory);

            $outcomes = actTogether($this->sandbox->root, [
                ['create-item', $team['owner']->id, $inventory->id],
                ['create-item', $team['editor']->id, $inventory->id],
            ], ['INVENTORIES_MAX_ITEMS' => '3']);

            expect($outcomes)->toBe(['created', 'refused:item_limit_reached'])
                ->and(Item::query()->where('inventory_id', $inventory->id)->count())->toBe(3)
                ->and(concurrencyItemsCount($inventory))->toBe(3);

            removeConcurrencyRows();
        }
    });

    it('give it to one of several items created at the same moment', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        itemIn($inventory);

        $outcomes = actTogether($this->sandbox->root, array_fill(0, 5, ['create-item', $team['owner']->id, $inventory->id]), ['INVENTORIES_MAX_ITEMS' => '2']);

        expect($outcomes)->toBe(['created', 'refused:item_limit_reached', 'refused:item_limit_reached', 'refused:item_limit_reached', 'refused:item_limit_reached'])
            ->and(Item::query()->where('inventory_id', $inventory->id)->count())->toBe(2)
            ->and(concurrencyItemsCount($inventory))->toBe(2);
    });

    it('give it to one of two inventories created at the same moment', function (): void {
        foreach (range(1, 4) as $_) {
            $team = inventoryTeam();
            inventoryIn($team['workspace'], 'First');

            $outcomes = actTogether($this->sandbox->root, [
                ['create-inventory', $team['owner']->id, $team['workspace']->id, 'Second'],
                ['create-inventory', $team['editor']->id, $team['workspace']->id, 'Third'],
            ], ['INVENTORIES_MAX_PER_WORKSPACE' => '2']);

            expect($outcomes)->toBe(['created', 'refused:inventory_limit_reached'])
                ->and(Inventory::query()->where('workspace_id', $team['workspace']->id)->count())->toBe(2);

            removeConcurrencyRows();
        }
    });

    it('give it to one of two fields created at the same moment', function (): void {
        foreach (range(1, 4) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);
            fieldIn($inventory, 'text', 'First');

            $outcomes = actTogether($this->sandbox->root, [
                ['create-field', $inventory->id, 'Second'],
                ['create-field', $inventory->id, 'Third'],
            ], ['INVENTORIES_MAX_FIELDS' => '2']);

            expect($outcomes)->toBe(['created', 'refused:field_limit_reached'])
                ->and(InventoryField::query()->where('inventory_id', $inventory->id)->count())->toBe(2);

            removeConcurrencyRows();
        }
    });
});

describe('items created and deleted at the same moment', function (): void {
    it('are counted one after the other: the counter is the number of items that are there', function (): void {
        foreach (range(1, 4) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);
            $items = [itemIn($inventory), itemIn($inventory), itemIn($inventory), itemIn($inventory)];

            $outcomes = actTogether($this->sandbox->root, [
                ['delete-item', $inventory->id, $items[0]->id],
                ['delete-item', $inventory->id, $items[1]->id],
            ]);

            expect($outcomes)->toBe(['deleted', 'deleted'])
                ->and(Item::query()->where('inventory_id', $inventory->id)->count())->toBe(2)
                ->and(Item::query()->whereKey([$items[0]->id, $items[1]->id])->exists())->toBeFalse()
                ->and(concurrencyItemsCount($inventory))->toBe(2);

            removeConcurrencyRows();
        }
    });

    it('leave a counter that matches the items when an item is added as another is deleted', function (): void {
        foreach (range(1, 4) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);
            $kept = itemIn($inventory);
            $gone = itemIn($inventory);

            $outcomes = actTogether($this->sandbox->root, [
                ['delete-item', $inventory->id, $gone->id],
                ['create-item', $team['owner']->id, $inventory->id],
            ]);

            expect($outcomes)->toBe(['created', 'deleted'])
                ->and(Item::query()->where('inventory_id', $inventory->id)->count())->toBe(2)
                ->and(Item::query()->whereKey($kept->id)->exists())->toBeTrue()
                ->and(concurrencyItemsCount($inventory))->toBe(2);

            removeConcurrencyRows();
        }
    });
});

describe('fields created at the same moment', function (): void {
    it('cannot share a name, whatever its capitals', function (): void {
        foreach (range(1, 4) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);

            $outcomes = actTogether($this->sandbox->root, [
                ['create-field', $inventory->id, 'Weight'],
                ['create-field', $inventory->id, 'WEIGHT'],
            ]);

            expect($outcomes)->toBe(['created', 'refused:invalid:name'])
                ->and(InventoryField::query()->where('inventory_id', $inventory->id)->count())->toBe(1);

            removeConcurrencyRows();
        }
    });

    it('get a place each, one after the other', function (): void {
        foreach (range(1, 4) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);

            $outcomes = actTogether($this->sandbox->root, [
                ['create-field', $inventory->id, 'One'],
                ['create-field', $inventory->id, 'Two'],
                ['create-field', $inventory->id, 'Three'],
            ]);

            expect($outcomes)->toBe(['created', 'created', 'created'])
                ->and($inventory->fields()->pluck('position')->all())->toBe([0, 1, 2]);

            removeConcurrencyRows();
        }
    });
});

describe('a field deleted while an item is written', function (): void {
    it('leaves no value behind: the item either went first and lost the value, or was refused', function (): void {
        foreach (range(1, 6) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);
            $field = fieldIn($inventory, 'text', 'Name');

            $outcomes = actTogether($this->sandbox->root, [
                ['delete-field', $inventory->id, $field->id],
                ['create-item', $team['owner']->id, $inventory->id, $field->ulid, 'Hammer'],
            ]);

            expect($outcomes)->toBeIn([['created', 'deleted'], ['deleted', 'refused:invalid:values.'.$field->ulid]])
                ->and(InventoryField::query()->whereKey($field->id)->exists())->toBeFalse()
                ->and(Item::query()->get()->contains(fn (Item $item): bool => array_key_exists($field->ulid, $item->values)))->toBeFalse()
                ->and(DB::table('items')->where('values', 'like', '%'.$field->ulid.'%')->count())->toBe(0);

            removeConcurrencyRows();
        }
    });

    it('leaves no value behind when an update writes it at the same moment', function (): void {
        foreach (range(1, 6) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);
            $field = fieldIn($inventory, 'text', 'Name');
            $item = itemIn($inventory);

            $outcomes = actTogether($this->sandbox->root, [
                ['delete-field', $inventory->id, $field->id],
                ['update-item', $team['owner']->id, $inventory->id, $item->id, $field->ulid, 'Hammer'],
            ]);

            expect($outcomes)->toBeIn([['deleted', 'updated'], ['deleted', 'refused:invalid:values.'.$field->ulid]])
                ->and(DB::table('items')->where('values', 'like', '%'.$field->ulid.'%')->count())->toBe(0);

            removeConcurrencyRows();
        }
    });
});

describe('updates of one item at the same moment', function (): void {
    it('are merged one after the other: none loses the value the other wrote', function (): void {
        foreach (range(1, 6) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);
            $name = fieldIn($inventory, 'text', 'Name');
            $note = fieldIn($inventory, 'text', 'Note');
            $item = itemIn($inventory);

            $outcomes = actTogether($this->sandbox->root, [
                ['update-item', $team['owner']->id, $inventory->id, $item->id, $name->ulid, 'Hammer'],
                ['update-item', $team['editor']->id, $inventory->id, $item->id, $note->ulid, 'Heavy'],
            ]);

            expect($outcomes)->toBe(['updated', 'updated'])
                ->and($item->fresh()->values)->toEqual([$name->ulid => 'Hammer', $note->ulid => 'Heavy']);

            removeConcurrencyRows();
        }
    });
});

describe('an account deleted while it writes an item', function (): void {
    it('is judged one request after the other: no deadlock, no failing foreign key', function (): void {
        foreach (range(1, 8) as $_) {
            $person = inventoryPerson('Alone');
            $inventory = inventoryIn($person->workspaces()->sole());

            $outcomes = actTogether($this->sandbox->root, [
                ['delete-account', $person->id],
                ['create-item', $person->id, $inventory->id],
            ]);

            // The item went first (and goes with the account's only workspace), or the account was gone when it was written.
            expect($outcomes)->toBeIn([['created', 'deleted'], ['deleted', 'refused:gone']])
                ->and(Item::query()->count())->toBe(0)
                ->and(Inventory::query()->count())->toBe(0);

            removeConcurrencyRows();
        }
    });
});
