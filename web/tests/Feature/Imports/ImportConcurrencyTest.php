<?php

declare(strict_types=1);

use App\Models\Import;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CommitsDatabaseChanges;

require_once __DIR__.'/../../Support/imports.php';

/*
 * What imports do to an inventory when they run at the same moment as other writes, in separate PHP processes against the real database
 * and a real folder (a single process cannot show it: see tests/Feature/Inventories/InventoryConcurrencyTest.php). An import writes its
 * fields and its items in one transaction under the lock of the inventory, so that the quotas are judged one write after the other, on
 * every engine, and a refused import leaves nothing behind. Each scenario is repeated, as one lucky ordering proves nothing.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(function (): void {
    removeConcurrencyRows();
    config(['inventories.attachments_disk' => 'local']);
    Storage::disk('local')->deleteDirectory('workspaces');
});
afterEach(function (): void {
    removeConcurrencyRows();
    Storage::disk('local')->deleteDirectory('workspaces');
});

/**
 * The environment of the children: the folder they write to and the limits of the scenario.
 *
 * @param  array<string, string>  $limits
 * @return array<string, string>
 */
function importConcurrencyEnvironment(string $root, array $limits): array
{
    return $limits + ['TEST_STORAGE_ROOT' => $root.'/storage', 'ATTACHMENTS_DISK' => 'local'];
}

/**
 * An inventory in a workspace of the team, on the real database, with a text field.
 *
 * @return array{team: array<string, mixed>, inventory: Inventory, field: InventoryField}
 */
function importConcurrencyCase(): array
{
    $team = inventoryTeam();
    $inventory = inventoryIn($team['workspace']);

    return ['team' => $team, 'inventory' => $inventory, 'field' => fieldIn($inventory, 'text', 'Name')];
}

describe('imports for the last places of a quota', function (): void {
    it('give them to one of two imports that run at the same moment, and the other writes nothing', function (): void {
        foreach (range(1, 3) as $_) {
            $case = importConcurrencyCase();
            itemIn($case['inventory'], [$case['field']->ulid => 'Old']);
            itemIn($case['inventory'], [$case['field']->ulid => 'Older']);
            $mapping = [['column' => 0, 'target' => 'field', 'field' => $case['field']->ulid, 'type' => null, 'name' => null]];
            $first = importPendingOn($case, $mapping, ['a', 'b']);
            $second = importPendingOn($case, $mapping, ['c', 'd']);

            $outcomes = actTogether($this->sandbox->root, [['run-import', $first->id], ['run-import', $second->id]], importConcurrencyEnvironment($this->sandbox->root, ['INVENTORIES_MAX_ITEMS' => '4']));

            expect($outcomes)->toBe(['completed', 'refused:item_limit_reached'])
                ->and(Item::query()->where('inventory_id', $case['inventory']->id)->count())->toBe(4)
                ->and((int) DB::table('inventories')->where('id', $case['inventory']->id)->value('items_count'))->toBe(4)
                ->and(Import::query()->whereIn('id', [$first->id, $second->id])->pluck('rows_imported', 'status')->all())->toEqual(['completed' => 2, 'failed' => 0])
                ->and(Storage::disk('local')->allFiles('workspaces'))->toBe([]);

            removeConcurrencyRows();
            Storage::disk('local')->deleteDirectory('workspaces');
        }
    });

    it('give the last place to an import or to an item added at the same moment, not to both', function (): void {
        foreach (range(1, 3) as $_) {
            $case = importConcurrencyCase();
            itemIn($case['inventory'], [$case['field']->ulid => 'Old']);
            $mapping = [['column' => 0, 'target' => 'field', 'field' => $case['field']->ulid, 'type' => null, 'name' => null]];
            $import = importPendingOn($case, $mapping, ['a', 'b']);

            $outcomes = actTogether($this->sandbox->root, [['run-import', $import->id], ['create-item', $case['team']['owner']->id, $case['inventory']->id]], importConcurrencyEnvironment($this->sandbox->root, ['INVENTORIES_MAX_ITEMS' => '3']));
            $count = Item::query()->where('inventory_id', $case['inventory']->id)->count();

            // Either the import took both places and the item was refused, or the item took one and the import (two rows) was refused whole.
            expect($outcomes)->toBeIn([['completed', 'refused:item_limit_reached'], ['created', 'refused:item_limit_reached']])
                ->and($count)->toBe($outcomes[0] === 'completed' ? 3 : 2)
                ->and((int) DB::table('inventories')->where('id', $case['inventory']->id)->value('items_count'))->toBe($count);

            removeConcurrencyRows();
            Storage::disk('local')->deleteDirectory('workspaces');
        }
    });
});

describe('imports that create the same field', function (): void {
    it('give it to one of two imports that run at the same moment: the other fails whole, with its items', function (): void {
        foreach (range(1, 3) as $_) {
            $case = importConcurrencyCase();
            $mapping = [['column' => 0, 'target' => 'new', 'field' => null, 'type' => 'text', 'name' => 'Label']];
            $first = importPendingOn($case, $mapping, ['a', 'b'], 'Label');
            $second = importPendingOn($case, $mapping, ['c', 'd'], 'Label');

            $outcomes = actTogether($this->sandbox->root, [['run-import', $first->id], ['run-import', $second->id]], importConcurrencyEnvironment($this->sandbox->root, []));

            expect($outcomes)->toBe(['completed', 'refused:fields_changed'])
                ->and(DB::table('inventory_fields')->where('inventory_id', $case['inventory']->id)->where('name', 'Label')->count())->toBe(1)
                ->and(Item::query()->where('inventory_id', $case['inventory']->id)->count())->toBe(2)
                ->and((int) DB::table('inventories')->where('id', $case['inventory']->id)->value('items_count'))->toBe(2);

            removeConcurrencyRows();
            Storage::disk('local')->deleteDirectory('workspaces');
        }
    });

    it('give the last field of the inventory to one of two imports, and a field is not created without its items', function (): void {
        foreach (range(1, 3) as $_) {
            $case = importConcurrencyCase();
            $first = importPendingOn($case, [['column' => 0, 'target' => 'new', 'field' => null, 'type' => 'text', 'name' => 'One']], ['a'], 'One');
            $second = importPendingOn($case, [['column' => 0, 'target' => 'new', 'field' => null, 'type' => 'text', 'name' => 'Two']], ['b'], 'Two');

            $outcomes = actTogether($this->sandbox->root, [['run-import', $first->id], ['run-import', $second->id]], importConcurrencyEnvironment($this->sandbox->root, ['INVENTORIES_MAX_FIELDS' => '2']));

            expect($outcomes)->toBe(['completed', 'refused:field_limit_reached'])
                ->and(DB::table('inventory_fields')->where('inventory_id', $case['inventory']->id)->count())->toBe(2)
                ->and(Item::query()->where('inventory_id', $case['inventory']->id)->count())->toBe(1);

            removeConcurrencyRows();
            Storage::disk('local')->deleteDirectory('workspaces');
        }
    });
});
