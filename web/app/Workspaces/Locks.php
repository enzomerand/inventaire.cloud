<?php

declare(strict_types=1);

namespace App\Workspaces;

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
 * workspace). So every code path that removes memberships or workspaces follows this protocol, inside one
 * transaction:
 *
 * 1. lock the accounts it touches, lowest id first ({@see self::users()});
 * 2. then the workspaces it touches, lowest id first ({@see self::workspaces()});
 * 3. then read the memberships it judges with a locking read ({@see self::memberships()}), and decide.
 *
 * A membership can therefore only disappear while both its account and its workspace are locked by whoever removes
 * it: reading under those locks gives a verdict that stays true until the commit. A single global order (accounts,
 * then workspaces, each by id) is what keeps two such requests from waiting for each other; what still gets caught,
 * the database aborts one of them and the caller retries (`transaction($callback, 3)`). Code that adds members
 * (invitations, in a later lot) locks the workspace first.
 *
 * Locking reads are also what makes the verdict right on MySQL and MariaDB, where a plain read inside a
 * transaction answers from the snapshot taken at its first read, which may predate a commit the lock waited for.
 */
final class Locks
{
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
