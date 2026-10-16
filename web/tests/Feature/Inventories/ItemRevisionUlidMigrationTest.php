<?php

declare(strict_types=1);

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CommitsDatabaseChanges;

/*
 * The migration that gives the revisions of the items a ULID, run for real: the column is dropped, revisions are written with
 * the schema as it was, and the migration gives each its own. It changes the schema, which a transaction cannot undo on MySQL
 * and MariaDB, so the test commits what it writes and removes it, and always puts the column back.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(fn () => removeConcurrencyRows());
afterEach(fn () => removeConcurrencyRows());

/**
 * Whether the revisions have a unique index on their ULID.
 */
function revisionUlidIsUnique(): bool
{
    return collect(Schema::getIndexes('item_revisions'))->contains(fn (array $index): bool => $index['columns'] === ['ulid'] && $index['unique']);
}

/**
 * Whether the ULID of a revision can be null.
 */
function revisionUlidIsNullable(): bool
{
    return (bool) collect(Schema::getColumns('item_revisions'))->firstWhere('name', 'ulid')['nullable'];
}

describe('the migration that adds the ULID of a revision', function (): void {
    it('gives every revision that already exists a ULID of its own, and makes the column required and unique', function (): void {
        $migration = require database_path('migrations/2026_10_22_000300_add_ulid_to_item_revisions.php');
        $workspace = Workspace::factory()->create();
        $now = now();
        $inventory = DB::table('inventories')->insertGetId(['ulid' => (string) Str::ulid(), 'workspace_id' => $workspace->id, 'name' => 'Tools', 'created_at' => $now, 'updated_at' => $now]);

        try {
            $migration->down();
            expect(Schema::hasColumn('item_revisions', 'ulid'))->toBeFalse();

            // More than one chunk of them.
            foreach (array_chunk(range(1, 1100), 200) as $numbers) {
                DB::table('item_revisions')->insert(array_map(fn (int $number): array => [
                    'inventory_id' => $inventory, 'item_id' => 1 + $number % 3, 'user_id' => null, 'action' => 'created', 'changes' => '{}', 'created_at' => $now,
                ], $numbers));
            }

            $migration->up();
        } finally {
            // Whatever happened, the schema the other tests run on has its column back.
            if (! Schema::hasColumn('item_revisions', 'ulid')) {
                $migration->up();
            }
        }

        $ulids = DB::table('item_revisions')->pluck('ulid')->all();

        expect($ulids)->toHaveCount(1100)
            ->and(array_unique($ulids))->toHaveCount(1100)
            ->and(array_filter($ulids, fn (?string $ulid): bool => $ulid === null || preg_match('/^[0-9a-z]{26}$/', $ulid) !== 1))->toBe([])
            ->and(revisionUlidIsUnique())->toBeTrue()
            ->and(revisionUlidIsNullable())->toBeFalse();
    });

    it('can be run again over what an earlier run that failed half way through left behind', function (): void {
        // MySQL and MariaDB cannot undo the change of schema: the column stays with some ULIDs written, the migration is not
        // recorded, and the next run starts from there.
        $migration = require database_path('migrations/2026_10_22_000300_add_ulid_to_item_revisions.php');
        $workspace = Workspace::factory()->create();
        $now = now();
        $inventory = DB::table('inventories')->insertGetId(['ulid' => (string) Str::ulid(), 'workspace_id' => $workspace->id, 'name' => 'Tools', 'created_at' => $now, 'updated_at' => $now]);

        try {
            $migration->down();
            DB::table('item_revisions')->insert(array_map(fn (int $number): array => [
                'inventory_id' => $inventory, 'item_id' => 1, 'user_id' => null, 'action' => 'created', 'changes' => '{}', 'created_at' => $now,
            ], range(1, 3)));

            // The failed run: the column was added and one revision got its ULID.
            Schema::table('item_revisions', fn ($table) => $table->ulid('ulid')->nullable());
            $kept = strtolower((string) Str::ulid());
            DB::table('item_revisions')->orderBy('id')->limit(1)->update(['ulid' => $kept]);

            $migration->up();
            $migration->up();
        } finally {
            if (! Schema::hasColumn('item_revisions', 'ulid')) {
                $migration->up();
            }
        }

        expect(DB::table('item_revisions')->whereNull('ulid')->count())->toBe(0)
            ->and(DB::table('item_revisions')->pluck('ulid')->unique()->count())->toBe(3)
            ->and(DB::table('item_revisions')->orderBy('id')->value('ulid'))->toBe($kept)
            ->and(revisionUlidIsUnique())->toBeTrue()
            ->and(revisionUlidIsNullable())->toBeFalse();
    });
});
