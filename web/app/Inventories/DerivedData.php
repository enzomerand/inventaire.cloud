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
use Throwable;

/**
 * Brings right the data that is derived from other data, which `inventaire:upgrade` ({@see UpgradeCommand}) does after
 * the migrations: the text the items are searched by, the counters of items and the storage counters of workspaces.
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
 *
 * A search text that cannot be built for one inventory (a value that is not JSON, a deadlock that went on, a disk that is
 * full) does not stop the others: it is logged and counted, and the next run tries again.
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
     * items that have none (null: never built, see {@see ItemSearchText}), which is every item written before the column
     * existed. An inventory with no item in that case is not even locked.
     *
     * An inventory that fails is logged and counted, and the others go on. The version is recorded when every inventory
     * was done, not before: a run that fails somewhere is run again in full.
     */
    public function rebuildSearchText(): SearchTextRebuild
    {
        $current = $this->settings->searchTextVersion() === SearchText::VERSION;
        $failed = 0;

        foreach ($this->inventoryIds() as $id) {
            try {
                // Locking an inventory to find nothing to do would make the writes to it wait for nothing.
                if ($current && ! $this->searchText->hasMissing($id)) {
                    continue;
                }

                $this->inTransaction($id, function (Inventory $inventory) use ($current): null {
                    $this->searchText->refresh($inventory, $inventory->fields()->get(), onlyMissing: $current);

                    return null;
                });
            } catch (Throwable $exception) {
                $failed++;

                Log::error('The search text of the items of an inventory could not be built: it will be tried again by the next upgrade.', ['inventory_id' => $id, 'exception' => $exception]);
            }
        }

        if (! $current && $failed === 0) {
            $this->settings->recordSearchTextVersion(SearchText::VERSION);
        }

        return new SearchTextRebuild(everything: ! $current, failed: $failed);
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
     * Adds up the sizes of the files of every workspace and corrects the storage counter of those where it differs, saying
     * so in the log (a counter is only moved with the files, so a difference is a fault to know about, not a routine).
     *
     * Each workspace is one transaction under the lock of its row, which every upload and every removal of a file takes
     * before anything else, so that neither the counter nor the files can change meanwhile. Only the rows that differ are
     * written, with the query builder: the workspace did not change, its counter did.
     *
     * @return int The number of workspaces whose counter was wrong.
     */
    public function recountStorage(): int
    {
        $corrected = 0;

        foreach ($this->workspaceIds() as $id) {
            // Logged here, not in the transaction: a transaction that is run again after a deadlock must not say it twice.
            $drift = DeadlockRetries::run(fn (): ?array => $this->db->transaction(function () use ($id): ?array {
                $workspace = $this->locks->workspaces([$id])->first();

                if ($workspace === null) {
                    return null;
                }

                $counted = (int) $this->db->table('attachments')
                    ->join('inventories', 'inventories.id', '=', 'attachments.inventory_id')
                    ->where('inventories.workspace_id', $id)
                    ->sum('attachments.size');

                if ($workspace->storage_used === $counted) {
                    return null;
                }

                $this->db->table('workspaces')->where('id', $id)->update(['storage_used' => $counted]);

                return ['workspace_id' => $id, 'workspace' => $workspace->ulid, 'stored' => $workspace->storage_used, 'counted' => $counted];
            }));

            if ($drift !== null) {
                $corrected++;

                Log::warning('The storage counter of a workspace was wrong: it was added up again.', $drift);
            }
        }

        return $corrected;
    }

    /**
     * The ids of the workspaces that exist, lowest first, read a chunk at a time.
     *
     * @return LazyCollection<int, int>
     */
    private function workspaceIds(): LazyCollection
    {
        return $this->db->table('workspaces')->select('id')->lazyById(self::CHUNK)->map(fn (object $row): int => (int) $row->id);
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
