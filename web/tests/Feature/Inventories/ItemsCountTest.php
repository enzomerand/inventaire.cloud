<?php

declare(strict_types=1);

use App\Inventories\Items;
use App\Models\Inventory;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/inventories.php';

/*
 * The number of items an inventory keeps on its own row (`items_count`): moved by the service that adds and deletes
 * items, read by the quota and by every answer that gives the count. The migration that adds it is in
 * ItemsCountMigrationTest.php.
 */

uses(RefreshDatabase::class);

/**
 * The counter of an inventory as the database holds it, and its `updated_at`.
 *
 * @return array{count: int, updated_at: string}
 */
function storedCounter(Inventory $inventory): array
{
    $row = DB::table('inventories')->where('id', $inventory->id)->first(['items_count', 'updated_at']);

    return ['count' => (int) $row->items_count, 'updated_at' => (string) $row->updated_at];
}

describe('the counter of items', function (): void {
    it('starts at zero and follows the items added and deleted', function (): void {
        $inventory = inventoryIn(inventoryTeam()['workspace']);
        expect(storedCounter($inventory)['count'])->toBe(0);

        $first = itemIn($inventory);
        itemIn($inventory);
        expect(storedCounter($inventory)['count'])->toBe(2);

        app(Items::class)->delete($inventory, $first);

        expect(storedCounter($inventory)['count'])->toBe(1)
            ->and(Item::query()->where('inventory_id', $inventory->id)->count())->toBe(1);
    });

    it('counts each inventory on its own', function (): void {
        $workspace = inventoryTeam()['workspace'];
        $tools = inventoryIn($workspace, 'Tools');
        $books = inventoryIn($workspace, 'Books');

        itemIn($tools);
        itemIn($tools);
        itemIn($books);

        expect(storedCounter($tools)['count'])->toBe(2)
            ->and(storedCounter($books)['count'])->toBe(1);
    });

    it('does not touch the dates of the inventory', function (): void {
        $inventory = inventoryIn(inventoryTeam()['workspace']);
        DB::table('inventories')->where('id', $inventory->id)->update(['updated_at' => '2020-01-01 00:00:00']);

        $item = itemIn($inventory);
        app(Items::class)->delete($inventory, $item);
        itemIn($inventory);

        expect(storedCounter($inventory))->toBe(['count' => 1, 'updated_at' => '2020-01-01 00:00:00']);
    });

    it('is moved neither by a change of an item nor by a refused one', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name', required: true);
        $item = itemIn($inventory, [$name->ulid => 'Hammer']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";

        inventoryCall($this, $team['owner'], 'PATCH', "{$uri}/{$item->ulid}", ['values' => [$name->ulid => 'Saw']])->assertOk();
        // A required field without a value, then a key that is no field: both refused, nothing counted.
        inventoryCall($this, $team['owner'], 'POST', $uri, ['values' => []])->assertUnprocessable();
        inventoryCall($this, $team['owner'], 'POST', $uri, ['values' => [$name->ulid => 'Plane', 'nope' => 'x']])->assertUnprocessable();
        // An item that is not in this inventory cannot be deleted through it.
        $foreign = itemIn(inventoryIn($team['workspace'], 'Other'));
        inventoryCall($this, $team['owner'], 'DELETE', "{$uri}/{$foreign->ulid}")->assertNotFound();

        expect(storedCounter($inventory)['count'])->toBe(1)
            ->and(storedCounter($foreign->inventory)['count'])->toBe(1)
            ->and(Item::query()->count())->toBe(2);
    });

    it('is not moved when the quota refuses an item', function (): void {
        config(['inventories.max_items' => 1]);
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";

        inventoryCall($this, $team['owner'], 'POST', $uri)->assertCreated();
        inventoryCall($this, $team['owner'], 'POST', $uri)->assertStatus(409);

        expect(storedCounter($inventory)['count'])->toBe(1);
    });

    it('is what the quota is judged on, not the rows', function (): void {
        config(['inventories.max_items' => 3]);
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";

        // No row at all, a counter at the quota: refused. A freed place is a place again.
        DB::table('inventories')->where('id', $inventory->id)->update(['items_count' => 3]);
        inventoryCall($this, $team['owner'], 'POST', $uri)->assertStatus(409)->assertJson(['code' => 'item_limit_reached', 'limit' => 3]);

        DB::table('inventories')->where('id', $inventory->id)->update(['items_count' => 2]);
        inventoryCall($this, $team['owner'], 'POST', $uri)->assertCreated();
        inventoryCall($this, $team['owner'], 'POST', $uri)->assertStatus(409);

        expect(storedCounter($inventory)['count'])->toBe(3)
            ->and(Item::query()->count())->toBe(1);
    });

    it('never goes below zero, and says so in the log when it would have', function (): void {
        $inventory = inventoryIn(inventoryTeam()['workspace']);
        $item = itemIn($inventory);
        $other = itemIn($inventory);
        Log::spy();

        // A deletion that leaves the counter right says nothing.
        app(Items::class)->delete($inventory, $other);

        Log::shouldNotHaveReceived('warning');

        // A counter that drifted (it never does through the application).
        DB::table('inventories')->where('id', $inventory->id)->update(['items_count' => 0]);

        app(Items::class)->delete($inventory, $item);

        expect(storedCounter($inventory)['count'])->toBe(0);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'below zero')
            && $context['inventory_id'] === $inventory->id && $context['stored'] === 0 && $context['by'] === -1);
    });

    it('is brought up to date in the inventory the caller holds, without leaving it modified', function (): void {
        $inventory = inventoryIn(inventoryTeam()['workspace']);
        expect($inventory->items_count)->toBe(0);

        $item = itemIn($inventory);
        expect($inventory->items_count)->toBe(1)->and($inventory->isDirty())->toBeFalse();

        app(Items::class)->delete($inventory, $item);
        expect($inventory->items_count)->toBe(0)->and($inventory->isDirty())->toBeFalse();
    });

    it('is the count the API gives, in the list, in one inventory and after a creation', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        itemIn($inventory);
        // Not the number of rows: the answers read the counter.
        DB::table('inventories')->where('id', $inventory->id)->update(['items_count' => 7]);

        $list = inventoryCall($this, $team['viewer'], 'GET', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories")->assertOk();
        $one = inventoryCall($this, $team['viewer'], 'GET', "/api/v1/inventories/{$inventory->ulid}")->assertOk();
        $created = inventoryCall($this, $team['owner'], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories", ['name' => 'Fresh'])->assertCreated();

        expect($list->json('data.0.items_count'))->toBe(7)
            ->and($one->json('data.items_count'))->toBe(7)
            ->and($created->json('data.items_count'))->toBe(0);
    });

    it('is the count of the account export', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        itemIn($inventory);
        itemIn($inventory);

        $export = inventoryCall($this, $team['owner'], 'GET', '/api/v1/me/export')->assertOk()->json('workspaces');
        $shop = collect($export)->firstWhere('id', $team['workspace']->ulid);

        expect($shop['inventories'][0]['items_count'])->toBe(2);
    });

    it('goes with the inventory, which takes its items with it', function (): void {
        $inventory = inventoryIn(inventoryTeam()['workspace']);
        itemIn($inventory);

        $inventory->delete();

        expect(Item::query()->count())->toBe(0)
            ->and(Inventory::query()->count())->toBe(0);
    });
});
