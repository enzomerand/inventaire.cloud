<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommitsDatabaseChanges;

require_once __DIR__.'/../../Support/inventories.php';

/*
 * The migration that adds `search_text` to the items, run for real: the column is dropped, then added again over items
 * that already exist. It only adds the column (the texts of those items are built by `inventaire:upgrade`: see
 * DerivedDataTest.php). It changes the schema, which a transaction cannot undo on MySQL and MariaDB, so the test commits
 * what it writes and removes it, and always puts the column back.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(fn () => removeConcurrencyRows());
afterEach(fn () => removeConcurrencyRows());

describe('the migration that adds the search text', function (): void {
    it('adds the column and leaves the items that already exist without a text', function (): void {
        $migration = require database_path('migrations/2026_10_21_000001_add_search_text_to_items.php');
        $team = inventoryTeam();
        $tools = inventoryIn($team['workspace'], 'Tools');
        $name = fieldIn($tools, 'text', 'Name');
        $item = itemIn($tools, [$name->ulid => 'Électricité']);
        DB::table('items')->update(['updated_at' => '2020-01-01 00:00:00']);

        try {
            $migration->down();
            expect(Schema::hasColumn('items', 'search_text'))->toBeFalse();

            $migration->up();
        } finally {
            // Whatever happened, the schema the other tests run on has its column back.
            if (! Schema::hasColumn('items', 'search_text')) {
                $migration->up();
            }
        }

        expect(Schema::hasColumn('items', 'search_text'))->toBeTrue()
            // The column was dropped with its texts, and nothing wrote them again: that is for the upgrade.
            ->and(DB::table('items')->where('id', $item->id)->value('search_text'))->toBeNull()
            ->and(DB::table('items')->where('updated_at', '!=', '2020-01-01 00:00:00')->count())->toBe(0);
    });

    it('does not call the application: it would be the code of the day the migration runs, not of the day it was written', function (): void {
        $source = (string) file_get_contents(database_path('migrations/2026_10_21_000001_add_search_text_to_items.php'));

        expect($source)->not->toMatch('/^use App\\\\/m')->and($source)->not->toContain('app(');
    });

    it('can be rolled back and run again', function (): void {
        $migration = require database_path('migrations/2026_10_21_000001_add_search_text_to_items.php');

        try {
            $migration->down();
            $migration->up();
            $migration->down();
            $migration->up();
        } finally {
            if (! Schema::hasColumn('items', 'search_text')) {
                $migration->up();
            }
        }

        expect(Schema::hasColumn('items', 'search_text'))->toBeTrue();
    });

    it('can be run again over the column an earlier run that failed after adding it left behind', function (): void {
        // MySQL and MariaDB cannot undo the change of schema: the column stays, the migration is not recorded, and the next
        // run starts with the column in place and texts that may be anything.
        $migration = require database_path('migrations/2026_10_21_000001_add_search_text_to_items.php');
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $first = itemIn($inventory, [$name->ulid => 'Électricité']);
        $second = itemIn($inventory, [$name->ulid => 'Plomberie']);
        DB::table('items')->where('id', $first->id)->update(['search_text' => null]);
        DB::table('items')->where('id', $second->id)->update(['search_text' => 'stale']);

        expect(Schema::hasColumn('items', 'search_text'))->toBeTrue();

        $migration->up();
        $migration->up();

        expect(Schema::hasColumn('items', 'search_text'))->toBeTrue()
            ->and(DB::table('items')->where('id', $first->id)->value('search_text'))->toBeNull()
            ->and(DB::table('items')->where('id', $second->id)->value('search_text'))->toBe('stale');
    });
});
