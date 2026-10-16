<?php

declare(strict_types=1);

use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CommitsDatabaseChanges;

require_once __DIR__.'/../../Support/inventories.php';

/*
 * The migration that adds `search_text` to the items, run for real: the column is dropped, then added again over items
 * that already exist, and the migration writes their text. It changes the schema, which a transaction cannot undo on
 * MySQL and MariaDB, so the test commits what it writes and removes it, and always puts the column back.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(fn () => removeConcurrencyRows());
afterEach(fn () => removeConcurrencyRows());

describe('the migration that adds the search text', function (): void {
    it('writes the text of the items that already exist, inventory by inventory and chunk by chunk', function (): void {
        $migration = require database_path('migrations/2026_10_21_000001_add_search_text_to_items.php');
        $team = inventoryTeam();
        $tools = inventoryIn($team['workspace'], 'Tools');
        $name = fieldIn($tools, 'text', 'Name');
        $number = fieldIn($tools, 'number', 'Weight');
        $condition = fieldIn($tools, 'select', 'Condition', ['choices' => [['label' => 'Neuf'], ['label' => 'Occasion']]]);
        [$new] = array_column($condition->options['choices'], 'id');
        $colors = inventoryIn($team['workspace'], 'Colors');
        $palette = fieldIn($colors, 'multiselect', 'Palette', ['choices' => [['label' => 'Rouge'], ['label' => 'Vert'], ['label' => 'Bleu']]]);
        [$red, , $blue] = array_column($palette->options['choices'], 'id');
        $empty = inventoryIn($team['workspace'], 'Empty');

        $special = itemIn($tools, [$name->ulid => 'Électricité', $number->ulid => '12', $condition->ulid => $new]);
        $nothing = itemIn($tools, [$number->ulid => '5']);
        $mixed = itemIn($colors, [$palette->ulid => [$blue, $red]]);
        // More items than a chunk (500) in one inventory, written the way the application wrote them before the column.
        $now = now();
        DB::table('items')->insert(array_map(fn (int $n): array => [
            'ulid' => (string) Str::ulid(), 'inventory_id' => $tools->id, 'values' => json_encode([$name->ulid => "Pièce {$n}"]), 'created_at' => $now, 'updated_at' => $now,
        ], range(1, 520)));
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

        $text = fn (Item $item): ?string => DB::table('items')->where('id', $item->id)->value('search_text');
        $pieces = fn (int $n): int => DB::table('items')->where('inventory_id', $tools->id)->where('search_text', "piece {$n}")->count();

        expect(Schema::hasColumn('items', 'search_text'))->toBeTrue()
            ->and($text($special))->toBe("electricite\nneuf")
            ->and($text($nothing))->toBeNull()
            ->and($text($mixed))->toBe('bleu rouge')
            ->and($pieces(1))->toBe(1)
            ->and($pieces(500))->toBe(1)
            ->and($pieces(501))->toBe(1)
            ->and($pieces(520))->toBe(1)
            ->and(DB::table('items')->where('search_text', 'like', 'piece %')->count())->toBe(520)
            ->and(DB::table('items')->whereNull('search_text')->count())->toBe(1)
            ->and(DB::table('items')->where('inventory_id', $empty->id)->count())->toBe(0)
            // The items did not change: they keep their dates.
            ->and(DB::table('items')->where('updated_at', '!=', '2020-01-01 00:00:00')->count())->toBe(0);
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
        // run starts with the column in place and texts that are missing or half written.
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
            ->and(DB::table('items')->where('id', $first->id)->value('search_text'))->toBe('electricite')
            ->and(DB::table('items')->where('id', $second->id)->value('search_text'))->toBe('plomberie');
    });
});
