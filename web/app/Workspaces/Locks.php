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
 * more than one of these locks takes them in the same order:
 *
 * 1. the sign-in code of an address (`login_codes`, by address: {@see self::loginCode()});
 * 2. the accounts (`users`), lowest id first ({@see self::users()});
 * 3. the workspaces (`workspaces`), lowest id first ({@see self::workspaces()});
 * 4. the memberships (`workspace_user`), by id, read with a locking read ({@see self::memberships()}).
 *
 * Signing in takes 1 then 2 (and 4, to read the memberships of an account that has none yet, before it is given its
 * personal workspace); deleting an account takes 1 to 4. A transaction may skip levels, never go back up: after a
 * workspace or a membership, it does not lock an account or a sign-in code. Code that adds members (the invitations
 * of lot 9) must follow the same order: accounts, then the workspace, then the memberships.
 *
 * What still gets caught, the database aborts one of the requests and the caller retries (`transaction($callback, 3)`,
 * or {@see DeadlockRetries} when many requests may collide, as sign-ups do), always the outermost transaction: a
 * deadlock rolls the whole of it back, so a nested transaction cannot retry by itself.
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
