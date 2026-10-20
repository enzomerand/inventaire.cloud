<?php

declare(strict_types=1);

use App\Inventories\DerivedData;
use App\Inventories\SearchText;
use App\Models\Inventory;
use App\Models\Item;
use App\Settings\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/inventories.php';

/*
 * What `inventaire:upgrade` brings right after the migrations: the text the items are searched by (built again when its
 * version changes, and for the items that have none: null, never built) and the counters of items (counted again, the wrong
 * ones corrected and logged). An inventory that fails does not stop the others. The command itself is in
 * tests/Feature/Installer/UpgradeCommandTest.php.
 */

uses(RefreshDatabase::class);

/**
 * Two inventories with items whose texts are written by hand: `$wrong` is the text of every item, whatever its values.
 *
 * @return array{tools: Inventory, colors: Inventory, items: array<string, int>}
 */
function derivedInventories(?string $wrong): array
{
    $team = inventoryTeam();
    $tools = inventoryIn($team['workspace'], 'Tools');
    $name = fieldIn($tools, 'text', 'Name');
    $colors = inventoryIn($team['workspace'], 'Colors');
    $palette = fieldIn($colors, 'multiselect', 'Palette', ['choices' => [['label' => 'Rouge'], ['label' => 'Bleu']]]);
    [$red, $blue] = array_column($palette->options['choices'], 'id');

    $items = [
        'special' => itemIn($tools, [$name->ulid => 'Électricité'])->id,
        'nothing' => itemIn($tools)->id,
        'mixed' => itemIn($colors, [$palette->ulid => [$blue, $red]])->id,
    ];
    DB::table('items')->update(['search_text' => $wrong, 'updated_at' => '2020-01-01 00:00:00']);

    return ['tools' => $tools, 'colors' => $colors, 'items' => $items];
}

/**
 * The text of an item as the database holds it.
 */
function derivedText(int $id): ?string
{
    return DB::table('items')->where('id', $id)->value('search_text');
}

describe('the search text', function (): void {
    it('is built for every item when no version was ever recorded, and the version is recorded', function (): void {
        $made = derivedInventories('stale');
        expect(app(InstanceSettings::class)->searchTextVersion())->toBeNull();

        $rebuild = app(DerivedData::class)->rebuildSearchText();

        expect($rebuild->everything)->toBeTrue()
            ->and($rebuild->failed)->toBe(0)
            ->and(derivedText($made['items']['special']))->toBe('electricite')
            // Built, and found to say nothing: an empty text, not null (which would say it was never built).
            ->and(derivedText($made['items']['nothing']))->toBe('')
            ->and(derivedText($made['items']['mixed']))->toBe('bleu rouge')
            ->and(app(InstanceSettings::class)->searchTextVersion())->toBe(SearchText::VERSION)
            // The items did not change, the way they are found did: they keep their dates.
            ->and(DB::table('items')->where('updated_at', '!=', '2020-01-01 00:00:00')->count())->toBe(0);
    });

    it('is built again for every item when the version it was built with is not the current one', function (): void {
        $made = derivedInventories('stale');
        app(InstanceSettings::class)->recordSearchTextVersion(SearchText::VERSION - 1);

        expect(app(DerivedData::class)->rebuildSearchText()->everything)->toBeTrue()
            ->and(derivedText($made['items']['special']))->toBe('electricite')
            ->and(derivedText($made['items']['mixed']))->toBe('bleu rouge')
            ->and(app(InstanceSettings::class)->searchTextVersion())->toBe(SearchText::VERSION);
    });

    it('is built again when the version that is recorded cannot be read', function (): void {
        $made = derivedInventories('stale');
        DB::table('settings')->insert(['key' => InstanceSettings::SEARCH_TEXT_VERSION, 'value' => json_encode('one'), 'created_at' => now(), 'updated_at' => now()]);

        expect(app(DerivedData::class)->rebuildSearchText()->everything)->toBeTrue()
            ->and(derivedText($made['items']['special']))->toBe('electricite')
            ->and(app(InstanceSettings::class)->searchTextVersion())->toBe(SearchText::VERSION);
    });

    it('is left alone when the version is the current one, but for the items that have none', function (): void {
        $made = derivedInventories('stale');
        DB::table('items')->where('id', $made['items']['special'])->update(['search_text' => null]);
        DB::table('items')->where('id', $made['items']['nothing'])->update(['search_text' => null]);
        app(InstanceSettings::class)->recordSearchTextVersion(SearchText::VERSION);

        expect(app(DerivedData::class)->rebuildSearchText()->everything)->toBeFalse()
            // No text (null): built, and an item that says nothing gets an empty one, so that it is not looked at again.
            ->and(derivedText($made['items']['special']))->toBe('electricite')
            ->and(derivedText($made['items']['nothing']))->toBe('')
            // A text, however stale: the version says it is right, so it is not read again.
            ->and(derivedText($made['items']['mixed']))->toBe('stale');
    });

    it('does not write an item whose text is right, and does not record the version again', function (): void {
        derivedInventories(null);
        app(DerivedData::class)->rebuildSearchText();
        DB::table('settings')->where('key', InstanceSettings::SEARCH_TEXT_VERSION)->update(['updated_at' => '2020-01-01 00:00:00']);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^(update|insert)/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        app(DerivedData::class)->rebuildSearchText();

        expect($writes)->toBe([])
            ->and(DB::table('settings')->where('key', InstanceSettings::SEARCH_TEXT_VERSION)->value('updated_at'))->toBe('2020-01-01 00:00:00');
    });

    it('goes through more items than a chunk, in every inventory', function (): void {
        $team = inventoryTeam();
        $tools = inventoryIn($team['workspace'], 'Tools');
        $name = fieldIn($tools, 'text', 'Name');
        $empty = inventoryIn($team['workspace'], 'Empty');
        $now = now();
        DB::table('items')->insert(array_map(fn (int $n): array => [
            'ulid' => (string) Str::ulid(), 'inventory_id' => $tools->id, 'values' => json_encode([$name->ulid => "Pièce {$n}"]), 'created_at' => $now, 'updated_at' => $now,
        ], range(1, 520)));

        app(DerivedData::class)->rebuildSearchText();

        $pieces = fn (int $n): int => DB::table('items')->where('inventory_id', $tools->id)->where('search_text', "piece {$n}")->count();

        expect($pieces(1))->toBe(1)->and($pieces(500))->toBe(1)->and($pieces(501))->toBe(1)->and($pieces(520))->toBe(1)
            ->and(DB::table('items')->where('search_text', 'like', 'piece %')->count())->toBe(520)
            ->and(DB::table('items')->where('inventory_id', $empty->id)->count())->toBe(0);
    });

    it('does not record the version when it fails, so that the next run starts again', function (): void {
        $made = derivedInventories('stale');
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'search_text')) {
                throw new RuntimeException('The disk is full.');
            }
        });
        Log::spy();

        $rebuild = app(DerivedData::class)->rebuildSearchText();

        // Every inventory failed, none of them stopped the upgrade, and nothing was written.
        expect($rebuild->failed)->toBe(2)
            ->and($rebuild->everything)->toBeTrue()
            ->and(app(InstanceSettings::class)->searchTextVersion())->toBeNull()
            ->and(derivedText($made['items']['special']))->toBe('stale');
    });

    it('goes on with the other inventories when one fails, counts and logs the failure, and does not record the version', function (): void {
        $made = derivedInventories('stale');
        // The second item of the first inventory: its first item is written before, and the rollback takes that write back.
        $failing = $made['items']['nothing'];
        DB::listen(function ($query) use ($failing): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'search_text') && end($query->bindings) == $failing) {
                throw new RuntimeException('The disk is full.');
            }
        });
        Log::spy();

        $rebuild = app(DerivedData::class)->rebuildSearchText();

        expect($rebuild->failed)->toBe(1)
            ->and($rebuild->everything)->toBeTrue()
            // The inventory that failed is as it was: its transaction was rolled back as a whole.
            ->and(derivedText($made['items']['special']))->toBe('stale')
            ->and(derivedText($made['items']['nothing']))->toBe('stale')
            // The other one was done.
            ->and(derivedText($made['items']['mixed']))->toBe('bleu rouge')
            ->and(app(InstanceSettings::class)->searchTextVersion())->toBeNull();

        Log::shouldHaveReceived('error')->once();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'search text')
            && $context['inventory_id'] === $made['tools']->id && $context['exception'] instanceof RuntimeException);
    });

    it('records the version on the run after a failure, once nothing fails any more', function (): void {
        $made = derivedInventories('stale');
        $listener = true;
        DB::listen(function ($query) use (&$listener): void {
            if ($listener && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'search_text')) {
                throw new RuntimeException('The disk is full.');
            }
        });
        Log::spy();

        expect(app(DerivedData::class)->rebuildSearchText()->failed)->toBe(2)
            ->and(app(InstanceSettings::class)->searchTextVersion())->toBeNull();

        $listener = false;
        $second = app(DerivedData::class)->rebuildSearchText();

        // No version was recorded, so everything is built again in full.
        expect($second->failed)->toBe(0)
            ->and($second->everything)->toBeTrue()
            ->and(derivedText($made['items']['special']))->toBe('electricite')
            ->and(derivedText($made['items']['mixed']))->toBe('bleu rouge')
            ->and(app(InstanceSettings::class)->searchTextVersion())->toBe(SearchText::VERSION);
    });

    it('goes on with the other inventories when one fails to be read for the missing texts only', function (): void {
        $made = derivedInventories('stale');
        DB::table('items')->update(['search_text' => null]);
        app(InstanceSettings::class)->recordSearchTextVersion(SearchText::VERSION);
        $failing = $made['items']['special'];
        DB::listen(function ($query) use ($failing): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'search_text') && end($query->bindings) == $failing) {
                throw new RuntimeException('Deadlock.');
            }
        });
        Log::spy();

        $rebuild = app(DerivedData::class)->rebuildSearchText();

        expect($rebuild->failed)->toBe(1)
            ->and($rebuild->everything)->toBeFalse()
            ->and(derivedText($made['items']['special']))->toBeNull()
            ->and(derivedText($made['items']['mixed']))->toBe('bleu rouge')
            ->and(app(InstanceSettings::class)->searchTextVersion())->toBe(SearchText::VERSION);
    });

    it('does not lock an inventory that has no item without a text when only the missing ones are built', function (): void {
        $made = derivedInventories('stale');
        // Only the first inventory holds an item that was never built: the other one holds a text, however stale.
        DB::table('items')->where('id', $made['items']['special'])->update(['search_text' => null]);
        app(InstanceSettings::class)->recordSearchTextVersion(SearchText::VERSION);
        $locked = [];
        DB::listen(function ($query) use (&$locked): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'for update') && preg_match('/from [`"]inventories[`"]/', $sql) === 1) {
                $locked[] = $query->bindings;
            }
        });

        $rebuild = app(DerivedData::class)->rebuildSearchText();

        expect($rebuild->everything)->toBeFalse()
            ->and($locked)->toHaveCount(1)
            ->and(derivedText($made['items']['special']))->toBe('electricite')
            ->and(derivedText($made['items']['mixed']))->toBe('stale');
    });

    it('does not lock any inventory when every item has a text, and does not read the items to find out', function (): void {
        derivedInventories('stale');
        app(InstanceSettings::class)->recordSearchTextVersion(SearchText::VERSION);
        $seen = [];
        DB::listen(function ($query) use (&$seen): void {
            $seen[] = strtolower($query->sql);
        });

        app(DerivedData::class)->rebuildSearchText();

        expect(array_filter($seen, fn (string $sql): bool => str_contains($sql, 'for update')))->toBe([])
            ->and(array_filter($seen, fn (string $sql): bool => str_starts_with($sql, 'select') && preg_match('/from [`"]items[`"]/', $sql) === 1 && ! str_contains($sql, 'search_text')))->toBe([]);
    });

    it('treats an empty text as built, not as missing: an item that says nothing is not looked at again', function (): void {
        $made = derivedInventories(null);
        app(DerivedData::class)->rebuildSearchText();
        expect(derivedText($made['items']['nothing']))->toBe('');
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^(update|insert)/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $second = app(DerivedData::class)->rebuildSearchText();

        // Nothing is null any more: the inventories are not even locked, let alone written.
        expect($second->everything)->toBeFalse()
            ->and($writes)->toBe([])
            ->and(Item::query()->whereNull('search_text')->count())->toBe(0);
    });

    it('takes the lock of each inventory before it reads or writes in it, in a transaction of its own', function (): void {
        derivedInventories('stale');
        $seen = [];
        DB::listen(function ($query) use (&$seen): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'for update') && preg_match('/from [`"]inventories[`"]/', $sql) === 1) {
                $seen[] = DB::transactionLevel();
            }
        });

        app(DerivedData::class)->rebuildSearchText();

        // Two inventories, each locked inside a transaction nested in the one of the test (level 1): level 2.
        expect($seen)->toBe([2, 2]);
    });

    it('is not in the settings the API gives', function (): void {
        app(InstanceSettings::class)->recordSearchTextVersion(SearchText::VERSION);

        // The choices of the administrator, and not the bookkeeping of the search text.
        expect(app(InstanceSettings::class)->all())->toBe(['registration_open' => true, 'invitation_sign_up' => true])
            ->and(app(InstanceSettings::class)->all())->not->toHaveKey(InstanceSettings::SEARCH_TEXT_VERSION)
            ->and(app(InstanceSettings::class)->searchTextVersion())->toBe(SearchText::VERSION);
    });
});

describe('the counters of items', function (): void {
    it('are corrected where they differ from the items, and the others are not written', function (): void {
        $team = inventoryTeam();
        $right = inventoryIn($team['workspace'], 'Right');
        $high = inventoryIn($team['workspace'], 'High');
        $low = inventoryIn($team['workspace'], 'Low');
        $empty = inventoryIn($team['workspace'], 'Empty');
        itemIn($right);
        itemIn($high);
        itemIn($high);
        itemIn($low);
        DB::table('inventories')->update(['updated_at' => '2020-01-01 00:00:00']);
        DB::table('inventories')->where('id', $high->id)->update(['items_count' => 7]);
        DB::table('inventories')->where('id', $low->id)->update(['items_count' => 0]);
        DB::table('inventories')->where('id', $empty->id)->update(['items_count' => 3]);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (str_starts_with(strtolower($query->sql), 'update')) {
                $writes[] = $query->bindings;
            }
        });

        $wrong = app(DerivedData::class)->recountItems();

        $counter = fn (Inventory $inventory): int => (int) DB::table('inventories')->where('id', $inventory->id)->value('items_count');

        expect($wrong)->toBe(3)
            ->and([$counter($right), $counter($high), $counter($low), $counter($empty)])->toBe([1, 2, 1, 0])
            // One write for each wrong counter, none for the right one; the inventories keep their dates.
            ->and($writes)->toHaveCount(3)
            ->and(DB::table('inventories')->where('updated_at', '!=', '2020-01-01 00:00:00')->count())->toBe(0);
    });

    it('are logged as a warning with the inventory, only for those that were wrong', function (): void {
        $team = inventoryTeam();
        $right = inventoryIn($team['workspace'], 'Right');
        $high = inventoryIn($team['workspace'], 'High');
        itemIn($right);
        itemIn($high);
        DB::table('inventories')->where('id', $high->id)->update(['items_count' => 7]);
        Log::spy();

        app(DerivedData::class)->recountItems();

        Log::shouldHaveReceived('warning')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'counter')
            && $context === ['inventory_id' => $high->id, 'inventory' => $high->ulid, 'stored' => 7, 'counted' => 1]);
    });

    it('say nothing and change nothing when they are all right', function (): void {
        $team = inventoryTeam();
        itemIn(inventoryIn($team['workspace'], 'One'));
        inventoryIn($team['workspace'], 'None');
        Log::spy();

        expect(app(DerivedData::class)->recountItems())->toBe(0);

        Log::shouldNotHaveReceived('warning');
    });

    it('are counted under the lock of the inventory, in a transaction of its own', function (): void {
        $team = inventoryTeam();
        itemIn(inventoryIn($team['workspace'], 'One'));
        inventoryIn($team['workspace'], 'Two');
        $order = [];
        DB::listen(function ($query) use (&$order): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'for update') && preg_match('/from [`"]inventories[`"]/', $sql) === 1) {
                $order[] = 'lock@'.DB::transactionLevel();
            } elseif (preg_match('/count\(\*\).*from [`"]items[`"]/', $sql) === 1) {
                $order[] = 'count@'.DB::transactionLevel();
            }
        });

        app(DerivedData::class)->recountItems();

        // The lock comes first, then the count that is judged under it, each inventory in its own (nested) transaction.
        expect($order)->toBe(['lock@2', 'count@2', 'lock@2', 'count@2']);
    });
});
