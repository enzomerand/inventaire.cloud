<?php

declare(strict_types=1);

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CommitsDatabaseChanges;

/*
 * The migration that adds `items_count` to the inventories, run for real: the column is dropped, items are written
 * with the schema as it was, and the migration counts them. It changes the schema, which a transaction cannot undo on
 * MySQL and MariaDB, so the test commits what it writes and removes it, and always puts the column back.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(fn () => removeConcurrencyRows());
afterEach(fn () => removeConcurrencyRows());

describe('the migration that adds the counter', function (): void {
    it('counts the items that already exist, inventory by inventory', function (): void {
        $migration = require database_path('migrations/2026_10_21_000000_add_items_count_to_inventories.php');
        $workspace = Workspace::factory()->create();
        $now = now();

        try {
            $migration->down();
            expect(Schema::hasColumn('inventories', 'items_count'))->toBeFalse();

            $ids = [];

            foreach (['three' => 3, 'none' => 0, 'one' => 1] as $name => $items) {
                $ids[$name] = DB::table('inventories')->insertGetId([
                    'ulid' => (string) Str::ulid(), 'workspace_id' => $workspace->id, 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
                ]);

                DB::table('items')->insert(array_map(fn (): array => [
                    'ulid' => (string) Str::ulid(), 'inventory_id' => $ids[$name], 'values' => '{}', 'created_at' => $now, 'updated_at' => $now,
                ], $items === 0 ? [] : range(1, $items)));
            }

            $migration->up();
        } finally {
            // Whatever happened, the schema the other tests run on has its column back.
            if (! Schema::hasColumn('inventories', 'items_count')) {
                $migration->up();
            }
        }

        expect(Schema::hasColumn('inventories', 'items_count'))->toBeTrue()
            ->and(array_map(fn (int $id): int => (int) DB::table('inventories')->where('id', $id)->value('items_count'), $ids))->toBe(['three' => 3, 'none' => 0, 'one' => 1])
            ->and(DB::table('inventories')->where('id', $ids['three'])->value('updated_at'))->not->toBeNull();
    });
});
