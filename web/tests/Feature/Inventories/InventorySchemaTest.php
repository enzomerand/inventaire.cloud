<?php

declare(strict_types=1);

use App\Installer\ApplicationTables;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('the tables', function (): void {
    it('have the columns the models read', function (string $table, array $columns): void {
        expect(Schema::hasColumns($table, $columns))->toBeTrue();
    })->with([
        'inventories' => ['inventories', ['id', 'ulid', 'workspace_id', 'name', 'description', 'created_at', 'updated_at']],
        'inventory_fields' => ['inventory_fields', ['id', 'ulid', 'inventory_id', 'name', 'type', 'options', 'required', 'position', 'created_at', 'updated_at']],
        'items' => ['items', ['id', 'ulid', 'inventory_id', 'values', 'created_by', 'updated_by', 'created_at', 'updated_at']],
    ]);

    it('are all known to the installer, which refuses a database that holds tables of another application', function (): void {
        foreach (['inventories', 'inventory_fields', 'items'] as $table) {
            expect(ApplicationTables::owns($table))->toBeTrue();
        }

        $foreign = array_diff(array_column(Schema::getTables(), 'name'), ApplicationTables::NAMES);

        expect($foreign)->toBe([]);
    });

    it('refuse two rows with the same ULID', function (): void {
        $inventory = Inventory::factory()->create();

        expect(fn () => DB::table('inventories')->insert([
            'ulid' => $inventory->ulid, 'workspace_id' => $inventory->workspace_id, 'name' => 'Twin', 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('give every row a ULID of 26 lowercase characters, and keep the numeric key to themselves', function (): void {
        $inventory = Inventory::factory()->create();
        $field = InventoryField::factory()->for($inventory)->create();
        $item = Item::factory()->for($inventory)->create();

        foreach ([$inventory, $field, $item] as $model) {
            expect($model->ulid)->toMatch('/^[0-9a-z]{26}$/')
                ->and($model->getRouteKeyName())->toBe('ulid')
                ->and($model->getRouteKey())->toBe($model->ulid);
        }
    });
});

describe('finding a row by the ULID of a URL', function (): void {
    it('works whatever the case, for a row bound on its own', function (): void {
        $inventory = Inventory::factory()->create();

        expect((new Inventory)->resolveRouteBinding(strtoupper($inventory->ulid))?->is($inventory))->toBeTrue()
            ->and((new Inventory)->resolveRouteBinding('01j0000000000000000000000x'))->toBeNull();
    });

    it('works whatever the case, for the child of another row, and only inside it', function (): void {
        $inventory = Inventory::factory()->create();
        $other = Inventory::factory()->create();
        $field = InventoryField::factory()->for($inventory)->create();
        $item = Item::factory()->for($inventory)->create();

        expect($inventory->resolveChildRouteBinding('field', strtoupper($field->ulid), null)?->is($field))->toBeTrue()
            ->and($inventory->resolveChildRouteBinding('item', strtoupper($item->ulid), null)?->is($item))->toBeTrue()
            ->and($other->resolveChildRouteBinding('field', $field->ulid, null))->toBeNull()
            ->and($other->resolveChildRouteBinding('item', $item->ulid, null))->toBeNull();
    });

    it('still works for a workspace', function (): void {
        $workspace = Workspace::factory()->create();

        expect((new Workspace)->resolveRouteBinding(strtoupper($workspace->ulid))?->is($workspace))->toBeTrue();
    });
});

describe('the relations', function (): void {
    it('lists the fields of an inventory in order, the oldest first among equals', function (): void {
        $inventory = Inventory::factory()->create();
        $third = InventoryField::factory()->for($inventory)->create(['name' => 'Third', 'position' => 2]);
        $first = InventoryField::factory()->for($inventory)->create(['name' => 'First', 'position' => 0]);
        $second = InventoryField::factory()->for($inventory)->create(['name' => 'Second', 'position' => 0]);
        InventoryField::factory()->create(['name' => 'Elsewhere', 'position' => 0]);

        expect($inventory->fields->pluck('name')->all())->toBe(['First', 'Second', 'Third'])
            ->and($first->inventory->is($inventory))->toBeTrue()
            ->and($second->id)->toBeGreaterThan($first->id)
            ->and($third->position)->toBe(2);
    });

    it('lists the items of an inventory and reaches the workspace', function (): void {
        $workspace = Workspace::factory()->create();
        $inventory = Inventory::factory()->for($workspace)->create();
        Item::factory()->count(2)->for($inventory)->create();
        Item::factory()->create();

        expect($inventory->items)->toHaveCount(2)
            ->and($inventory->workspace->is($workspace))->toBeTrue()
            ->and($workspace->inventories)->toHaveCount(1)
            ->and($inventory->items->first()->inventory->is($inventory))->toBeTrue();
    });

    it('reads the options of a field and falls back to a default for one it does not hold', function (): void {
        $field = InventoryField::factory()->ofType('number', ['decimals' => 2, 'unit' => 'kg'])->create();
        $bare = InventoryField::factory()->create(['options' => null]);

        // MySQL keeps the keys of a JSON object in an order of its own: the options are compared as a set.
        expect($field->fresh()->options)->toEqual(['decimals' => 2, 'unit' => 'kg'])
            ->and($field->option('unit'))->toBe('kg')
            ->and($field->option('max', 'none'))->toBe('none')
            ->and($bare->fresh()->option('max_length', 255))->toBe(255)
            ->and($field->fresh()->required)->toBeFalse()
            ->and(InventoryField::factory()->required()->create()->fresh()->required)->toBeTrue();
    });
});

describe('the values of an item', function (): void {
    it('are an empty object, not an empty list, when there are none', function (): void {
        $item = Item::factory()->create();

        expect(json_decode((string) DB::table('items')->where('id', $item->id)->value('values')))->toBeObject()
            ->and($item->fresh()->values)->toBe([]);
    });

    it('come back as they went in, numbers written as decimal strings staying strings', function (): void {
        $values = ['01j0000000000000000000000a' => '12.50', '01j0000000000000000000000b' => true, '01j0000000000000000000000c' => ['red', 'blue'], '01j0000000000000000000000d' => 3, '01j0000000000000000000000e' => 'é 日本 😀'];
        $item = Item::factory()->create(['values' => $values]);

        expect($item->fresh()->values)->toEqual($values)
            ->and($item->fresh()->values['01j0000000000000000000000a'])->toBe('12.50');
    });

    it('survive a column that holds nothing usable', function (): void {
        $item = Item::factory()->create();

        DB::table('items')->where('id', $item->id)->update(['values' => json_encode([1, 2])]);
        expect($item->fresh()->values)->toBe([1, 2]);

        DB::table('items')->where('id', $item->id)->update(['values' => json_encode('text')]);
        expect($item->fresh()->values)->toBe([]);
    });
});

describe('deleting', function (): void {
    it('an inventory deletes its fields and items, and nothing else', function (): void {
        $inventory = Inventory::factory()->create();
        $other = Inventory::factory()->create();
        InventoryField::factory()->for($inventory)->create();
        Item::factory()->for($inventory)->create();
        $keptField = InventoryField::factory()->for($other)->create();
        $keptItem = Item::factory()->for($other)->create();

        $inventory->delete();

        expect(InventoryField::query()->pluck('id')->all())->toBe([$keptField->id])
            ->and(Item::query()->pluck('id')->all())->toBe([$keptItem->id]);
    });

    it('a workspace deletes its inventories with everything in them', function (): void {
        $workspace = Workspace::factory()->create();
        $inventory = Inventory::factory()->for($workspace)->create();
        InventoryField::factory()->for($inventory)->create();
        Item::factory()->for($inventory)->create();
        $other = Inventory::factory()->create();

        $workspace->delete();

        expect(Inventory::query()->pluck('id')->all())->toBe([$other->id])
            ->and(InventoryField::query()->count())->toBe(0)
            ->and(Item::query()->count())->toBe(0);
    });

    it('an account leaves its items, forgetting who wrote them', function (): void {
        $author = User::factory()->create();
        $editor = User::factory()->create();
        $item = Item::factory()->create();
        $item->forceFill(['created_by' => $author->id, 'updated_by' => $editor->id])->save();

        $author->delete();

        expect($item->fresh()->created_by)->toBeNull()
            ->and($item->fresh()->updated_by)->toBe($editor->id);

        $editor->delete();

        expect($item->fresh()->updated_by)->toBeNull();
    });
});
