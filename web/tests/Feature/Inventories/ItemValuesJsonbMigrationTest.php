<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CommitsDatabaseChanges;

require_once __DIR__.'/../../Support/inventories.php';

/*
 * The migration that stores the values of the items as `jsonb` on PostgreSQL, run for real in both directions. It changes
 * the schema, which a transaction cannot undo on MySQL and MariaDB (where it changes nothing), so the test commits what it
 * writes and removes it, and always puts the column back as the migrations left it.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(fn () => removeConcurrencyRows());
afterEach(fn () => removeConcurrencyRows());

/**
 * The type of the column of values, as the database names it.
 */
function valuesColumnType(): string
{
    return (string) Schema::getColumnType('items', 'values');
}

/**
 * The type the engine gives the column once the migrations have run.
 */
function migratedValuesType(): string
{
    return DB::getDriverName() === 'pgsql' ? 'jsonb' : valuesColumnType();
}

describe('the migration that stores the values as jsonb', function (): void {
    it('has made the column jsonb on PostgreSQL, and left it as it was on the other engines', function (): void {
        if (DB::getDriverName() === 'pgsql') {
            expect(valuesColumnType())->toBe('jsonb');
        } else {
            expect(valuesColumnType())->not->toBe('jsonb')->and(Schema::hasColumn('items', 'values'))->toBeTrue();
        }
    });

    it('converts in both directions without losing a value, and can be run again', function (): void {
        $migration = require database_path('migrations/2026_10_21_000002_store_item_values_as_jsonb_on_postgres.php');
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $name = fieldIn($inventory, 'text', 'Name');
        $weight = fieldIn($inventory, 'number', 'Weight', ['decimals' => 2]);
        $flag = fieldIn($inventory, 'boolean', 'Flag');
        $tags = fieldIn($inventory, 'tags', 'Tags');
        $values = [$name->ulid => 'Électricité', $weight->ulid => '12.50', $flag->ulid => true, $tags->ulid => ['b', 'a']];
        $item = itemIn($inventory, $values);
        $before = valuesColumnType();

        try {
            $migration->down();
            $down = valuesColumnType();
            $migration->down();
            $stillDown = valuesColumnType();
            $stored = DB::table('items')->where('id', $item->id)->value('values');

            $migration->up();
            $up = valuesColumnType();
            $migration->up();
        } finally {
            // Whatever happened, the schema the other tests run on is the one the migrations leave.
            if (valuesColumnType() !== $before) {
                $migration->{$before === 'jsonb' ? 'up' : 'down'}();
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            expect($down)->toBe('json')->and($stillDown)->toBe('json')->and($up)->toBe('jsonb');
        } else {
            expect([$down, $stillDown, $up])->each->toBe($before);
        }

        expect(valuesColumnType())->toBe($before)
            ->and(json_decode((string) $stored, true))->toEqual($values)
            // The item reads the same, whatever order the keys come in.
            ->and($item->fresh()?->values)->toEqual($values);
    });

    it('drops the null characters a json value holds instead of failing, and only those', function (): void {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Only PostgreSQL has a jsonb column to convert to.');
        }

        // The first release let an email value carry a null character: a `json` column keeps its escape as text, `jsonb` refuses it,
        // and a migration that fails keeps `inventaire:upgrade` (so a container) failing for good.
        $migration = require database_path('migrations/2026_10_21_000002_store_item_values_as_jsonb_on_postgres.php');
        $inventory = inventoryIn(inventoryTeam()['workspace']);
        $before = valuesColumnType();
        // In order: a null character inside a text, a backslash followed by the text u0000 (not a null character), a backslash
        // then a null character, two in a row, one after an escaped quote, and one in a key.
        $raw = <<<'JSON'
{"a":"x\u0000y","b":"x\\u0000y","c":"\\\u0000","d":"\u0000\u0000","e":"\"\u0000","k\u0000ey":"v"}
JSON;

        try {
            $migration->down();
            DB::table('items')->insert(['ulid' => (string) Str::ulid(), 'inventory_id' => $inventory->id, 'values' => $raw, 'created_at' => now(), 'updated_at' => now()]);
            $migration->up();
            $type = valuesColumnType();
            $stored = DB::table('items')->where('inventory_id', $inventory->id)->value('values');
        } finally {
            // Gone first: a column cannot go back to jsonb while a row holds what it refuses (a failed conversion would leave it stuck).
            DB::table('items')->where('inventory_id', $inventory->id)->delete();

            if (valuesColumnType() !== $before) {
                $migration->{$before === 'jsonb' ? 'up' : 'down'}();
            }
        }

        expect($type)->toBe('jsonb')
            ->and(json_decode((string) $stored, true))->toEqual(['a' => 'xy', 'b' => 'x\\u0000y', 'c' => '\\', 'd' => '', 'e' => '"', 'key' => 'v'])
            ->and(valuesColumnType())->toBe($before);
    });

    it('lets the list sort, filter and add up the values of a json column too', function (): void {
        // The queries are the same text on `json` and `jsonb`: the rollback of the migration must leave a working list.
        $migration = require database_path('migrations/2026_10_21_000002_store_item_values_as_jsonb_on_postgres.php');
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $weight = fieldIn($inventory, 'number', 'Weight', ['decimals' => 2]);
        $tags = fieldIn($inventory, 'multiselect', 'Kinds', ['choices' => [['label' => 'A'], ['label' => 'B']]]);
        [$a] = array_column($tags->fresh()?->options['choices'] ?? [], 'id');
        itemIn($inventory, [$weight->ulid => '10', $tags->ulid => [$a]]);
        itemIn($inventory, [$weight->ulid => '2.5']);
        itemIn($inventory);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";
        $ask = fn (array $query): array => inventoryCall($this, $team['owner'], 'GET', $uri.'?'.http_build_query($query))->assertOk()->json();
        $before = valuesColumnType();

        $answers = [];

        try {
            foreach (['json' => 'down', 'jsonb' => 'up'] as $type => $direction) {
                $migration->{$direction}();

                $answers[$type] = [
                    'sorted' => array_map(fn (array $item): ?string => $item['values'][$weight->ulid] ?? null, $ask(['sort' => $weight->ulid])['data']),
                    'total' => $ask(['totals' => [$weight->ulid]])['meta']['totals'],
                    'present' => $ask(['filter' => [$weight->ulid => ['empty' => 'false']]])['meta']['total'],
                    'missing' => $ask(['filter' => [$weight->ulid => ['empty' => 'true']]])['meta']['total'],
                    'choice' => $ask(['filter' => [$tags->ulid => ['in' => [$a]]]])['meta']['total'],
                ];
            }
        } finally {
            if (valuesColumnType() !== $before) {
                $migration->{$before === 'jsonb' ? 'up' : 'down'}();
            }
        }

        expect($answers['json'])->toBe($answers['jsonb'])
            ->and($answers['jsonb'])->toBe([
                'sorted' => ['2.50', '10.00', null],
                'total' => [$weight->ulid => '12.50'],
                'present' => 2,
                'missing' => 1,
                'choice' => 1,
            ])
            ->and(valuesColumnType())->toBe(migratedValuesType());
    });
});
