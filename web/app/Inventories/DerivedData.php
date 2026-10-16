<?php

declare(strict_types=1);

namespace App\Inventories;

use App\Console\Commands\UpgradeCommand;
use App\Database\DeadlockRetries;
use App\Models\Inventory;
use App\Settings\InstanceSettings;
use App\Workspaces\Locks;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use JsonException;

/**
 * Brings right the data that is derived from other data, which `inventaire:upgrade` ({@see UpgradeCommand}) does after
 * the migrations: the text the items are searched by and the counters of items.
 *
 * Both are written with the data they come from, in the same transaction, so they are right as long as the code that wrote
 * them was. They are not after an update that changes how they are made (the form of the search text, {@see SearchText::VERSION}),
 * after an item written before a column existed, or after a counter that went wrong for a reason nobody foresaw. A migration
 * must not call the application's code (it is the code of the day it was written, and runs against the schema of that day),
 * so this is done here, with the code and the schema of the version that is being installed.
 *
 * Each inventory is one transaction under its own row lock ({@see Locks::inventories()}): the writes to that inventory wait
 * for it, the others go on, and the lock is not held for longer than one inventory takes. Both steps can be run as often as
 * needed.
 */
final class DerivedData
{
    /** Inventories read at a time when the list of them is walked. */
    private const CHUNK = 100;

    /**
     * Gets what the derived data are made from and written with.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly ItemSearchText $searchText,
        private readonly InstanceSettings $settings,
    ) {}

    /**
     * Builds the search text of the items again when the version of its form ({@see SearchText::VERSION}) is not the one
     * the texts were built with (or none was ever recorded), then records the version; otherwise builds it only for the
     * items that have none, which is every item written before the column existed.
     *
     * The version is recorded when every inventory was done, not before: a run that fails is run again in full.
     *
     * @return bool Whether every text was built again (the version changed), and not only the missing ones.
     *
     * @throws JsonException When the values of an item are not JSON.
     */
    public function rebuildSearchText(): bool
    {
        $current = $this->settings->searchTextVersion() === SearchText::VERSION;

        foreach ($this->inventoryIds() as $id) {
            $this->inTransaction($id, function (Inventory $inventory) use ($current): null {
                $this->searchText->refresh($inventory, $inventory->fields()->get(), onlyMissing: $current);

                return null;
            });
        }

        if (! $current) {
            $this->settings->recordSearchTextVersion(SearchText::VERSION);
        }

        return ! $current;
    }

    /**
     * Counts the items of every inventory and corrects the counter of those where it differs, saying so in the log (a
     * counter is only moved with the items, so a difference is a fault to know about, not a routine).
     *
     * Only the rows that differ are written, with the query builder: the inventory did not change, its counter did.
     *
     * @return int The number of inventories whose counter was wrong.
     */
    public function recountItems(): int
    {
        $corrected = 0;

        foreach ($this->inventoryIds() as $id) {
            // Logged here, not in the transaction: a transaction that is run again after a deadlock must not say it twice.
            $drift = $this->inTransaction($id, function (Inventory $inventory): ?array {
                $counted = $this->db->table('items')->where('inventory_id', $inventory->getKey())->count();

                if ($inventory->items_count === $counted) {
                    return null;
                }

                $this->db->table('inventories')->where('id', $inventory->getKey())->update(['items_count' => $counted]);

                return ['inventory_id' => $inventory->getKey(), 'inventory' => $inventory->ulid, 'stored' => $inventory->items_count, 'counted' => $counted];
            });

            if ($drift !== null) {
                $corrected++;

                Log::warning('The item counter of an inventory was wrong: it was recounted.', $drift);
            }
        }

        return $corrected;
    }

    /**
     * The ids of the inventories that exist, lowest first, read a chunk at a time.
     *
     * @return LazyCollection<int, int>
     */
    private function inventoryIds(): LazyCollection
    {
        return $this->db->table('inventories')->select('id')->lazyById(self::CHUNK)->map(fn (object $row): int => (int) $row->id);
    }

    /**
     * Runs the work on an inventory in a transaction of its own, with the inventory locked and the work reading it as it is
     * under the lock. Run again when the database picks it as the loser of a deadlock.
     *
     * @template TResult
     *
     * @param  Closure(Inventory): TResult  $work
     * @return TResult|null What the work returns, or null when the inventory was deleted in the meantime.
     */
    private function inTransaction(int $id, Closure $work): mixed
    {
        return DeadlockRetries::run(fn (): mixed => $this->db->transaction(function () use ($id, $work): mixed {
            $inventory = $this->locks->inventories([$id])->first();

            return $inventory === null ? null : $work($inventory);
        }));
    }
}
