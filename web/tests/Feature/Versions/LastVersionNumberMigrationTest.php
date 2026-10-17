<?php

declare(strict_types=1);

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CommitsDatabaseChanges;

/*
 * The migration that adds `last_version_number` to the inventories, run for real: the column is dropped, versions are written with the
 * schema as it was, and the migration sets the counter to the highest number each inventory uses. It changes the schema, which a
 * transaction cannot undo on MySQL and MariaDB, so the test commits what it writes and removes it, and always puts the column back.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(fn () => removeConcurrencyRows());
afterEach(fn () => removeConcurrencyRows());

/**
 * Writes the row of a version with the numbers it has, as the schema was before the counter.
 *
 * @param  list<int>  $numbers
 */
function versionRowsNumbered(int $inventory, array $numbers): void
{
    foreach ($numbers as $number) {
        $ulid = strtolower((string) Str::ulid());

        DB::table('inventory_versions')->insert([
            'ulid' => $ulid, 'inventory_id' => $inventory, 'number' => $number, 'author_name' => 'Owner', 'locale' => 'en', 'frozen_at' => now(),
            'items_count' => 0, 'fields_count' => 0, 'status' => 'completed', 'disk' => 'local', 'snapshot_size' => 2, 'snapshot_sha256' => str_repeat('a', 64),
            'snapshot_path' => "workspaces/w/inventories/i/versions/{$ulid}/snapshot.json", 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

describe('the migration that adds the counter of the numbers of the versions', function (): void {
    it('starts every inventory at the highest number it uses, and at zero when it has no version', function (): void {
        $migration = require database_path('migrations/2026_11_07_000000_add_last_version_number_to_inventories.php');
        $workspace = Workspace::factory()->create();
        $now = now();
        $ids = [];

        try {
            $migration->down();
            expect(Schema::hasColumn('inventories', 'last_version_number'))->toBeFalse();

            // Holes left by deleted versions: the counter is the highest number, not the count.
            foreach (['holes' => [1, 3, 7], 'one' => [1], 'none' => []] as $name => $numbers) {
                $ids[$name] = DB::table('inventories')->insertGetId([
                    'ulid' => (string) Str::ulid(), 'workspace_id' => $workspace->id, 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
                ]);
                versionRowsNumbered($ids[$name], $numbers);
            }

            $migration->up();
        } finally {
            // Whatever happened, the schema the other tests run on has its column back.
            if (! Schema::hasColumn('inventories', 'last_version_number')) {
                $migration->up();
            }
        }

        expect(Schema::hasColumn('inventories', 'last_version_number'))->toBeTrue()
            ->and(array_map(fn (int $id): int => (int) DB::table('inventories')->where('id', $id)->value('last_version_number'), $ids))->toBe(['holes' => 7, 'one' => 1, 'none' => 0])
            ->and(DB::table('inventories')->where('id', $ids['holes'])->value('updated_at'))->not->toBeNull();
    });

    it('can be run again over the column an earlier run that failed after adding it left behind', function (): void {
        $migration = require database_path('migrations/2026_11_07_000000_add_last_version_number_to_inventories.php');
        $workspace = Workspace::factory()->create();
        $id = DB::table('inventories')->insertGetId([
            'ulid' => (string) Str::ulid(), 'workspace_id' => $workspace->id, 'name' => 'Tools', 'created_at' => now(), 'updated_at' => now(),
        ]);
        versionRowsNumbered($id, [1, 2]);
        DB::table('inventories')->where('id', $id)->update(['last_version_number' => 99]);

        $migration->up();
        $migration->up();

        expect((int) DB::table('inventories')->where('id', $id)->value('last_version_number'))->toBe(2);
    });

    it('gives a new inventory a counter of zero', function (): void {
        $workspace = Workspace::factory()->create();
        $id = DB::table('inventories')->insertGetId([
            'ulid' => (string) Str::ulid(), 'workspace_id' => $workspace->id, 'name' => 'New', 'created_at' => now(), 'updated_at' => now(),
        ]);

        expect((int) DB::table('inventories')->where('id', $id)->value('last_version_number'))->toBe(0);
    });
});
