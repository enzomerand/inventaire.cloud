<?php

declare(strict_types=1);

use App\Database\JsonValues;
use App\Inventories\Fields\ChoiceType;
use App\Inventories\Fields\Decimal;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\ItemQuery;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/inventories.php';

/*
 * Sorting, filtering and totalling the items of an inventory (`sort`, `direction`, `filter[<field>][<operator>]`,
 * `meta.totals`), the same on PostgreSQL, MySQL and MariaDB. The words of a search (`q`) are in ItemSearchTest.php.
 */

uses(RefreshDatabase::class);

/**
 * A workspace with an inventory that has a "name" text field to tell the items apart, and the fields asked for.
 *
 * @param  array<string, array{0: string, 1?: array<string, mixed>}>  $fields  The type and the options of each field, by alias.
 * @return array{team: array<string, mixed>, inventory: Inventory, fields: array<string, InventoryField>}
 */
function listingOf(array $fields = []): array
{
    $team = inventoryTeam();
    $inventory = inventoryIn($team['workspace']);
    $made = ['name' => fieldIn($inventory, 'text', 'Name')];

    foreach ($fields as $alias => $field) {
        $made[$alias] = fieldIn($inventory, $field[0], ucfirst($alias), $field[1] ?? []);
    }

    return ['team' => $team, 'inventory' => $inventory, 'fields' => $made];
}

/**
 * Adds an item named `$name` to the inventory, with values given by the alias of the field.
 *
 * @param  array{team: array<string, mixed>, inventory: Inventory, fields: array<string, InventoryField>}  $made
 * @param  array<string, mixed>  $values
 */
function listingAdd(array $made, string $name, array $values = []): Item
{
    $keyed = ['name' => $name] + $values;
    $stored = [];

    foreach ($keyed as $alias => $value) {
        $stored[$made['fields'][$alias]->ulid] = $value;
    }

    return itemIn($made['inventory'], $stored, $made['team']['owner']);
}

/**
 * The ULID of a field, by its alias.
 *
 * @param  array{team: array<string, mixed>, inventory: Inventory, fields: array<string, InventoryField>}  $made
 */
function listingKey(array $made, string $alias): string
{
    return $made['fields'][$alias]->ulid;
}

/**
 * The ids of the choices of a field, by label.
 *
 * @param  array{team: array<string, mixed>, inventory: Inventory, fields: array<string, InventoryField>}  $made
 * @return array<string, string>
 */
function listingChoices(array $made, string $alias): array
{
    return array_column(ChoiceType::choicesOf($made['fields'][$alias]->fresh()?->options), 'id', 'label');
}

/**
 * The list of the items, asked with the query string made of `$query`.
 *
 * @param  array{team: array<string, mixed>, inventory: Inventory, fields: array<string, InventoryField>}  $made
 * @param  array<string, mixed>  $query
 * @param  string  $as  The role of the person who asks.
 * @param  array<string, string>  $headers
 */
function listingAsk(object $test, array $made, array $query = [], string $as = 'owner', array $headers = []): TestResponse
{
    $uri = "/api/v1/inventories/{$made['inventory']->ulid}/items".($query === [] ? '' : '?'.http_build_query($query));

    return inventoryCall($test, $made['team'][$as], 'GET', $uri, [], $headers);
}

/**
 * The names of the items on a page, in the order they come.
 *
 * @param  array{team: array<string, mixed>, inventory: Inventory, fields: array<string, InventoryField>}  $made
 * @return list<string>
 */
function listingNames(TestResponse $response, array $made): array
{
    return array_map(fn (array $item): string => $item['values'][listingKey($made, 'name')] ?? '(none)', $response->assertOk()->json('data'));
}

/**
 * The names the list gives for a query, in the order they come.
 *
 * @param  array{team: array<string, mixed>, inventory: Inventory, fields: array<string, InventoryField>}  $made
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function listingOrder(object $test, array $made, array $query): array
{
    return listingNames(listingAsk($test, $made, $query), $made);
}

/**
 * The names the list gives for a query, sorted, for a filter that says nothing about the order.
 *
 * @param  array{team: array<string, mixed>, inventory: Inventory, fields: array<string, InventoryField>}  $made
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function listingSet(object $test, array $made, array $query): array
{
    $names = listingOrder($test, $made, $query);
    sort($names);

    return $names;
}

/**
 * A filter on one field: `filter[<ulid>][<operator>] = value`.
 *
 * @param  array{team: array<string, mixed>, inventory: Inventory, fields: array<string, InventoryField>}  $made
 * @return array{filter: array<string, array<string, mixed>>}
 */
function listingFilter(array $made, string $alias, string $operator, mixed $value): array
{
    return ['filter' => [listingKey($made, $alias) => [$operator => $value]]];
}

/**
 * The error keys of a refused list (422), sorted.
 *
 * @return list<string>
 */
function listingRefused(TestResponse $response): array
{
    $keys = array_keys($response->assertUnprocessable()->json('errors'));
    sort($keys);

    return $keys;
}

/**
 * The totals the list gives for these fields (by alias, or an id as it is) with the rest of a query.
 *
 * @param  array{team: array<string, mixed>, inventory: Inventory, fields: array<string, InventoryField>}  $made
 * @param  list<string>  $aliases
 * @param  array<string, mixed>  $query
 * @return array<string, string>
 */
function listingTotals(object $test, array $made, array $aliases, array $query = [], string $as = 'owner'): array
{
    $keys = array_map(fn (string $alias): string => isset($made['fields'][$alias]) ? listingKey($made, $alias) : $alias, $aliases);

    return listingAsk($test, $made, ['totals' => $keys] + $query, $as)->assertOk()->json('meta.totals');
}

describe('sorting', function (): void {
    it('sorts text with the collation of the connection, empty values last', function (): void {
        $made = listingOf(['label' => ['text']]);
        listingAdd($made, 'one', ['label' => 'charlie']);
        listingAdd($made, 'two', ['label' => 'alpha']);
        listingAdd($made, 'blank');
        listingAdd($made, 'three', ['label' => 'bravo']);
        listingAdd($made, 'blank too');
        $sort = ['sort' => listingKey($made, 'label')];

        expect(listingOrder($this, $made, $sort))->toBe(['two', 'three', 'one', 'blank', 'blank too'])
            ->and(listingOrder($this, $made, $sort + ['direction' => 'asc']))->toBe(['two', 'three', 'one', 'blank', 'blank too'])
            ->and(listingOrder($this, $made, $sort + ['direction' => 'desc']))->toBe(['one', 'three', 'two', 'blank too', 'blank']);
    });

    it('ignores the case and the accents of text, the same on the three engines', function (): void {
        // MySQL and MariaDB sort with the collation of the connection, PostgreSQL with its ICU root collation (und-x-icu).
        if (DB::getDriverName() === 'pgsql' && ! DB::scalar("select exists (select 1 from pg_collation where collname = 'und-x-icu' and collprovider = 'i')")) {
            $this->markTestSkipped('This PostgreSQL was built without ICU: it sorts with the collation of its database, which an installation chooses.');
        }

        $made = listingOf(['label' => ['text']]);

        foreach (['bob', 'Alice', 'zed', 'Éric', 'eve'] as $label) {
            listingAdd($made, $label, ['label' => $label]);
        }

        expect(listingOrder($this, $made, ['sort' => listingKey($made, 'label')]))->toBe(['Alice', 'bob', 'Éric', 'eve', 'zed'])
            ->and(listingOrder($this, $made, ['sort' => listingKey($made, 'label'), 'direction' => 'desc']))->toBe(['zed', 'eve', 'Éric', 'bob', 'Alice']);
    });

    it('sorts numbers as numbers, empty values last', function (): void {
        $made = listingOf(['qty' => ['number', ['decimals' => 2]]]);
        listingAdd($made, 'nine', ['qty' => '9']);
        listingAdd($made, 'ten', ['qty' => '10']);
        listingAdd($made, 'minus', ['qty' => '-1.5']);
        listingAdd($made, 'quarter', ['qty' => '0.25']);
        listingAdd($made, 'blank');
        listingAdd($made, 'blank too');
        $sort = ['sort' => listingKey($made, 'qty')];

        expect(listingOrder($this, $made, $sort))->toBe(['minus', 'quarter', 'nine', 'ten', 'blank', 'blank too'])
            ->and(listingOrder($this, $made, $sort + ['direction' => 'desc']))->toBe(['ten', 'nine', 'quarter', 'minus', 'blank too', 'blank']);
    });

    it('sorts amounts, percentages and ratings as numbers too', function (): void {
        $made = listingOf(['price' => ['money'], 'pct' => ['percentage'], 'stars' => ['rating']]);
        listingAdd($made, 'a', ['price' => '100', 'pct' => '7.25', 'stars' => 4]);
        listingAdd($made, 'b', ['price' => '20.5', 'pct' => '12.5', 'stars' => 5]);
        listingAdd($made, 'c', ['price' => '3', 'pct' => '100', 'stars' => 1]);
        listingAdd($made, 'd');

        foreach (['price' => ['c', 'b', 'a', 'd'], 'pct' => ['a', 'b', 'c', 'd'], 'stars' => ['c', 'a', 'b', 'd']] as $alias => $expected) {
            expect(listingOrder($this, $made, ['sort' => listingKey($made, $alias)]))->toBe($expected, $alias)
                ->and(listingOrder($this, $made, ['sort' => listingKey($made, $alias), 'direction' => 'desc']))->toBe([...array_reverse(array_slice($expected, 0, 3)), 'd'], $alias);
        }
    });

    it('sorts dates, moments and times in the order of time, and no before yes', function (): void {
        $made = listingOf(['bought' => ['date'], 'seen' => ['datetime'], 'at' => ['time'], 'done' => ['boolean']]);
        listingAdd($made, 'a', ['bought' => '2026-03-01', 'seen' => '2026-03-01T10:00:00+01:00', 'at' => '17:45', 'done' => true]);
        listingAdd($made, 'b', ['bought' => '2025-12-31', 'seen' => '2026-03-01T08:00:00Z', 'at' => '08:30', 'done' => false]);
        listingAdd($made, 'c', ['bought' => '2026-01-15', 'seen' => '2026-03-01T09:59:59Z', 'at' => '09:05', 'done' => true]);
        listingAdd($made, 'd');

        // 10:00 at +01:00 is 09:00 in UTC: the moments are sorted in UTC.
        expect(listingOrder($this, $made, ['sort' => listingKey($made, 'bought')]))->toBe(['b', 'c', 'a', 'd'])
            ->and(listingOrder($this, $made, ['sort' => listingKey($made, 'bought'), 'direction' => 'desc']))->toBe(['a', 'c', 'b', 'd'])
            ->and(listingOrder($this, $made, ['sort' => listingKey($made, 'seen')]))->toBe(['b', 'a', 'c', 'd'])
            ->and(listingOrder($this, $made, ['sort' => listingKey($made, 'seen'), 'direction' => 'desc']))->toBe(['c', 'a', 'b', 'd'])
            ->and(listingOrder($this, $made, ['sort' => listingKey($made, 'at')]))->toBe(['b', 'c', 'a', 'd'])
            ->and(listingOrder($this, $made, ['sort' => listingKey($made, 'at'), 'direction' => 'desc']))->toBe(['a', 'c', 'b', 'd'])
            ->and(listingOrder($this, $made, ['sort' => listingKey($made, 'done')]))->toBe(['b', 'a', 'c', 'd'])
            ->and(listingOrder($this, $made, ['sort' => listingKey($made, 'done'), 'direction' => 'desc']))->toBe(['c', 'a', 'b', 'd']);
    });

    it('sorts a choice by its place among the choices of its field, not by its label', function (): void {
        $made = listingOf(['kind' => ['select', ['choices' => [['label' => 'Zulu'], ['label' => 'Alpha'], ['label' => 'Mike']]]]]);
        $ids = listingChoices($made, 'kind');
        listingAdd($made, 'mike', ['kind' => $ids['Mike']]);
        listingAdd($made, 'blank');
        listingAdd($made, 'zulu', ['kind' => $ids['Zulu']]);
        listingAdd($made, 'alpha', ['kind' => $ids['Alpha']]);
        listingAdd($made, 'zulu too', ['kind' => $ids['Zulu']]);
        $sort = ['sort' => listingKey($made, 'kind')];

        expect(listingOrder($this, $made, $sort))->toBe(['zulu', 'zulu too', 'alpha', 'mike', 'blank'])
            ->and(listingOrder($this, $made, $sort + ['direction' => 'desc']))->toBe(['mike', 'alpha', 'zulu too', 'zulu', 'blank']);
    });

    it('puts a choice the field no longer has after the others, in either direction', function (): void {
        $made = listingOf(['kind' => ['select', ['choices' => [['label' => 'Zulu'], ['label' => 'Alpha']]]]]);
        $ids = listingChoices($made, 'kind');
        listingAdd($made, 'zulu', ['kind' => $ids['Zulu']]);
        listingAdd($made, 'alpha', ['kind' => $ids['Alpha']]);
        $stale = listingAdd($made, 'stale');
        DB::table('items')->where('id', $stale->id)->update(['values' => json_encode([listingKey($made, 'name') => 'stale', listingKey($made, 'kind') => 'zzzzzzzz'])]);
        listingAdd($made, 'blank');
        $sort = ['sort' => listingKey($made, 'kind')];

        expect(listingOrder($this, $made, $sort))->toBe(['zulu', 'alpha', 'stale', 'blank'])
            ->and(listingOrder($this, $made, $sort + ['direction' => 'desc']))->toBe(['alpha', 'zulu', 'blank', 'stale']);
    });

    it('sorts by the date an item was added or last changed', function (string $column): void {
        $made = listingOf();

        foreach (['first' => '2026-01-01 10:00:00', 'second' => '2026-02-01 10:00:00', 'third' => '2026-03-01 10:00:00'] as $name => $moment) {
            $item = listingAdd($made, $name);
            // The order of the dates is not the order of the ids.
            DB::table('items')->where('id', $item->id)->update([$column => $name === 'first' ? '2026-04-01 10:00:00' : $moment]);
        }

        expect(listingOrder($this, $made, ['sort' => $column]))->toBe(['second', 'third', 'first'])
            ->and(listingOrder($this, $made, ['sort' => $column, 'direction' => 'desc']))->toBe(['first', 'third', 'second']);
    })->with(['created_at', 'updated_at']);

    it('keeps the newest item first when there is no sort', function (): void {
        $made = listingOf();

        foreach (['first', 'second', 'third'] as $name) {
            listingAdd($made, $name);
        }

        expect(listingOrder($this, $made, []))->toBe(['third', 'second', 'first'])
            ->and(listingOrder($this, $made, ['direction' => 'asc']))->toBe(['third', 'second', 'first']);
    });

    it('breaks a tie by the id in the direction asked for, so that no page repeats or skips an item', function (string $direction): void {
        $made = listingOf(['qty' => ['number']]);
        $key = listingKey($made, 'qty');

        // 120 items over three values, every seventh with no value: the pages are full of ties.
        foreach (range(0, 119) as $number) {
            Item::factory()->for($made['inventory'])->create(['values' => $number % 7 === 0 ? [] : [$key => (string) ($number % 3 + 1)]]);
        }

        $rows = [];

        foreach (DB::table('items')->where('inventory_id', $made['inventory']->id)->get(['id', 'ulid', 'values']) as $row) {
            $rows[] = ['id' => $row->id, 'ulid' => $row->ulid, 'qty' => json_decode((string) $row->values, true)[$key] ?? null];
        }

        $sign = $direction === 'asc' ? 1 : -1;
        usort($rows, fn (array $left, array $right): int => [$left['qty'] === null, $sign * (int) $left['qty'], $sign * $left['id']] <=> [$right['qty'] === null, $sign * (int) $right['qty'], $sign * $right['id']]);

        $seen = [];

        foreach ([1, 2, 3] as $page) {
            $response = listingAsk($this, $made, ['sort' => $key, 'direction' => $direction, 'page' => $page])->assertOk();
            array_push($seen, ...array_column($response->json('data'), 'id'));
        }

        expect($seen)->toHaveCount(120)->and($seen)->toBe(array_column($rows, 'ulid'));
    })->with(['asc', 'desc']);

    it('finds the field of a sort whatever the case of its key', function (): void {
        $made = listingOf(['qty' => ['number']]);
        listingAdd($made, 'big', ['qty' => '5']);
        listingAdd($made, 'small', ['qty' => '1']);

        expect(listingOrder($this, $made, ['sort' => strtoupper(listingKey($made, 'qty'))]))->toBe(['small', 'big'])
            ->and(listingOrder($this, $made, ['sort' => 'CREATED_AT', 'direction' => 'desc']))->toBe(['small', 'big']);
    });

    it('refuses what cannot be sorted by, and names the mistake (422)', function (): void {
        $made = listingOf(['flags' => ['multiselect', ['choices' => [['label' => 'A']]]], 'labels' => ['tags']]);
        listingAdd($made, 'one');

        expect(listingRefused(listingAsk($this, $made, ['sort' => listingKey($made, 'flags')])))->toBe(['sort'])
            ->and(listingRefused(listingAsk($this, $made, ['sort' => listingKey($made, 'labels')])))->toBe(['sort'])
            ->and(listingRefused(listingAsk($this, $made, ['sort' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])))->toBe(['sort'])
            ->and(listingRefused(listingAsk($this, $made, ['sort' => 'name'])))->toBe(['sort'])
            ->and(listingRefused(listingAsk($this, $made, ['sort' => 'id'])))->toBe(['sort'])
            ->and(listingRefused(listingAsk($this, $made, ['sort' => str_repeat('a', 65)])))->toBe(['sort'])
            ->and(listingRefused(listingAsk($this, $made, ['sort' => ['a']])))->toBe(['sort']);
    });

    it('refuses a direction that is neither asc nor desc (422)', function (string $direction): void {
        $made = listingOf();

        expect(listingRefused(listingAsk($this, $made, ['sort' => 'created_at', 'direction' => $direction])))->toBe(['direction']);
    })->with(['up', 'ascending', 'descending', '1']);

    it('reads the direction in any case, like the field it sorts by', function (string $direction, array $order): void {
        $made = listingOf(['qty' => ['number']]);
        listingAdd($made, 'big', ['qty' => '5']);
        listingAdd($made, 'small', ['qty' => '1']);

        expect(listingOrder($this, $made, ['sort' => strtoupper(listingKey($made, 'qty')), 'direction' => $direction]))->toBe($order);
    })->with([
        'DESC' => ['DESC', ['big', 'small']],
        'Desc' => ['Desc', ['big', 'small']],
        'ASC' => ['ASC', ['small', 'big']],
        'aSc' => ['aSc', ['small', 'big']],
    ]);

    it('is open to a viewer', function (): void {
        $made = listingOf(['qty' => ['number']]);
        listingAdd($made, 'big', ['qty' => '5']);
        listingAdd($made, 'small', ['qty' => '1']);

        $response = listingAsk($this, $made, ['sort' => listingKey($made, 'qty')] + listingFilter($made, 'qty', 'min', '1'), 'viewer');

        expect(listingNames($response, $made))->toBe(['small', 'big']);
    });
});

describe('filtering by number', function (): void {
    it('compares numbers as numbers, both bounds included', function (): void {
        $made = listingOf(['qty' => ['number', ['decimals' => 2]]]);

        foreach (['nine' => '9', 'ten' => '10', 'minus' => '-1.5', 'quarter' => '0.25', 'hundred' => '100'] as $name => $qty) {
            listingAdd($made, $name, ['qty' => $qty]);
        }

        listingAdd($made, 'blank');

        expect(listingSet($this, $made, listingFilter($made, 'qty', 'min', '0.25')))->toBe(['hundred', 'nine', 'quarter', 'ten'])
            ->and(listingSet($this, $made, listingFilter($made, 'qty', 'min', '10')))->toBe(['hundred', 'ten'])
            ->and(listingSet($this, $made, listingFilter($made, 'qty', 'min', '9.5')))->toBe(['hundred', 'ten'])
            ->and(listingSet($this, $made, listingFilter($made, 'qty', 'max', '9')))->toBe(['minus', 'nine', 'quarter'])
            ->and(listingSet($this, $made, listingFilter($made, 'qty', 'max', '-1.5')))->toBe(['minus'])
            ->and(listingSet($this, $made, listingFilter($made, 'qty', 'max', '-1.51')))->toBe([])
            ->and(listingSet($this, $made, ['filter' => [listingKey($made, 'qty') => ['min' => '-1.5', 'max' => '0.25']]]))->toBe(['minus', 'quarter'])
            ->and(listingSet($this, $made, ['filter' => [listingKey($made, 'qty') => ['min' => '9', 'max' => '9']]]))->toBe(['nine'])
            ->and(listingSet($this, $made, ['filter' => [listingKey($made, 'qty') => ['min' => '10', 'max' => '9']]]))->toBe([]);
    });

    it('compares exactly, down to the last decimal of the largest numbers', function (): void {
        $made = listingOf(['price' => ['money']]);
        listingAdd($made, 'big', ['price' => '999999999999999.99']);
        listingAdd($made, 'almost', ['price' => '999999999999999.98']);
        listingAdd($made, 'small', ['price' => '0.01']);

        expect(listingSet($this, $made, listingFilter($made, 'price', 'min', '999999999999999.99')))->toBe(['big'])
            ->and(listingSet($this, $made, listingFilter($made, 'price', 'min', '999999999999999.981')))->toBe(['big'])
            ->and(listingSet($this, $made, listingFilter($made, 'price', 'max', '999999999999999.98')))->toBe(['almost', 'small'])
            ->and(listingSet($this, $made, listingFilter($made, 'price', 'max', '0.009999')))->toBe([])
            ->and(listingSet($this, $made, listingFilter($made, 'price', 'min', '0.010001')))->toBe(['almost', 'big']);
    });

    it('filters percentages and ratings as numbers', function (): void {
        $made = listingOf(['pct' => ['percentage'], 'stars' => ['rating']]);
        listingAdd($made, 'a', ['pct' => '7.25', 'stars' => 1]);
        listingAdd($made, 'b', ['pct' => '12.5', 'stars' => 3]);
        listingAdd($made, 'c', ['pct' => '100', 'stars' => 5]);
        listingAdd($made, 'd');

        expect(listingSet($this, $made, listingFilter($made, 'pct', 'min', '12.5')))->toBe(['b', 'c'])
            ->and(listingSet($this, $made, listingFilter($made, 'pct', 'max', '12.49')))->toBe(['a'])
            ->and(listingSet($this, $made, listingFilter($made, 'stars', 'min', '3')))->toBe(['b', 'c'])
            ->and(listingSet($this, $made, listingFilter($made, 'stars', 'max', '3')))->toBe(['a', 'b'])
            ->and(listingSet($this, $made, listingFilter($made, 'stars', 'min', '2.5')))->toBe(['b', 'c']);
    });

    it('refuses a bound that is not a decimal number below 1e15 with at most 6 decimals (422)', function (mixed $bound): void {
        $made = listingOf(['qty' => ['number']]);
        $key = listingKey($made, 'qty');

        expect(listingRefused(listingAsk($this, $made, listingFilter($made, 'qty', 'min', $bound))))->toBe(["filter.{$key}.min"])
            ->and(listingRefused(listingAsk($this, $made, listingFilter($made, 'qty', 'max', $bound))))->toBe(["filter.{$key}.max"]);
    })->with([
        'letters' => 'abc',
        'an exponent' => '1e5',
        'a plus sign' => '+5',
        'a comma' => '1,5',
        'a trailing dot' => '1.',
        'a leading dot' => '.5',
        'seven decimals' => '1.2345678',
        'a quadrillion' => '1000000000000000',
        'minus a quadrillion' => '-1000000000000000.5',
        'a list' => [['5']],
    ]);

    it('accepts the largest bound and six decimals', function (): void {
        $made = listingOf(['qty' => ['number', ['decimals' => 6]]]);
        listingAdd($made, 'tiny', ['qty' => '0.000001']);

        expect(listingSet($this, $made, listingFilter($made, 'qty', 'min', '0.000001')))->toBe(['tiny'])
            ->and(listingSet($this, $made, listingFilter($made, 'qty', 'max', '999999999999999.999999')))->toBe(['tiny'])
            ->and(listingSet($this, $made, listingFilter($made, 'qty', 'min', '-999999999999999.999999')))->toBe(['tiny']);
    });
});

describe('filtering by date, moment and time', function (): void {
    it('keeps the dates between the bounds, both included', function (): void {
        $made = listingOf(['bought' => ['date']]);

        foreach (['dec' => '2025-12-31', 'jan' => '2026-01-15', 'mar' => '2026-03-01'] as $name => $date) {
            listingAdd($made, $name, ['bought' => $date]);
        }

        listingAdd($made, 'blank');

        expect(listingSet($this, $made, listingFilter($made, 'bought', 'min', '2026-01-15')))->toBe(['jan', 'mar'])
            ->and(listingSet($this, $made, listingFilter($made, 'bought', 'max', '2026-01-15')))->toBe(['dec', 'jan'])
            ->and(listingSet($this, $made, ['filter' => [listingKey($made, 'bought') => ['min' => '2026-01-01', 'max' => '2026-02-28']]]))->toBe(['jan'])
            ->and(listingSet($this, $made, ['filter' => [listingKey($made, 'bought') => ['min' => '2026-03-02', 'max' => '2026-12-31']]]))->toBe([]);
    });

    it('keeps the moments between the bounds, to the second, both included', function (): void {
        $made = listingOf(['seen' => ['datetime']]);
        listingAdd($made, 'before', ['seen' => '2026-03-01T09:59:59Z']);
        listingAdd($made, 'at', ['seen' => '2026-03-01T10:00:00Z']);
        listingAdd($made, 'after', ['seen' => '2026-03-01T10:00:01Z']);
        // 12:00 at +02:00 is 10:00 in UTC.
        listingAdd($made, 'offset', ['seen' => '2026-03-01T12:00:00+02:00']);

        expect(listingSet($this, $made, listingFilter($made, 'seen', 'min', '2026-03-01T10:00:00Z')))->toBe(['after', 'at', 'offset'])
            ->and(listingSet($this, $made, listingFilter($made, 'seen', 'max', '2026-03-01T10:00:00Z')))->toBe(['at', 'before', 'offset'])
            ->and(listingSet($this, $made, ['filter' => [listingKey($made, 'seen') => ['min' => '2026-03-01T10:00:00Z', 'max' => '2026-03-01T10:00:00Z']]]))->toBe(['at', 'offset']);
    });

    it('keeps the times between the bounds, both included', function (): void {
        $made = listingOf(['at' => ['time']]);

        foreach (['early' => '08:30', 'noon' => '12:00', 'late' => '17:45'] as $name => $time) {
            listingAdd($made, $name, ['at' => $time]);
        }

        expect(listingSet($this, $made, listingFilter($made, 'at', 'min', '12:00')))->toBe(['late', 'noon'])
            ->and(listingSet($this, $made, listingFilter($made, 'at', 'max', '12:00')))->toBe(['early', 'noon'])
            ->and(listingSet($this, $made, ['filter' => [listingKey($made, 'at') => ['min' => '08:31', 'max' => '17:44']]]))->toBe(['noon']);
    });

    it('wants a bound in the form the value is stored in (422)', function (string $alias, string $type, mixed $bound): void {
        $made = listingOf([$alias => [$type]]);
        $key = listingKey($made, $alias);

        expect(listingRefused(listingAsk($this, $made, listingFilter($made, $alias, 'min', $bound))))->toBe(["filter.{$key}.min"]);
    })->with([
        'a date that does not exist' => ['bought', 'date', '2026-02-30'],
        'a month 13' => ['bought', 'date', '2026-13-01'],
        'a date written the other way' => ['bought', 'date', '01/03/2026'],
        'a date with a time' => ['bought', 'date', '2026-03-01T10:00:00Z'],
        'a moment with an offset' => ['seen', 'datetime', '2026-03-01T10:00:00+02:00'],
        'a moment without seconds' => ['seen', 'datetime', '2026-03-01T10:00Z'],
        'a moment without zone' => ['seen', 'datetime', '2026-03-01T10:00:00'],
        'a moment with a fraction' => ['seen', 'datetime', '2026-03-01T10:00:00.5Z'],
        'a date for a moment' => ['seen', 'datetime', '2026-03-01'],
        'an hour 25' => ['at', 'time', '25:00'],
        'a time with seconds' => ['at', 'time', '10:00:00'],
        'a time with one digit' => ['at', 'time', '9:00'],
        'a list' => ['at', 'time', ['10:00']],
    ]);
});

describe('filtering by yes or no', function (): void {
    it('keeps the yes, or the no, and tells a no from a value that is missing', function (): void {
        $made = listingOf(['done' => ['boolean']]);
        listingAdd($made, 'yes', ['done' => true]);
        listingAdd($made, 'no', ['done' => false]);
        listingAdd($made, 'blank');

        expect(listingSet($this, $made, listingFilter($made, 'done', 'is', 'true')))->toBe(['yes'])
            ->and(listingSet($this, $made, listingFilter($made, 'done', 'is', '1')))->toBe(['yes'])
            ->and(listingSet($this, $made, listingFilter($made, 'done', 'is', true)))->toBe(['yes'])
            ->and(listingSet($this, $made, listingFilter($made, 'done', 'is', 'false')))->toBe(['no'])
            ->and(listingSet($this, $made, listingFilter($made, 'done', 'is', '0')))->toBe(['no'])
            ->and(listingSet($this, $made, listingFilter($made, 'done', 'is', 'FALSE')))->toBe(['no'])
            ->and(listingSet($this, $made, listingFilter($made, 'done', 'empty', 'true')))->toBe(['blank'])
            ->and(listingSet($this, $made, listingFilter($made, 'done', 'empty', 'false')))->toBe(['no', 'yes']);
    });

    it('wants true or false (422)', function (mixed $value): void {
        $made = listingOf(['done' => ['boolean']]);
        $key = listingKey($made, 'done');

        expect(listingRefused(listingAsk($this, $made, listingFilter($made, 'done', 'is', $value))))->toBe(["filter.{$key}.is"]);
    })->with(['yes', '2', 'maybe', [['true']]]);
});

describe('filtering by choice', function (): void {
    it('keeps the items that hold any of the choices of a select', function (): void {
        $made = listingOf(['kind' => ['select', ['choices' => [['label' => 'Red'], ['label' => 'Green'], ['label' => 'Blue']]]]]);
        $ids = listingChoices($made, 'kind');
        listingAdd($made, 'red', ['kind' => $ids['Red']]);
        listingAdd($made, 'green', ['kind' => $ids['Green']]);
        listingAdd($made, 'blue', ['kind' => $ids['Blue']]);
        listingAdd($made, 'blank');

        expect(listingSet($this, $made, listingFilter($made, 'kind', 'in', [$ids['Red']])))->toBe(['red'])
            ->and(listingSet($this, $made, listingFilter($made, 'kind', 'in', [$ids['Red'], $ids['Blue']])))->toBe(['blue', 'red'])
            ->and(listingSet($this, $made, listingFilter($made, 'kind', 'in', [$ids['Red'], $ids['Red']])))->toBe(['red'])
            ->and(listingSet($this, $made, listingFilter($made, 'kind', 'in', array_values($ids))))->toBe(['blue', 'green', 'red'])
            ->and(listingSet($this, $made, listingFilter($made, 'kind', 'empty', 'true')))->toBe(['blank'])
            ->and(listingSet($this, $made, listingFilter($made, 'kind', 'empty', 'false')))->toBe(['blue', 'green', 'red']);
    });

    it('keeps the items that hold any of the choices of a multiple choice', function (): void {
        $made = listingOf(['flags' => ['multiselect', ['choices' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C']]]]]);
        $ids = listingChoices($made, 'flags');
        listingAdd($made, 'a', ['flags' => [$ids['A']]]);
        listingAdd($made, 'ab', ['flags' => [$ids['A'], $ids['B']]]);
        listingAdd($made, 'c', ['flags' => [$ids['C']]]);
        listingAdd($made, 'blank');

        expect(listingSet($this, $made, listingFilter($made, 'flags', 'in', [$ids['A']])))->toBe(['a', 'ab'])
            ->and(listingSet($this, $made, listingFilter($made, 'flags', 'in', [$ids['B'], $ids['C']])))->toBe(['ab', 'c'])
            ->and(listingSet($this, $made, listingFilter($made, 'flags', 'in', [$ids['A'], $ids['B'], $ids['C']])))->toBe(['a', 'ab', 'c'])
            ->and(listingSet($this, $made, listingFilter($made, 'flags', 'in', [$ids['C'], $ids['C']])))->toBe(['c']);
    });

    it('wants a non-empty list of choices of the field (422)', function (mixed $value): void {
        $made = listingOf(['kind' => ['select', ['choices' => [['label' => 'Red']]]], 'flags' => ['multiselect', ['choices' => [['label' => 'A']]]]]);
        $other = listingOf(['kind' => ['select', ['choices' => [['label' => 'Red']]]]]);
        $kind = listingKey($made, 'kind');
        $flags = listingKey($made, 'flags');
        $foreign = $value === 'foreign' ? [listingChoices($other, 'kind')['Red']] : $value;

        expect(listingRefused(listingAsk($this, $made, listingFilter($made, 'kind', 'in', $foreign))))->toBe(["filter.{$kind}.in"])
            ->and(listingRefused(listingAsk($this, $made, listingFilter($made, 'flags', 'in', $foreign))))->toBe(["filter.{$flags}.in"]);
    })->with([
        'a text' => 'abcdefgh',
        'an unknown id' => [['zzzzzzzz']],
        'an id of another field' => 'foreign',
        'an id in capitals' => [['ABCDEFGH']],
        'a label' => [['Red']],
        'a nested list' => [[['abcdefgh']]],
    ]);

    it('refuses an empty list of choices (422)', function (): void {
        $made = listingOf(['kind' => ['select', ['choices' => [['label' => 'Red']]]]]);
        $kind = listingKey($made, 'kind');

        expect(listingRefused(listingAsk($this, $made, ['filter' => [$kind => ['in' => '']]])))->toBe(["filter.{$kind}.in"]);
    });
});

describe('filtering by what is empty', function (): void {
    it('keeps the items that hold no value, or the ones that do, whatever the kind of field', function (): void {
        $made = listingOf([
            'note' => ['text'],
            'qty' => ['number'],
            'bought' => ['date'],
            'done' => ['boolean'],
            'kind' => ['select', ['choices' => [['label' => 'Red']]]],
            'flags' => ['multiselect', ['choices' => [['label' => 'A'], ['label' => 'B']]]],
            'labels' => ['tags'],
        ]);
        $kind = listingChoices($made, 'kind')['Red'];
        $flags = listingChoices($made, 'flags');

        listingAdd($made, 'full', ['note' => 'x', 'qty' => '0', 'bought' => '2026-01-01', 'done' => false, 'kind' => $kind, 'flags' => [$flags['A'], $flags['B']], 'labels' => ['one', 'two']]);
        listingAdd($made, 'empty');

        foreach (['note', 'qty', 'bought', 'done', 'kind', 'flags', 'labels'] as $alias) {
            expect(listingSet($this, $made, listingFilter($made, $alias, 'empty', 'true')))->toBe(['empty'], $alias)
                ->and(listingSet($this, $made, listingFilter($made, $alias, 'empty', 'false')))->toBe(['full'], $alias)
                ->and(listingSet($this, $made, listingFilter($made, $alias, 'empty', '1')))->toBe(['empty'], $alias)
                ->and(listingSet($this, $made, listingFilter($made, $alias, 'empty', '0')))->toBe(['full'], $alias);
        }
    });

    it('takes zero and no for values, not for nothing', function (): void {
        $made = listingOf(['qty' => ['number'], 'done' => ['boolean']]);
        listingAdd($made, 'zero', ['qty' => '0', 'done' => false]);
        listingAdd($made, 'blank');

        expect(listingSet($this, $made, listingFilter($made, 'qty', 'empty', 'true')))->toBe(['blank'])
            ->and(listingSet($this, $made, listingFilter($made, 'done', 'empty', 'true')))->toBe(['blank']);
    });

    it('wants true or false (422)', function (mixed $value): void {
        $made = listingOf(['qty' => ['number']]);
        $key = listingKey($made, 'qty');

        expect(listingRefused(listingAsk($this, $made, listingFilter($made, 'qty', 'empty', $value))))->toBe(["filter.{$key}.empty"]);
    })->with(['yes', '', 'null', [['true']]]);
});

describe('combining filters', function (): void {
    it('keeps the items that meet every condition, the search words included', function (): void {
        $made = listingOf(['qty' => ['number'], 'done' => ['boolean'], 'note' => ['text']]);
        listingAdd($made, 'red hammer', ['qty' => '5', 'done' => true, 'note' => 'garage']);
        listingAdd($made, 'blue hammer', ['qty' => '5', 'done' => false, 'note' => 'garage']);
        listingAdd($made, 'red saw', ['qty' => '1', 'done' => true, 'note' => 'garage']);
        listingAdd($made, 'red drill', ['qty' => '9', 'done' => true]);

        $filter = ['filter' => [
            listingKey($made, 'qty') => ['min' => '2'],
            listingKey($made, 'done') => ['is' => 'true'],
        ]];

        expect(listingSet($this, $made, $filter))->toBe(['red drill', 'red hammer'])
            ->and(listingSet($this, $made, $filter + ['q' => 'hammer']))->toBe(['red hammer'])
            ->and(listingSet($this, $made, ['filter' => $filter['filter'] + [listingKey($made, 'note') => ['empty' => 'false']]]))->toBe(['red hammer'])
            ->and(listingSet($this, $made, $filter + ['q' => 'saw']))->toBe([])
            ->and(listingOrder($this, $made, $filter + ['sort' => listingKey($made, 'qty'), 'direction' => 'desc']))->toBe(['red drill', 'red hammer']);
    });

    it('counts the items that match, and keeps the query in the links of the pages', function (): void {
        $made = listingOf(['qty' => ['number']]);
        $key = listingKey($made, 'qty');

        foreach (range(1, 60) as $number) {
            Item::factory()->for($made['inventory'])->create(['values' => [$key => (string) $number]]);
        }

        // 50 items match: one page.
        $few = listingAsk($this, $made, ['sort' => $key, 'filter' => [$key => ['min' => '11', 'max' => '60']]])->assertOk();

        expect($few->json('meta.total'))->toBe(50)
            ->and($few->json('data'))->toHaveCount(50)
            ->and($few->json('links.next'))->toBeNull();

        // 60 match: the link to the next page carries the sort, the direction and the filter.
        $query = ['sort' => $key, 'direction' => 'desc', 'filter' => [$key => ['min' => '1']]];
        $page = listingAsk($this, $made, $query)->assertOk();
        $next = (string) $page->json('links.next');
        $link = parse_url($next);
        $second = inventoryCall($this, $made['team']['owner'], 'GET', $link['path'].'?'.$link['query'])->assertOk();

        expect($page->json('meta.total'))->toBe(60)
            ->and(urldecode($next))->toContain("sort={$key}", 'direction=desc', "filter[{$key}][min]=1", 'page=2')
            ->and($second->json('data'))->toHaveCount(10)
            ->and($second->json("data.0.values.{$key}"))->toBe('10')
            ->and($second->json("data.9.values.{$key}"))->toBe('1')
            ->and($second->json('meta.total'))->toBe(60)
            ->and($second->json('meta.current_page'))->toBe(2);
    });

    it('finds the field of a filter whatever the case of its key', function (): void {
        $made = listingOf(['qty' => ['number']]);
        listingAdd($made, 'big', ['qty' => '5']);
        listingAdd($made, 'blank');

        expect(listingSet($this, $made, ['filter' => [strtoupper(listingKey($made, 'qty')) => ['min' => '1']]]))->toBe(['big'])
            ->and(listingSet($this, $made, ['filter' => [strtoupper(listingKey($made, 'qty')) => ['empty' => 'true']]]))->toBe(['blank']);
    });

    it('is open to a viewer', function (): void {
        $made = listingOf(['qty' => ['number']]);
        listingAdd($made, 'big', ['qty' => '5']);
        listingAdd($made, 'small', ['qty' => '1']);

        expect(listingNames(listingAsk($this, $made, listingFilter($made, 'qty', 'min', '2'), 'viewer'), $made))->toBe(['big']);
    });
});

describe('refusing a filter that makes no sense', function (): void {
    it('refuses a field the inventory does not have (422)', function (string $key): void {
        $made = listingOf(['qty' => ['number']]);
        $other = listingOf(['qty' => ['number']]);
        $key = $key === 'foreign' ? listingKey($other, 'qty') : $key;

        expect(listingRefused(listingAsk($this, $made, ['filter' => [$key => ['empty' => 'true']]])))->toBe(['filter.'.strtolower($key)]);
    })->with(['an unknown ULID' => '01arz3ndektsv4rrffq69g5fav', 'a name' => 'qty', 'a word' => 'created_at', 'a field of another inventory' => 'foreign']);

    it('refuses an operator the kind of field does not know (422)', function (string $alias, string $operator): void {
        $made = listingOf([
            'note' => ['text'],
            'qty' => ['number'],
            'bought' => ['date'],
            'done' => ['boolean'],
            'kind' => ['select', ['choices' => [['label' => 'Red']]]],
            'flags' => ['multiselect', ['choices' => [['label' => 'A']]]],
            'labels' => ['tags'],
        ]);
        $key = listingKey($made, $alias);

        expect(listingRefused(listingAsk($this, $made, listingFilter($made, $alias, $operator, 'true'))))->toBe(["filter.{$key}.{$operator}"]);
    })->with([
        'text min' => ['note', 'min'],
        'text is' => ['note', 'is'],
        'text in' => ['note', 'in'],
        'tags max' => ['labels', 'max'],
        'tags in' => ['labels', 'in'],
        'number is' => ['qty', 'is'],
        'number in' => ['qty', 'in'],
        'date is' => ['bought', 'is'],
        'date in' => ['bought', 'in'],
        'boolean min' => ['done', 'min'],
        'boolean in' => ['done', 'in'],
        'select min' => ['kind', 'min'],
        'select is' => ['kind', 'is'],
        'multiselect max' => ['flags', 'max'],
        'an operator nobody knows' => ['qty', 'like'],
        'an operator in capitals' => ['qty', 'MIN'],
    ]);

    it('refuses conditions that are not an object, a field given twice, and every mistake at once (422)', function (): void {
        $made = listingOf(['qty' => ['number'], 'done' => ['boolean']]);
        $qty = listingKey($made, 'qty');
        $done = listingKey($made, 'done');
        $everything = ['direction', 'sort', "filter.{$done}.is", "filter.{$qty}.max", "filter.{$qty}.min", 'filter.nope'];
        sort($everything);

        expect(listingRefused(listingAsk($this, $made, ['filter' => [$qty => 'x']])))->toBe(["filter.{$qty}"])
            ->and(listingRefused(listingAsk($this, $made, ['filter' => 'x'])))->toBe(['filter'])
            ->and(listingRefused(listingAsk($this, $made, ['filter' => [$qty => ['min' => 'x', 'max' => 'y'], $done => ['is' => 'z'], 'nope' => ['empty' => 'true']], 'sort' => 'nope', 'direction' => 'up'])))
            ->toBe($everything);
    });

    it('refuses the same field twice in two cases (422)', function (): void {
        $made = listingOf(['qty' => ['number']]);
        $key = listingKey($made, 'qty');

        expect(listingRefused(listingAsk($this, $made, ['filter' => [$key => ['min' => '1'], strtoupper($key) => ['max' => '5']]])))->toBe(["filter.{$key}"]);
    });

    it('gives the messages in words, in the language of the request', function (): void {
        $made = listingOf(['qty' => ['number']]);
        $key = listingKey($made, 'qty');
        $query = listingFilter($made, 'qty', 'is', 'true') + ['sort' => 'nope'];

        $english = listingAsk($this, $made, $query, 'owner', ['Accept-Language' => 'en'])->assertUnprocessable();
        $french = listingAsk($this, $made, $query, 'owner', ['Accept-Language' => 'fr'])->assertUnprocessable();

        expect($english->json('errors'))->toBe([
            "filter.{$key}.is" => ['This filter does not apply to this kind of field.'],
            'sort' => ['This field does not exist in this inventory.'],
        ])->and($french->json('errors'))->toBe([
            "filter.{$key}.is" => ["Ce filtre ne s'applique pas à ce genre de champ."],
            'sort' => ["Ce champ n'existe pas dans cet inventaire."],
        ]);
    });
});

describe('totals', function (): void {
    it('adds up the numbers and amounts exactly, as decimal strings with the decimals of the field', function (): void {
        $made = listingOf(['qty' => ['number', ['decimals' => 2]], 'price' => ['money'], 'pct' => ['percentage'], 'stars' => ['rating']]);
        listingAdd($made, 'a', ['qty' => '0.10', 'price' => '0.10', 'pct' => '10', 'stars' => 3]);
        listingAdd($made, 'b', ['qty' => '0.20', 'price' => '0.20', 'pct' => '20', 'stars' => 4]);
        listingAdd($made, 'blank');

        $totals = listingTotals($this, $made, ['qty', 'price']);

        expect($totals)->toBe([listingKey($made, 'qty') => '0.30', listingKey($made, 'price') => '0.30']);
    });

    it('does not lose a cent on the largest amounts', function (): void {
        $made = listingOf(['price' => ['money']]);

        foreach (['a', 'b', 'c'] as $name) {
            listingAdd($made, $name, ['price' => '999999999999999.99']);
        }

        expect(listingTotals($this, $made, ['price']))->toBe([listingKey($made, 'price') => '2999999999999999.97']);
    });

    it('adds negative numbers, and a large number of items', function (): void {
        $made = listingOf(['qty' => ['number', ['decimals' => 3]]]);
        $key = listingKey($made, 'qty');

        foreach (range(1, 120) as $number) {
            Item::factory()->for($made['inventory'])->create(['values' => [$key => $number % 2 === 0 ? '0.001' : '-0.002']]);
        }

        // 60 times 0.001 less 60 times 0.002: all the matching items count, not the 50 of the page.
        expect(listingTotals($this, $made, ['qty']))->toBe([$key => '-0.060']);
    });

    it('writes an amount with the decimals of its currency', function (string $currency, string $sent, string $total, string $zero): void {
        $made = listingOf(['price' => ['money', ['currency' => $currency]]]);
        $key = listingKey($made, 'price');

        expect(listingTotals($this, $made, ['price']))->toBe([$key => $zero]);

        listingAdd($made, 'one', ['price' => $sent]);
        listingAdd($made, 'two', ['price' => $sent]);

        expect(listingTotals($this, $made, ['price']))->toBe([$key => $total]);
    })->with([
        'euros' => ['EUR', '1234.5', '2469.00', '0.00'],
        'yen' => ['JPY', '1234', '2468', '0'],
        'dinars' => ['KWD', '1.5', '3.000', '0.000'],
    ]);

    it('is zero, with the decimals of the field, when no item holds a value', function (): void {
        $made = listingOf(['none' => ['number'], 'three' => ['number', ['decimals' => 3]], 'six' => ['number', ['decimals' => 6]]]);
        listingAdd($made, 'blank');

        expect(listingTotals($this, $made, ['none', 'three', 'six']))->toBe([
            listingKey($made, 'none') => '0',
            listingKey($made, 'three') => '0.000',
            listingKey($made, 'six') => '0.000000',
        ]);
    });

    it('has more decimals than the field when values stored under older options have more', function (): void {
        $made = listingOf(['qty' => ['number', ['decimals' => 3]]]);
        $field = $made['fields']['qty'];
        listingAdd($made, 'a', ['qty' => '1.234']);
        listingAdd($made, 'b', ['qty' => '2']);
        // The decimals of a field can be lowered: the values already stored keep theirs.
        DB::table('inventory_fields')->where('id', $field->id)->update(['options' => json_encode(['decimals' => 1])]);

        expect(listingTotals($this, $made, ['qty']))->toBe([$field->ulid => '3.234']);
    });

    it('adds up the items that match the search and the filters, not the whole inventory', function (): void {
        $made = listingOf(['qty' => ['number'], 'price' => ['money'], 'done' => ['boolean']]);
        listingAdd($made, 'red hammer', ['qty' => '5', 'price' => '10', 'done' => true]);
        listingAdd($made, 'blue hammer', ['qty' => '3', 'price' => '20', 'done' => false]);
        listingAdd($made, 'red saw', ['qty' => '1', 'price' => '30', 'done' => true]);
        $qty = listingKey($made, 'qty');
        $price = listingKey($made, 'price');

        $totals = fn (array $query): array => listingTotals($this, $made, ['qty', 'price'], $query);

        expect($totals([]))->toBe([$qty => '9', $price => '60.00'])
            ->and($totals(['q' => 'hammer']))->toBe([$qty => '8', $price => '30.00'])
            ->and($totals(listingFilter($made, 'done', 'is', 'true')))->toBe([$qty => '6', $price => '40.00'])
            ->and($totals(listingFilter($made, 'qty', 'min', '3') + ['q' => 'red']))->toBe([$qty => '5', $price => '10.00'])
            ->and($totals(listingFilter($made, 'qty', 'min', '100')))->toBe([$qty => '0', $price => '0.00'])
            ->and($totals(['sort' => $price, 'direction' => 'desc', 'page' => 1]))->toBe([$qty => '9', $price => '60.00']);
    });

    it('is not changed by the page, and sits with the pagination in meta', function (): void {
        $made = listingOf(['qty' => ['number']]);
        $key = listingKey($made, 'qty');

        foreach (range(1, 55) as $number) {
            Item::factory()->for($made['inventory'])->create(['values' => [$key => '2']]);
        }

        $first = listingAsk($this, $made, ['totals' => [$key]])->assertOk();
        $second = listingAsk($this, $made, ['totals' => [$key], 'page' => 2])->assertOk();

        expect($first->json('meta.totals'))->toBe([$key => '110'])
            ->and($second->json('meta.totals'))->toBe([$key => '110'])
            ->and($first->json('meta.total'))->toBe(55)
            ->and($first->json('meta.current_page'))->toBe(1)
            ->and($second->json('data'))->toHaveCount(5);
    });

    it('is not run, and not in meta, unless the list asks for totals', function (): void {
        $made = listingOf(['qty' => ['number'], 'price' => ['money']]);
        listingAdd($made, 'one', ['qty' => '4', 'price' => '10']);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $plain = listingAsk($this, $made)->assertOk();
        $sums = function () use (&$queries): array {
            return array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'sum(')));
        };

        expect($plain->json('meta'))->not->toHaveKey('totals')
            ->and($plain->json('meta.total'))->toBe(1)
            ->and($sums())->toBe([]);

        // Nothing is asked for in a query string that names nothing (`totals=` is no list at all).
        expect(listingAsk($this, $made, ['totals' => ''])->assertOk()->json('meta'))->not->toHaveKey('totals')
            ->and($sums())->toBe([]);

        listingTotals($this, $made, ['qty']);

        expect($sums())->toHaveCount(1);
    });

    it('gives only the totals that are asked for, each once, whatever the case of the name', function (): void {
        $made = listingOf(['qty' => ['number'], 'price' => ['money'], 'weight' => ['number']]);
        listingAdd($made, 'one', ['qty' => '4', 'price' => '10', 'weight' => '7']);
        listingAdd($made, 'two', ['qty' => '1', 'price' => '5', 'weight' => '3']);
        $qty = listingKey($made, 'qty');
        $price = listingKey($made, 'price');

        expect(listingTotals($this, $made, ['qty']))->toBe([$qty => '5'])
            ->and(listingTotals($this, $made, ['price', 'qty']))->toBe([$price => '15.00', $qty => '5'])
            ->and(listingTotals($this, $made, [strtoupper($qty), $qty, strtoupper($price), $qty]))->toBe([$qty => '5', $price => '15.00']);
    });

    it('keeps the totals asked for in the links of the pages', function (): void {
        $made = listingOf(['qty' => ['number']]);
        $key = listingKey($made, 'qty');

        foreach (range(1, 55) as $number) {
            Item::factory()->for($made['inventory'])->create(['values' => [$key => '2']]);
        }

        $next = urldecode((string) listingAsk($this, $made, ['totals' => [$key]])->assertOk()->json('links.next'));

        expect($next)->toContain("totals[0]={$key}")->and($next)->toContain('page=2');
    });

    it('refuses a name that is no field of the inventory, and a field that does not add up, naming where', function (): void {
        $made = listingOf(['qty' => ['number'], 'pct' => ['percentage'], 'stars' => ['rating'], 'note' => ['text'], 'when' => ['date']]);
        $other = listingOf(['qty' => ['number']]);
        $qty = listingKey($made, 'qty');

        $response = listingAsk($this, $made, ['totals' => [
            $qty, listingKey($made, 'pct'), 'nope', listingKey($made, 'stars'), listingKey($other, 'qty'), listingKey($made, 'note'), listingKey($made, 'when'),
        ]]);

        expect(listingRefused($response))->toBe(['totals.1', 'totals.2', 'totals.3', 'totals.4', 'totals.5', 'totals.6'])
            ->and($response->json('errors'))->toBe([
                'totals.1' => ['This field cannot be added up.'],
                'totals.2' => ['This field does not exist in this inventory.'],
                'totals.3' => ['This field cannot be added up.'],
                'totals.4' => ['This field does not exist in this inventory.'],
                'totals.5' => ['This field cannot be added up.'],
                'totals.6' => ['This field cannot be added up.'],
            ]);

        $french = listingAsk($this, $made, ['totals' => [listingKey($made, 'pct')]], headers: ['Accept-Language' => 'fr']);

        expect($french->assertUnprocessable()->json('errors'))->toBe(['totals.0' => ['Ce champ ne peut pas être additionné.']]);
    });

    it('reports a mistake in the totals together with the others, and refuses what is not a list of names', function (): void {
        $made = listingOf(['qty' => ['number']]);
        $key = listingKey($made, 'qty');

        expect(listingRefused(listingAsk($this, $made, ['totals' => ['nope'], 'sort' => 'nope', 'direction' => 'up'])))->toBe(['direction', 'sort', 'totals.0'])
            ->and(listingRefused(listingAsk($this, $made, ['totals' => 'qty'])))->toBe(['totals'])
            ->and(listingRefused(listingAsk($this, $made, ['totals' => [[$key]]])))->toBe(['totals.0'])
            ->and(listingRefused(listingAsk($this, $made, ['totals' => ['']])))->toBe(['totals.0'])
            ->and(listingRefused(listingAsk($this, $made, ['totals' => [str_repeat('a', 65)]])))->toBe(['totals.0'])
            // At most as many names as an inventory has fields.
            ->and(listingRefused(listingAsk($this, $made, ['totals' => array_fill(0, config('inventories.max_fields') + 1, $key)])))->toBe(['totals']);
    });

    it('refuses every name for an inventory with no field, and has no totals without being asked', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";

        $response = inventoryCall($this, $team['viewer'], 'GET', $uri)->assertOk();

        expect($response->json('meta'))->not->toHaveKey('totals')->and($response->json('meta.total'))->toBe(0)
            ->and(listingRefused(inventoryCall($this, $team['viewer'], 'GET', $uri.'?totals[]=01m513q7z9j8zet4r7c5smwbtz')))->toBe(['totals.0']);
    });

    it('reads the columns of the items it gives, not the text they are searched by', function (): void {
        $made = listingOf(['qty' => ['number']]);
        listingAdd($made, 'one', ['qty' => '4']);
        $selects = [];
        DB::listen(function ($query) use (&$selects): void {
            $sql = strtolower($query->sql);

            // The page itself: a select of the items, not the count (`count(*)`) nor the search of the fields.
            if (str_starts_with($sql, 'select') && preg_match('/from [`"]items[`"]/', $sql) === 1 && ! str_contains($sql, 'count(')) {
                $selects[] = (string) preg_replace('/\s+from\s.*$/s', '', $sql);
            }
        });

        $response = listingAsk($this, $made, ['q' => 'one'])->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0'))->toHaveKeys(['id', 'values', 'created_at', 'updated_at'])
            // Named columns, not `*` (which would read the search text of every item of the page).
            ->and($selects)->toHaveCount(1)
            ->and($selects[0])->toContain('ulid')->toContain('values')->not->toContain('*')->not->toContain('search_text');
    });

    it('is given to a viewer too', function (): void {
        $made = listingOf(['qty' => ['number']]);
        listingAdd($made, 'one', ['qty' => '4']);

        expect(listingTotals($this, $made, ['qty'], [], 'viewer'))->toBe([listingKey($made, 'qty') => '4']);
    });

    it('is left out of the items of another inventory', function (): void {
        $made = listingOf(['qty' => ['number']]);
        $other = listingOf(['qty' => ['number']]);
        listingAdd($made, 'mine', ['qty' => '4']);
        listingAdd($other, 'theirs', ['qty' => '1000']);

        expect(listingTotals($this, $made, ['qty']))->toBe([listingKey($made, 'qty') => '4']);
    });

    it('adds up the items the query matches, whatever its order, its limit and its offset', function (): void {
        $made = listingOf(['qty' => ['number']]);
        $key = listingKey($made, 'qty');

        foreach (range(1, 5) as $number) {
            listingAdd($made, "n{$number}", ['qty' => (string) $number]);
        }

        $sum = fn (EloquentBuilder $items): ?string => Decimal::tryFrom((new JsonValues(DB::connection()))->sums($items->toBase(), [$key])[$key])?->format(0);
        $inventory = fn (): EloquentBuilder => Item::query()->where('inventory_id', $made['inventory']->id);
        $ids = $inventory()->orderBy('id')->pluck('id')->all();

        // A limit and an offset would otherwise cut the one row of the aggregate away (an offset) or count it out of order.
        expect($sum($inventory()))->toBe('15')
            ->and($sum($inventory()->orderBy('id')->limit(2)))->toBe('15')
            ->and($sum($inventory()->orderByDesc('id')->limit(2)->offset(3)))->toBe('15')
            ->and($sum($inventory()->offset(10)))->toBe('15')
            ->and($sum($inventory()->where('id', '>', $ids[2])->limit(1)->offset(1)))->toBe('9')
            ->and(app(ItemQuery::class)->totals($inventory()->orderBy('id')->limit(2)->offset(1), $made['inventory']->fields()->get(), [$key]))->toBe([$key => '15']);
    });

    it('shows zero when no item holds a value, and refuses a total that is not a number rather than showing zero', function (): void {
        $made = listingOf(['qty' => ['number']]);
        $key = listingKey($made, 'qty');
        $fields = $made['inventory']->fields()->get();

        // A connection that answers the aggregate with the sum it is told to, whatever the query.
        $answering = function (mixed $sum) use ($fields, $key): array {
            $connection = new class(fn () => throw new LogicException('Never connected.'), 'db', '', ['driver' => 'pgsql']) extends PostgresConnection
            {
                public mixed $sum = null;

                /**
                 * @param  string  $query
                 * @param  array<mixed>  $bindings
                 * @param  bool  $useReadPdo
                 * @param  array<mixed>  $fetchUsing
                 * @return list<object>
                 */
                public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
                {
                    return [(object) ['sum_0' => $this->sum]];
                }
            };
            $connection->sum = $sum;
            $manager = Mockery::mock(DatabaseManager::class);
            $manager->shouldReceive('connection')->andReturn($connection);

            return (new ItemQuery($manager, app(FieldTypes::class)))->totals((new EloquentBuilder($connection->query()->from('items')))->setModel(new Item), $fields, [$key]);
        };

        expect($answering(null))->toBe([$key => '0'])
            ->and($answering('12.500000'))->toBe([$key => '12.5'])
            ->and($answering(7))->toBe([$key => '7'])
            ->and(fn () => $answering('oops'))->toThrow(LogicException::class)
            ->and(fn () => $answering(''))->toThrow(LogicException::class)
            ->and(fn () => $answering(1.5))->toThrow(LogicException::class)
            ->and(fn () => $answering(['1']))->toThrow(LogicException::class);
    });
});
