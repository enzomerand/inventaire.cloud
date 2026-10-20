<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Database\DeadlockRetries;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\LoginCode;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The row locks that keep memberships coherent when several requests change them at the same moment.
 *
 * Two rules must hold at all times: every workspace has a member, and every account belongs to a workspace. Both
 * are judged by reading memberships, and two requests that each judge "somebody else is left" and then both remove
 * their own side break them (two co-owners deleting their accounts, two members each deleting a different shared
 * workspace). So whatever removes memberships or workspaces does it inside one transaction, and judges under the
 * locks below: a membership can then only disappear while both its account and its workspace are locked by whoever
 * removes it, and a verdict read under those locks stays true until the commit. Locking reads are also what makes the
 * verdict right on MySQL and MariaDB, where a plain read inside a transaction answers from the snapshot taken at its
 * first read, which may predate a commit the lock waited for.
 *
 * The global lock order
 * ---------------------
 *
 * What keeps two requests from waiting for each other is that *every* transaction of the application that takes
 * more than one of these locks takes them in the same order (but for the two paths named below):
 *
 * 1. the sign-in code of an address (`login_codes`, by address: {@see self::loginCode()});
 * 2. the accounts (`users`), lowest id first ({@see self::users()}, or {@see self::administratorsAnd()} when the verdict
 *    depends on the administrators too);
 * 3. the workspaces (`workspaces`), lowest id first ({@see self::workspaces()});
 * 4. the memberships (`workspace_user`), by id, read with a locking read ({@see self::memberships()});
 * 5. the inventories (`inventories`), lowest id first ({@see self::inventories()});
 * 6. the items (`items`), lowest id first ({@see self::items()}).
 *
 * Signing in takes 1 then 2 (and 4, to read the memberships of an account that has none yet, before it is given its
 * personal workspace); deleting an account takes 1 to 4; so does an administrator deleting one, who first hands over
 * the workspaces it alone owns (and who, like the administrators changing who is one, locks all the administrators
 * with the account: see {@see self::administratorsAnd()}). A transaction may skip levels: creating an account takes
 * none of 2 to 4 for its personal workspace, since the account and the workspace are invisible to everybody until the
 * commit and the workspace is inserted without reading any membership (see {@see Workspaces::createPersonal()}).
 * Code that adds members follows the same order, accounts then the workspace then the memberships: it is what lets the
 * exceptions below stay safety nets. That is the case of everything that changes who belongs to a workspace (inviting,
 * accepting, changing a role, removing, leaving, handing the ownership over: see {@see Roster}, which takes the locks in that
 * order). One consequence: once the memberships *of the workspace* are locked, no transaction locks the memberships *of an account*
 * (`memberships('user_id', ...)`) any more: level 4 would be taken a second time, on rows that sort differently from the first, and a
 * request that locks them the other way round (the account's memberships, then the workspace's) could wait for it. What those transactions need to know about
 * the account's memberships (does it belong to another workspace? how many?) is a plain count under the lock of the account (2), which
 * is enough: everything that adds or removes a membership of an account locks that account first. Accepting an invitation and leaving or
 * being removed are the cases ({@see Invitations::accept()}, {@see Members::remove()}). The invitations (`workspace_invitations`) come last among these: every writer of the invitations of a workspace holds the
 * workspace's lock, which is what keeps them one after the other, so they are read and written with plain queries and never
 * with a locking read (on MySQL and MariaDB it would lock the gap after the last invitation of the workspace, where the first
 * invitation of the next workspace is inserted). Declining an invitation deletes the one row by its key, without the lock.
 *
 * Levels 5 and 6 are the content of a workspace. Whatever writes to an inventory (its name, its fields, its items)
 * locks the inventory first, so the writes of one inventory happen one after the other: that is what makes a quota
 * count right, a field name unique and a field deletion leave no orphan value in an item written at the same moment.
 * The fields of an inventory are **not** locked row by row: the lock of the inventory already keeps every other writer
 * out, and a locking read on `inventory_fields (inventory_id, position)` would lock more than the rows it finds. On
 * MySQL and MariaDB, at their default isolation level, it also locks the *gap* after the last row it passes, and the
 * gap after the last field of an inventory is where the first field of the next inventory (another workspace's) is
 * inserted: two writers of two unrelated inventories would wait for each other, and could deadlock. A writer reads
 * the fields with a plain read, after the locks (the first plain read of the transaction opens the snapshot it reads
 * from, so it must come after them: see {@see self::inventories()}). The same goes for counting the items of an
 * inventory against its quota.
 *
 * Creating an inventory locks its workspace (3) to count the workspace's inventories, and nothing below. An item
 * write locks the account of the person who writes it (2: the item's author columns are foreign keys, which lock that
 * row when the item is saved; see {@see self::author()}), then the inventory, then, for an update, which merges into
 * what is stored, the item.
 *
 * The files of the items count against the storage of their workspace, a counter kept on the workspace's row
 * (`storage_used`). Whatever adds or removes a file, or removes what holds files (an item, a file or photo field, an
 * inventory), therefore locks the workspace (3) *before* the inventory (5) and the item (6), after the account (2): it
 * is the same order, with one more level taken, and it makes the check of the quota and the counter one decision under
 * one lock. Writes that cannot touch a file (a text field, an item with nothing attached) do not take the workspace. Deleting a workspace or an account reaches the inventories, fields and items through the foreign keys'
 * cascade, after its own locks, which is the order above; it removes everything the workspace holds in the one
 * transaction (an inventory at its quota of items makes it a long one: accepted, since deleting a workspace is rare and
 * the writers it waits for are waiting for the same inventory rows).
 *
 * The official versions follow the same order. Freezing an inventory takes the author's account (2), then the inventory (5), and
 * inserts the row under them (nothing counts against the storage yet). Writing the PDF and storing the signed PDF take the workspace
 * (3), then update the version row, which moves `counted_bytes` and the storage counter in one decision. Deleting a version takes the
 * workspace (3), then the inventory (5).
 *
 * Three paths do go back up the order, each to catch a row that appeared between the plain read that chose what to lock
 * and the locks themselves:
 *
 * - `Workspaces::delete()` locks, after the workspace and its memberships, the accounts of the members who joined that
 *   workspace meanwhile (a request that committed in that gap);
 * - `AccountDeletion` locks, after the memberships of the account, the workspaces the account joined meanwhile (the
 *   account is locked by then, and adding it to a workspace locks it first, so this is for a membership added without
 *   that lock);
 * - `UserAdministration`, when an administrator deletes an account, locks first the administrators, the account and
 *   the people who can take over the workspaces it alone owns (all in one statement, lowest id first, then the
 *   workspaces and their memberships), and then, once the memberships are read, the accounts of the members who joined
 *   those workspaces meanwhile.
 *
 * Only these three can end up waiting for each other, in a race that narrow: the writers of the content of a workspace
 * wait for one lock at a time that they take in the order above (an account, an inventory, an item), and for nothing
 * else, since fields are not locked and the quota counts take no lock. The database then aborts one of the
 * requests and the caller retries (`transaction($callback, 3)`, or {@see DeadlockRetries} when many requests may
 * collide, as sign-ups do), always the outermost transaction: a deadlock rolls the whole of it back, so a nested
 * transaction cannot retry by itself.
 */
final class Locks
{
    /**
     * Locks the sign-in code waiting for an address, if there is one (and the place where it would be, if not).
     *
     * First in the global lock order (see the class): signing in locks it before the account, so deleting the account
     * must lock it before the account too. Nothing is returned: a caller wants the lock, not the code.
     *
     * @param  string  $email  Address, already normalized.
     */
    public function loginCode(string $email): void
    {
        LoginCode::query()->whereKey($email)->lockForUpdate()->first();
    }

    /**
     * Locks accounts and returns the ones that exist, by id. Ids of accounts that are gone are skipped.
     *
     * @param  iterable<int|string>  $ids
     * @return Collection<int, User>
     */
    public function users(iterable $ids): Collection
    {
        $ids = $this->sorted($ids);

        /** @var Collection<int, User> $users */
        $users = $ids === [] ? new Collection : User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return $users;
    }

    /**
     * Locks every administrator and the given accounts, lowest id first, and returns the ones that exist, by id.
     *
     * For the verdicts that depend on who administers the instance ("an administrator remains"). The administrators are
     * read first, without a lock, and then everything is locked by primary key in one statement. A locking read that
     * filtered on `is_admin` would scan the whole table (the column has no index) and, on MySQL and MariaDB at their
     * default isolation level, lock every row *and every gap* it passes, the one after the last account included: sign-ups
     * and sign-ins would wait for as long as a deletion lasts. An index on the column would not change that, since the
     * lock on its first entry still covers the gap where the accounts that are not administrators are inserted. Locking
     * by primary key takes record locks on the rows that exist, which is all that is needed in the usual case. An id
     * that is gone (an account deleted since the caller read it) costs a gap lock on MySQL and MariaDB, on the gap
     * where it would be; when it is greater than every id left, that is the gap at the end of the table, where
     * accounts are inserted, and sign-ups wait for it until this transaction ends. That needs an account deleted at
     * the very moment, and lasts as long as this one transaction: rare and short, which is why it is accepted rather
     * than checked for (a check that reads the ids first, without a lock, would only narrow the race, not close it).
     * PostgreSQL has no gap locks.
     *
     * What the caller learns is a lower bound: an account is an administrator for the whole transaction when it is one
     * in the returned collection (the row is locked and was read as it is now, not as it was at the first read). One
     * made an administrator between the first read and the locks is not locked, so it is not counted; that can only make
     * a caller refuse something it could have allowed ("this is the last administrator"), never allow what leaves
     * the instance without one.
     *
     * @param  iterable<int|string>  $ids  Accounts to lock besides the administrators.
     * @return Collection<int, User>
     */
    public function administratorsAnd(iterable $ids): Collection
    {
        $administrators = User::query()->where('is_admin', true)->pluck('id')->all();

        return $this->users([...$administrators, ...$ids]);
    }

    /**
     * Locks workspaces and returns the ones that exist, by id. Ids of workspaces that are gone are skipped.
     *
     * @param  iterable<int|string>  $ids
     * @return Collection<int, Workspace>
     */
    public function workspaces(iterable $ids): Collection
    {
        $ids = $this->sorted($ids);

        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = $ids === [] ? new Collection : Workspace::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return $workspaces;
    }

    /**
     * Reads memberships with a locking read: it sees what is committed now, and nobody can change them until the
     * transaction ends. Call it once the accounts and workspaces concerned are locked.
     *
     * Where there are none to read, MySQL and MariaDB (at their default isolation level) lock the gap where they
     * would be, and every insert there waits: two accounts that read nothing then insert at the same moment wait
     * for each other, and one is aborted. So never use it on the memberships of an account that is being created.
     *
     * @param  'user_id'|'workspace_id'  $column  Whether the ids are those of accounts or of workspaces.
     * @param  iterable<int|string>  $ids
     * @return Collection<int, WorkspaceMember>
     */
    public function memberships(string $column, iterable $ids): Collection
    {
        $ids = $this->sorted($ids);

        /** @var Collection<int, WorkspaceMember> $memberships */
        $memberships = $ids === [] ? new Collection : WorkspaceMember::query()->whereIn($column, $ids)->orderBy('id')->lockForUpdate()->get();

        return $memberships;
    }

    /**
     * Locks one workspace and returns it, or fails the way a route binding does when it is gone (a 404): a request that
     * waited for the lock of a workspace that was deleted meanwhile finds nothing to write to.
     *
     * @throws ModelNotFoundException When the workspace no longer exists.
     */
    public function workspace(int|string $id): Workspace
    {
        return $this->workspaces([$id])->first() ?? throw (new ModelNotFoundException)->setModel(Workspace::class, [$id]);
    }

    /**
     * Locks inventories and returns the ones that exist, by id. Ids of inventories that are gone are skipped.
     *
     * Every write to an inventory or to what it holds starts here (see the order in the class), so writes to one
     * inventory never overlap. Lock it before reading anything inside the transaction: on MySQL and MariaDB a plain
     * read opens the snapshot the rest of the transaction reads from, and one taken before the lock was granted would
     * predate what the request that held the lock committed.
     *
     * @param  iterable<int|string>  $ids
     * @return Collection<int, Inventory>
     */
    public function inventories(iterable $ids): Collection
    {
        $ids = $this->sorted($ids);

        /** @var Collection<int, Inventory> $inventories */
        $inventories = $ids === [] ? new Collection : Inventory::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return $inventories;
    }

    /**
     * Locks one inventory and returns it, or fails the way a route binding does when it is gone (a 404): a request
     * that waited for the lock of an inventory that was deleted meanwhile finds nothing to write to.
     *
     * @throws ModelNotFoundException When the inventory no longer exists.
     */
    public function inventory(int|string $id): Inventory
    {
        return $this->inventories([$id])->first() ?? throw (new ModelNotFoundException)->setModel(Inventory::class, [$id]);
    }

    /**
     * Locks items and returns the ones that exist, by id. Ids of items that are gone are skipped.
     *
     * @param  iterable<int|string>  $ids
     * @return Collection<int, Item>
     */
    public function items(iterable $ids): Collection
    {
        $ids = $this->sorted($ids);

        /** @var Collection<int, Item> $items */
        $items = $ids === [] ? new Collection : Item::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return $items;
    }

    /**
     * Locks the account of the person who writes to the content of a workspace, before anything else of that write.
     *
     * An item records who wrote it (`created_by`, `updated_by`: foreign keys to the accounts), and so does a file
     * (`uploaded_by`) and an entry of the history (`user_id`): the database takes a shared lock on that account's row when
     * the row is saved. Taken after the inventory's, it would go back up the order above (accounts come first): deleting
     * that account holds its row and then needs the inventory to delete what is in it, while this holds the inventory
     * and waits for the row. Taking the row first keeps the order, and an account deleted meanwhile is found out here,
     * not by a failing foreign key.
     *
     * @throws ModelNotFoundException When the account was deleted in the meantime.
     */
    public function author(User $author): void
    {
        if ($this->users([$author->getKey()])->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(User::class, [$author->getKey()]);
        }
    }

    /**
     * Locks an item and returns it as it is now, found in the inventory it must belong to (an inventory that is locked
     * already: the item is the last level of the order above).
     *
     * @throws ModelNotFoundException When the item was deleted meanwhile, or is not in that inventory.
     */
    public function itemOf(Inventory $inventory, Item $item): Item
    {
        $locked = $this->items([$item->getKey()])->first();

        if ($locked === null || (int) $locked->inventory_id !== (int) $inventory->getKey()) {
            throw (new ModelNotFoundException)->setModel(Item::class, [$item->getKey()]);
        }

        return $locked;
    }

    /**
     * The ids as sorted, distinct integers.
     *
     * @param  iterable<int|string>  $ids
     * @return list<int>
     */
    private function sorted(iterable $ids): array
    {
        $unique = [];

        foreach ($ids as $id) {
            $unique[(int) $id] = true;
        }

        ksort($unique);

        return array_keys($unique);
    }
}
