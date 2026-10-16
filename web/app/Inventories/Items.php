<?php

declare(strict_types=1);

namespace App\Inventories;

use App\Database\DeadlockRetries;
use App\Http\Resources\ItemResource;
use App\Inventories\Attachments\AttachmentCleanup;
use App\Inventories\Exceptions\ItemLimitReachedException;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\User;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Creating, changing and deleting the items of an inventory.
 *
 * Every write that depends on the fields starts by locking the inventory, then reads the fields while it holds that lock
 * (a plain read: the fields have no locks of their own, see {@see Locks}), so that:
 *
 * - the quota (`inventories.max_items`) is judged with nobody else adding an item: two requests for the last place
 *   cannot both get it. The number of items is the counter kept on the inventory row (`items_count`), which the lock
 *   protects and which this class alone moves, in the transaction of the item it adds or deletes;
 * - the values are validated against the fields as they are *now*, and rebuilt from them: a field deleted at the same
 *   moment either took the value with it before this runs, or is not deleted until this has finished, and no value
 *   is left behind that points to a field that is gone;
 * - an update merges into what the item holds now (the copy the request read may be older), one update after the other;
 * - the text the item is searched by ({@see ItemSearchText}) is built from the values and the fields read under that
 *   same lock, and written with them;
 * - the history of the item ({@see ItemRevisions}) gets its line in the same transaction, from the values as they were and
 *   as they become, so that a change that is rolled back leaves no trace and a change that happens always leaves one. The
 *   history and the files of an item are deleted with it by {@see self::delete()}: no foreign key does it (see the
 *   migrations that create their tables).
 *
 * The account of the person who writes is locked first (see {@see Locks::author()}). Each write is one transaction,
 * retried when the database picks it as the loser of a deadlock.
 */
final class Items
{
    /** Items per page of the list. */
    public const PER_PAGE = 50;

    /** Items written per statement when many are added at once. */
    private const BULK_CHUNK = 500;

    /**
     * The columns the list reads: what an item is described with ({@see ItemResource}). Not the text the item is searched
     * by (`search_text`, which can be as long as its values and is never given out), nor its authors.
     *
     * @var list<string>
     */
    public const LIST_COLUMNS = ['items.id', 'items.ulid', 'items.inventory_id', 'items.values', 'items.created_at', 'items.updated_at'];

    /**
     * Gets what the rules of items need: the database, the locks, the checks of values and the search text.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly ItemValues $values,
        private readonly ItemSearchText $searchText,
        private readonly ItemRevisions $revisions,
        private readonly AttachmentCleanup $files,
    ) {}

    /**
     * Adds an item to the inventory.
     *
     * @param  array<array-key, mixed>  $values  As sent, keyed by the ULID of the field.
     * @param  User  $author  The person who adds it.
     *
     * @throws ItemLimitReachedException When the inventory already holds as many items as allowed.
     * @throws ValidationException When a value is refused, a key is not a field, or a required field has no value.
     * @throws ModelNotFoundException When the inventory was deleted in the meantime.
     */
    public function create(Inventory $inventory, array $values, User $author): Item
    {
        return DeadlockRetries::run(fn (): Item => $this->db->transaction(function () use ($inventory, $values, $author): Item {
            $this->locks->author($author);
            $locked = $this->locks->inventory($inventory->getKey());
            $fields = $locked->fields()->get();

            $limit = config()->integer('inventories.max_items');

            if ($locked->items_count >= $limit) {
                throw new ItemLimitReachedException($limit);
            }

            $item = new Item;
            $item->inventory_id = $locked->getKey();
            $stored = $this->values->apply($fields, $values, [], true);
            $item->values = $stored;
            $item->search_text = $this->searchText->build($fields, $stored);
            $item->created_by = $author->getKey();
            $item->updated_by = $author->getKey();
            $item->save();
            $this->moveCount($locked, $inventory, 1);
            $this->revisions->created($item, $author, $stored);

            return $item;
        }));
    }

    /**
     * Adds many items to an inventory the caller holds, inside the caller's transaction: the quota (`inventories.max_items`) judged for
     * all of them at once, then the items, their search text and their first revision written in bulk, a few hundred a statement, and
     * the counter of the inventory moved once. That is what lets an import be one transaction with one lock, where a loop of
     * {@see self::create()} would take the locks again and write four statements an item.
     *
     * The inventory is locked by the caller ({@see Locks::inventory()}, after {@see Locks::author()}) and the fields are those read under
     * that lock, those just added included. The values are **not** checked here: they come out of {@see ItemValues::apply()} already, in
     * canonical form, and the caller vouches that the fields have not changed since. The items are given the ULIDs in the order of the list, so
     * that the order of the file is the order of the numeric keys.
     *
     * @param  Inventory  $locked  The inventory as just read under the lock.
     * @param  Inventory  $given  The copy the caller holds, whose counter is moved too.
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory, read under the lock.
     * @param  list<array<string, mixed>>  $stored  The stored values of each item, keyed by the ULID of the field.
     * @return int How many items were added.
     *
     * @throws ItemLimitReachedException When the inventory would hold more items than allowed.
     */
    public function createMany(Inventory $locked, Inventory $given, Collection $fields, array $stored, User $author): int
    {
        $count = count($stored);

        if ($count === 0) {
            return 0;
        }

        $limit = config()->integer('inventories.max_items');

        if ($locked->items_count + $count > $limit) {
            throw new ItemLimitReachedException($limit);
        }

        $now = now();

        foreach (array_chunk($stored, self::BULK_CHUNK) as $chunk) {
            $rows = [];

            foreach ($chunk as $values) {
                $rows[] = [
                    'ulid' => strtolower((string) Str::ulid()),
                    'inventory_id' => $locked->getKey(),
                    'values' => json_encode($values === [] ? new stdClass : $values, JSON_THROW_ON_ERROR),
                    'search_text' => $this->searchText->build($fields, $values),
                    'created_by' => $author->getKey(),
                    'updated_by' => $author->getKey(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $this->db->table('items')->insert($rows);

            // The numeric keys are not given back by a bulk insert: they are read by the ULIDs that were just made.
            $ids = $this->db->table('items')->whereIn('ulid', array_column($rows, 'ulid'))->pluck('id', 'ulid');
            $items = [];

            foreach ($rows as $position => $row) {
                $items[] = ['id' => (int) $ids[$row['ulid']], 'values' => $chunk[$position]];
            }

            $this->revisions->createdMany((int) $locked->getKey(), $items, $author);
        }

        $this->moveCount($locked, $given, $count);

        return $count;
    }

    /**
     * Changes the values an item holds: only the keys that are sent change, and an empty value clears its field.
     * Nothing is written when nothing changes (the item keeps its `updated_at` and its `updated_by`).
     *
     * @param  array<array-key, mixed>  $values  As sent, keyed by the ULID of the field.
     * @param  User  $editor  The person who changes it.
     *
     * @throws ValidationException When a value is refused, a key is not a field, or a required field is cleared.
     * @throws ModelNotFoundException When the inventory or the item was deleted in the meantime.
     */
    public function update(Inventory $inventory, Item $item, array $values, User $editor): Item
    {
        return DeadlockRetries::run(fn (): Item => $this->db->transaction(function () use ($inventory, $item, $values, $editor): Item {
            $this->locks->author($editor);
            $locked = $this->locks->inventory($inventory->getKey());
            $current = $this->locks->itemOf($locked, $item);
            // Read last: the first plain read of the transaction opens its snapshot, and every lock is taken by now.
            $fields = $locked->fields()->get();

            $merged = $this->values->apply($fields, $values, $current->values, false);

            if (! $this->same($merged, $current->values)) {
                $before = $current->values;
                $current->values = $merged;
                $current->search_text = $this->searchText->build($fields, $merged);
                $current->updated_by = $editor->getKey();
                $current->save();
                $this->revisions->updated($current, $editor, $before, $merged);
            }

            return $current;
        }));
    }

    /**
     * Deletes an item, with its history and its files: the files are counted out of the storage of the workspace in the same
     * transaction (which is why the workspace is locked first: see {@see Locks}), and deleted from the disk once it is
     * committed.
     *
     * @throws ModelNotFoundException When the workspace, the inventory or the item was deleted in the meantime.
     */
    public function delete(Inventory $inventory, Item $item): void
    {
        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($inventory, $item): void {
            $workspace = $this->locks->workspace($inventory->workspace_id);
            $locked = $this->locks->inventory($inventory->getKey());
            $current = $this->locks->itemOf($locked, $item);

            $this->files->releaseItem($workspace, $locked, $current);
            $this->revisions->forget($current);
            $current->delete();
            $this->moveCount($locked, $inventory, -1);
        }));
    }

    /**
     * Moves the counter of items of the inventory by the number of items just added or deleted.
     *
     * The inventory is locked by the caller, so the counter read with it is the one to move. It is written with the query
     * builder, which does not touch `updated_at` (adding an item is not a change of the inventory). Both copies of the
     * inventory in memory, the locked one and the one the caller holds (the request's, which a resource may describe
     * afterwards), get the new value; it is set from the locked row, not added to the caller's copy, which may be older.
     * The counter never goes below zero: the column is unsigned on MySQL and MariaDB, which refuse a negative value with an
     * error (an unsigned underflow) instead of clamping it, so a counter that was already off (it should not be) must not
     * turn a deletion into a failure. It is a fault all the same, so it is written in the log (`inventaire:upgrade` counts
     * the items again and corrects the counters, see {@see DerivedData}).
     *
     * @param  Inventory  $locked  The inventory as just read under the lock.
     * @param  Inventory  $given  The copy the caller holds.
     * @param  int  $by  1 for an item added, -1 for one deleted.
     */
    private function moveCount(Inventory $locked, Inventory $given, int $by): void
    {
        $count = $locked->items_count + $by;

        if ($count < 0) {
            Log::warning('The item counter of an inventory would have gone below zero: it was kept at zero.', ['inventory_id' => $locked->getKey(), 'stored' => $locked->items_count, 'by' => $by]);

            $count = 0;
        }

        $this->db->table('inventories')->where('id', $locked->getKey())->update(['items_count' => $count]);

        foreach ([$locked, $given] as $inventory) {
            $inventory->setAttribute('items_count', $count);
            $inventory->syncOriginalAttribute('items_count');
        }
    }

    /**
     * Whether two sets of values are the same, whatever the order of their keys. Compared strictly: "12.50" is not
     * "12.5" (loose comparison of numeric strings would say it is).
     *
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     */
    private function same(array $first, array $second): bool
    {
        ksort($first);
        ksort($second);

        return $first === $second;
    }
}
