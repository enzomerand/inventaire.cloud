<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Database\DeadlockRetries;
use App\Models\LoginCode;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Collection;

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
 * 4. the memberships (`workspace_user`), by id, read with a locking read ({@see self::memberships()}).
 *
 * Signing in takes 1 then 2 (and 4, to read the memberships of an account that has none yet, before it is given its
 * personal workspace); deleting an account takes 1 to 4; so does an administrator deleting one, who first hands over
 * the workspaces it alone owns (and who, like the administrators changing who is one, locks all the administrators
 * with the account: see {@see self::administratorsAnd()}). A transaction may skip levels: creating an account takes
 * none of 2 to 4 for its personal workspace, since the account and the workspace are invisible to everybody until the
 * commit and the workspace is inserted without reading any membership (see {@see Workspaces::createPersonal()}).
 * Code that adds members (the invitations of lot 9) must follow the same order, accounts then the workspace then the
 * memberships: it is what lets the exceptions below stay safety nets.
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
 * Only these three can end up waiting for each other, in a race that narrow. The database then aborts one of the
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
     * by primary key takes record locks only, on rows that exist (an id that is gone costs a gap lock, but between ids
     * that were taken, not at the end of the table where accounts are inserted).
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
