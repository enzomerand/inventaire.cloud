<?php

declare(strict_types=1);

use App\Inventories\InventoryFields;
use App\Inventories\Items;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\ItemRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../../Support/inventories.php';

/*
 * The history of an item: a revision for every creation and every change of values (written in the transaction of the
 * write, none when nothing changes), at most `inventories.max_revisions_per_item` of them per item, read by any member
 * through GET .../items/{item}/revisions. The files added to and removed from an item are in ItemAttachmentsTest.php.
 */

uses(RefreshDatabase::class);

/**
 * An inventory with a text field, a price and a select, and an item in it written by an author.
 *
 * @return array{team: array<string, mixed>, inventory: Inventory, name: InventoryField, price: InventoryField, state: InventoryField, new: string, used: string, item: Item, uri: string}
 */
function historyCase(): array
{
    $team = inventoryTeam();
    $inventory = inventoryIn($team['workspace']);
    $name = fieldIn($inventory, 'text', 'Name');
    $price = fieldIn($inventory, 'money', 'Price', ['currency' => 'EUR']);
    $state = fieldIn($inventory, 'select', 'State', ['choices' => [['label' => 'New'], ['label' => 'Used']]]);
    [$new, $used] = array_column($state->options['choices'], 'id');
    $item = itemIn($inventory, [$name->ulid => 'Hammer', $price->ulid => '12.50'], $team['editor']);

    return ['team' => $team, 'inventory' => $inventory, 'name' => $name, 'price' => $price, 'state' => $state, 'new' => $new, 'used' => $used, 'item' => $item, 'uri' => "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}"];
}

/**
 * The revisions of an item as the database holds them, oldest first.
 *
 * @return list<ItemRevision>
 */
function historyRows(Item $item): array
{
    return ItemRevision::query()->where('item_id', $item->id)->orderBy('id')->get()->all();
}

describe('the table', function (): void {
    it('has the columns the model reads, and an index for the history of an item', function (): void {
        expect(Schema::hasColumns('item_revisions', ['id', 'inventory_id', 'item_id', 'user_id', 'action', 'changes', 'created_at']))->toBeTrue()
            ->and(Schema::hasColumn('item_revisions', 'updated_at'))->toBeFalse()
            ->and(collect(Schema::getIndexes('item_revisions'))->contains(fn (array $index): bool => $index['columns'] === ['item_id', 'id']))->toBeTrue();
    });
});

describe('creating an item', function (): void {
    it('writes a created revision with the values it was given, by the person who added it', function (): void {
        $case = historyCase();

        [$revision] = historyRows($case['item']);

        expect(historyRows($case['item']))->toHaveCount(1)
            ->and($revision->action)->toBe('created')
            ->and($revision->user_id)->toBe($case['team']['editor']->id)
            ->and($revision->changes)->toEqual([
                $case['name']->ulid => ['before' => null, 'after' => 'Hammer'],
                $case['price']->ulid => ['before' => null, 'after' => '12.50'],
            ])
            ->and($revision->created_at)->not->toBeNull();
    });

    it('writes one with no change at all for an item that holds nothing', function (): void {
        $item = itemIn(inventoryIn(inventoryTeam()['workspace']));

        [$revision] = historyRows($item);

        expect($revision->action)->toBe('created')
            ->and($revision->changes)->toBe([])
            ->and(DB::table('item_revisions')->where('id', $revision->id)->value('changes'))->toBe('{}');
    });

    it('writes nothing for an item that is refused', function (): void {
        $case = historyCase();
        $before = ItemRevision::query()->count();

        inventoryCall($this, $case['team']['owner'], 'POST', "/api/v1/inventories/{$case['inventory']->ulid}/items", ['values' => [$case['price']->ulid => 'not a price']])->assertUnprocessable();

        expect(ItemRevision::query()->count())->toBe($before);
    });
});

describe('changing an item', function (): void {
    it('writes an updated revision with the values before and after, only for what changed', function (): void {
        $case = historyCase();

        inventoryCall($this, $case['team']['owner'], 'PATCH', $case['uri'], ['values' => [
            $case['name']->ulid => 'Hammer',
            $case['price']->ulid => '15',
            $case['state']->ulid => $case['used'],
        ]])->assertOk();

        $revisions = historyRows($case['item']);

        expect($revisions)->toHaveCount(2)
            ->and($revisions[1]->action)->toBe('updated')
            ->and($revisions[1]->user_id)->toBe($case['team']['owner']->id)
            ->and($revisions[1]->changes)->toEqual([
                $case['price']->ulid => ['before' => '12.50', 'after' => '15.00'],
                $case['state']->ulid => ['before' => null, 'after' => $case['used']],
            ]);
    });

    it('writes the revision of an inline edit, which is the same request', function (): void {
        $case = historyCase();

        inventoryCall($this, $case['team']['editor'], 'PATCH', $case['uri'], ['values' => [$case['name']->ulid => 'Mallet']])->assertOk();

        expect(array_column(array_map(fn (ItemRevision $revision): array => $revision->changes, historyRows($case['item'])), $case['name']->ulid))
            ->toEqual([['before' => null, 'after' => 'Hammer'], ['before' => 'Hammer', 'after' => 'Mallet']]);
    });

    it('records a value that is cleared as an after of null', function (): void {
        $case = historyCase();

        inventoryCall($this, $case['team']['owner'], 'PATCH', $case['uri'], ['values' => [$case['price']->ulid => null]])->assertOk();

        expect(historyRows($case['item'])[1]->changes)->toEqual([$case['price']->ulid => ['before' => '12.50', 'after' => null]]);
    });

    it('writes nothing when nothing changes, and leaves the item as it was', function (): void {
        $case = historyCase();
        $updated = DB::table('items')->where('id', $case['item']->id)->value('updated_at');

        inventoryCall($this, $case['team']['owner'], 'PATCH', $case['uri'], ['values' => [$case['name']->ulid => ' Hammer ', $case['price']->ulid => '12.5']])->assertOk();
        inventoryCall($this, $case['team']['owner'], 'PATCH', $case['uri'], ['values' => []])->assertOk();

        expect(historyRows($case['item']))->toHaveCount(1)
            ->and(DB::table('items')->where('id', $case['item']->id)->value('updated_at'))->toBe($updated);
    });

    it('writes nothing for a change that is refused', function (): void {
        $case = historyCase();

        inventoryCall($this, $case['team']['owner'], 'PATCH', $case['uri'], ['values' => [$case['price']->ulid => 'free']])->assertUnprocessable();

        expect(historyRows($case['item']))->toHaveCount(1);
    });

    it('keeps the history of each item apart', function (): void {
        $case = historyCase();
        $other = itemIn($case['inventory'], [$case['name']->ulid => 'Saw']);

        inventoryCall($this, $case['team']['owner'], 'PATCH', $case['uri'], ['values' => [$case['name']->ulid => 'Mallet']])->assertOk();

        expect(historyRows($case['item']))->toHaveCount(2)
            ->and(historyRows($other))->toHaveCount(1);
    });

    it('is rolled back with the write when the database fails after the revision is written', function (): void {
        $case = historyCase();

        expect(fn () => DB::transaction(function () use ($case): void {
            app(Items::class)->update($case['inventory'], $case['item'], [$case['name']->ulid => 'Mallet'], $case['team']['owner']);

            throw new RuntimeException('The request failed afterwards.');
        }))->toThrow(RuntimeException::class);

        expect(historyRows($case['item']))->toHaveCount(1)
            ->and(Item::query()->findOrFail($case['item']->id)->values[$case['name']->ulid])->toBe('Hammer');
    });
});

describe('the number of revisions an item keeps', function (): void {
    it('is capped, the oldest ones going first', function (): void {
        config(['inventories.max_revisions_per_item' => 3]);
        $case = historyCase();

        foreach (['A', 'B', 'C', 'D'] as $name) {
            app(Items::class)->update($case['inventory'], $case['item'], [$case['name']->ulid => $name], $case['team']['owner']);
        }

        $kept = array_map(fn (ItemRevision $revision): mixed => $revision->changes[$case['name']->ulid]['after'] ?? null, historyRows($case['item']));

        expect($kept)->toBe(['B', 'C', 'D']);
    });

    it('is counted for each item, and deleting the oldest does not touch the other items', function (): void {
        config(['inventories.max_revisions_per_item' => 2]);
        $case = historyCase();
        $other = itemIn($case['inventory'], [$case['name']->ulid => 'Saw']);

        foreach (['A', 'B', 'C'] as $name) {
            app(Items::class)->update($case['inventory'], $case['item'], [$case['name']->ulid => $name], $case['team']['owner']);
        }

        expect(historyRows($case['item']))->toHaveCount(2)
            ->and(historyRows($other))->toHaveCount(1);
    });

    it('keeps at least one when it is configured below one', function (): void {
        config(['inventories.max_revisions_per_item' => 0]);
        $case = historyCase();

        app(Items::class)->update($case['inventory'], $case['item'], [$case['name']->ulid => 'A'], $case['team']['owner']);

        expect(historyRows($case['item']))->toHaveCount(1);
    });

    it('is a hundred unless configured otherwise', function (): void {
        expect(config('inventories.max_revisions_per_item'))->toBe(100);
    });
});

describe('reading the history', function (): void {
    it('gives the latest revision first, with who did it, when, and the changes shown with the field as it is now', function (): void {
        $case = historyCase();
        inventoryCall($this, $case['team']['owner'], 'PATCH', $case['uri'], ['values' => [$case['price']->ulid => '15', $case['state']->ulid => $case['used']]])->assertOk();

        $response = inventoryCall($this, $case['team']['viewer'], 'GET', $case['uri'].'/revisions')->assertOk();
        $data = $response->json('data');

        expect($data)->toHaveCount(2)
            ->and(array_keys($data[0]))->toBe(['id', 'action', 'user', 'created_at', 'changes'])
            ->and($data[0]['action'])->toBe('updated')
            ->and($data[0]['user'])->toBe(['id' => $case['team']['owner']->id, 'name' => 'Owner'])
            ->and($data[0]['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}|Z$/')
            ->and($data[0]['changes'])->toBe([
                ['field' => ['id' => $case['price']->ulid, 'name' => 'Price', 'type' => 'money'], 'before' => '12.50', 'after' => '15.00'],
                ['field' => ['id' => $case['state']->ulid, 'name' => 'State', 'type' => 'select'], 'before' => null, 'after' => $case['used']],
            ])
            ->and($data[1]['action'])->toBe('created')
            ->and($data[1]['user'])->toBe(['id' => $case['team']['editor']->id, 'name' => 'Editor'])
            ->and($data[1]['changes'][0])->toBe(['field' => ['id' => $case['name']->ulid, 'name' => 'Name', 'type' => 'text'], 'before' => null, 'after' => 'Hammer'])
            ->and($data[0]['id'])->toBeGreaterThan($data[1]['id']);
    });

    it('is readable by every member, and by nobody else', function (): void {
        $case = historyCase();

        foreach (['owner', 'editor', 'viewer'] as $role) {
            inventoryCall($this, $case['team'][$role], 'GET', $case['uri'].'/revisions')->assertOk();
        }

        inventoryCall($this, $case['team']['stranger'], 'GET', $case['uri'].'/revisions')->assertNotFound();
        $this->flushSession();
        app('auth')->forgetGuards();
        $this->json('GET', $case['uri'].'/revisions', [], frontend())->assertUnauthorized();
    });

    it('is the item of the inventory in the path or a 404', function (): void {
        $case = historyCase();
        $elsewhere = inventoryIn($case['team']['workspace'], 'Elsewhere');

        inventoryCall($this, $case['team']['owner'], 'GET', "/api/v1/inventories/{$elsewhere->ulid}/items/{$case['item']->ulid}/revisions")->assertNotFound();
        inventoryCall($this, $case['team']['owner'], 'GET', "/api/v1/inventories/{$case['inventory']->ulid}/items/01j0000000000000000000000x/revisions")->assertNotFound();
    });

    it('is paginated, twenty-five a page, latest first', function (): void {
        $case = historyCase();

        foreach (range(1, 29) as $number) {
            app(Items::class)->update($case['inventory'], $case['item'], [$case['name']->ulid => "Name {$number}"], $case['team']['owner']);
        }

        $first = inventoryCall($this, $case['team']['viewer'], 'GET', $case['uri'].'/revisions')->assertOk();
        $second = inventoryCall($this, $case['team']['viewer'], 'GET', $case['uri'].'/revisions?page=2')->assertOk();

        expect($first->json('data'))->toHaveCount(25)
            ->and($first->json('meta.total'))->toBe(30)
            ->and($first->json('meta.per_page'))->toBe(25)
            ->and($first->json('meta.last_page'))->toBe(2)
            ->and($first->json('data.0.changes.0.after'))->toBe('Name 29')
            ->and($second->json('data'))->toHaveCount(5)
            ->and($second->json('data.4.action'))->toBe('created');
    });

    it('names no author when the account was deleted since, and keeps the revision', function (): void {
        $case = historyCase();
        $case['team']['editor']->delete();

        $data = inventoryCall($this, $case['team']['owner'], 'GET', $case['uri'].'/revisions')->assertOk()->json('data');

        expect($data)->toHaveCount(1)
            ->and($data[0]['user'])->toBeNull()
            ->and(historyRows($case['item'])[0]->user_id)->toBeNull();
    });

    it('shows a field that is gone as no field, with the values as they were stored', function (): void {
        $case = historyCase();
        app(InventoryFields::class)->delete($case['inventory'], $case['price']);

        $changes = inventoryCall($this, $case['team']['owner'], 'GET', $case['uri'].'/revisions')->assertOk()->json('data.0.changes');

        expect($changes)->toBe([
            ['field' => ['id' => $case['name']->ulid, 'name' => 'Name', 'type' => 'text'], 'before' => null, 'after' => 'Hammer'],
            ['field' => null, 'before' => null, 'after' => '12.50'],
        ]);
    });

    it('shows a value through the field as it is now: a choice deleted since is gone', function (): void {
        $case = historyCase();
        inventoryCall($this, $case['team']['owner'], 'PATCH', $case['uri'], ['values' => [$case['state']->ulid => $case['used']]])->assertOk();
        app(InventoryFields::class)->update($case['inventory'], $case['state'], ['options' => ['choices' => [['id' => $case['new'], 'label' => 'New']]]]);

        $changes = inventoryCall($this, $case['team']['owner'], 'GET', $case['uri'].'/revisions')->assertOk()->json('data.0.changes');

        expect($changes)->toBe([['field' => ['id' => $case['state']->ulid, 'name' => 'State', 'type' => 'select'], 'before' => null, 'after' => null]]);
    });

    it('goes with the item', function (): void {
        $case = historyCase();

        app(Items::class)->delete($case['inventory'], $case['item']);

        expect(ItemRevision::query()->where('item_id', $case['item']->id)->count())->toBe(0);
    });

    it('gives no change for a stray revision whose changes are not what is expected', function (): void {
        $case = historyCase();
        DB::table('item_revisions')->insert(['inventory_id' => $case['inventory']->id, 'item_id' => $case['item']->id, 'user_id' => null, 'action' => 'updated', 'changes' => '{"x":5}', 'created_at' => now()]);

        $data = inventoryCall($this, $case['team']['owner'], 'GET', $case['uri'].'/revisions')->assertOk()->json('data');

        expect($data[0]['changes'])->toBe([])
            ->and($data[0]['user'])->toBeNull();
    });
});
