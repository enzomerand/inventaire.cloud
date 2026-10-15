<?php

declare(strict_types=1);

namespace App\Inventories;

use App\Database\DeadlockRetries;
use App\Inventories\Exceptions\ItemLimitReachedException;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\User;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Creating, changing and deleting the items of an inventory.
 *
 * Every write that depends on the fields starts by locking the inventory, then reads the fields while it holds that lock
 * (a plain read: the fields have no locks of their own, see {@see Locks}), so that:
 *
 * - the quota (`inventories.max_items`) is counted with nobody else adding an item: two requests for the last place
 *   cannot both get it;
 * - the values are validated against the fields as they are *now*, and rebuilt from them: a field deleted at the same
 *   moment either took the value with it before this runs, or is not deleted until this has finished, and no value
 *   is left behind that points to a field that is gone;
 * - an update merges into what the item holds now (the copy the request read may be older), one update after the other.
 *
 * The account of the person who writes is locked first (see {@see self::lockAuthor()}). Each write is one transaction,
 * retried when the database picks it as the loser of a deadlock.
 */
final class Items
{
    /** Items per page of the list. */
    public const PER_PAGE = 50;

    /**
     * Gets what the rules of items need: the database, the locks and the checks of values.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly ItemValues $values,
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
            $this->lockAuthor($author);
            $locked = $this->locks->inventory($inventory->getKey());
            $fields = $locked->fields()->get();

            $limit = config()->integer('inventories.max_items');

            if (Item::query()->where('inventory_id', $locked->getKey())->count() >= $limit) {
                throw new ItemLimitReachedException($limit);
            }

            $item = new Item;
            $item->inventory_id = $locked->getKey();
            $item->values = $this->values->apply($fields, $values, [], true);
            $item->created_by = $author->getKey();
            $item->updated_by = $author->getKey();
            $item->save();

            return $item;
        }));
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
            $this->lockAuthor($editor);
            $locked = $this->locks->inventory($inventory->getKey());
            $current = $this->lockedItem($locked, $item);
            // Read last: the first plain read of the transaction opens its snapshot, and every lock is taken by now.
            $fields = $locked->fields()->get();

            $merged = $this->values->apply($fields, $values, $current->values, false);

            if (! $this->same($merged, $current->values)) {
                $current->values = $merged;
                $current->updated_by = $editor->getKey();
                $current->save();
            }

            return $current;
        }));
    }

    /**
     * Deletes an item.
     *
     * @throws ModelNotFoundException When the inventory or the item was deleted in the meantime.
     */
    public function delete(Inventory $inventory, Item $item): void
    {
        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($inventory, $item): void {
            $locked = $this->locks->inventory($inventory->getKey());

            $this->lockedItem($locked, $item)->delete();
        }));
    }

    /**
     * Locks the account of the person who writes, before anything else.
     *
     * The item records who wrote it (`created_by`, `updated_by`: foreign keys to the accounts), and the database takes a
     * shared lock on that account's row when the item is saved. Taken after the inventory's, it would go back up the
     * order of {@see Locks} (accounts come first): deleting that account holds its row and then needs the inventory to
     * delete what is in it, while this holds the inventory and waits for the row. Taking the row first keeps the order,
     * and an account deleted meanwhile is found out here, not by a failing foreign key.
     *
     * @throws ModelNotFoundException When the account was deleted in the meantime.
     */
    private function lockAuthor(User $author): void
    {
        if ($this->locks->users([$author->getKey()])->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(User::class, [$author->getKey()]);
        }
    }

    /**
     * The item as it is now, locked, found in the inventory it must belong to.
     *
     * @throws ModelNotFoundException When it was deleted meanwhile.
     */
    private function lockedItem(Inventory $inventory, Item $item): Item
    {
        $locked = $this->locks->items([$item->getKey()])->first();

        if ($locked === null || (int) $locked->inventory_id !== (int) $inventory->getKey()) {
            throw (new ModelNotFoundException)->setModel(Item::class, [$item->getKey()]);
        }

        return $locked;
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
