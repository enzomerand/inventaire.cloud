<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\Exceptions\MembershipRefused;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The people of a workspace, read under the row locks of {@see Locks}, for whatever decides who belongs to it.
 *
 * Inviting, accepting, changing a role, removing somebody, leaving and handing the ownership over all judge the same things: who the
 * members are, what the person who asks is, how many places are taken. Those verdicts are only true if nobody changes them meanwhile,
 * so they are all given by one sequence of locks, in the order of the class {@see Locks}: the accounts concerned (lowest id first),
 * then the workspace, then its memberships. A roster is made inside the transaction of the caller, as the first thing it does; anything
 * it reads with a plain query comes after it (the first plain read of a transaction opens the snapshot it reads from, on MySQL and
 * MariaDB, and it must not predate the locks).
 */
final class Roster
{
    /**
     * @param  Workspace  $workspace  The workspace, locked, as it is now.
     * @param  Collection<int, User>  $accounts  The accounts that were asked for and still exist, locked, by id.
     * @param  Collection<int, WorkspaceMember>  $members  Every membership of the workspace, locked, oldest first.
     */
    private function __construct(
        public readonly Workspace $workspace,
        public readonly Collection $accounts,
        public readonly Collection $members,
    ) {}

    /**
     * Locks the accounts, then the workspace, then its memberships.
     *
     * @param  iterable<int|string>  $accountIds  The accounts the caller will change or judge, the one who acts included.
     *
     * @throws ModelNotFoundException When the workspace was deleted in the meantime.
     */
    public static function lock(Locks $locks, Workspace $workspace, iterable $accountIds): self
    {
        $accounts = $locks->users($accountIds);
        $locked = $locks->workspace($workspace->getKey());

        return new self($locked, $accounts, $locks->memberships('workspace_id', [$locked->getKey()]));
    }

    /**
     * The membership of an account, or null when it does not belong to the workspace.
     */
    public function membershipOf(User $user): ?WorkspaceMember
    {
        return $this->members->first(fn (WorkspaceMember $member): bool => (int) $member->user_id === (int) $user->getKey());
    }

    /**
     * The locked account with this id, when it was asked for and exists.
     */
    public function account(int $id): ?User
    {
        return $this->accounts->get($id);
    }

    /**
     * The membership of the person who acts, judged again now that everything is locked: they must still exist, still belong to the
     * workspace and be its owner.
     *
     * @throws ModelNotFoundException When the account was deleted in the meantime.
     * @throws AuthorizationException With status 404 when they do not belong to the workspace (it is answered as if it did not exist).
     * @throws MembershipRefused With {@see MembershipRefused::NOT_OWNER} when they belong to it without being its owner.
     */
    public function owner(User $actor): WorkspaceMember
    {
        $member = $this->member($actor);

        if (! $member->role->canManage()) {
            throw new MembershipRefused(MembershipRefused::NOT_OWNER);
        }

        return $member;
    }

    /**
     * The membership of the person who acts, whatever their role.
     *
     * @throws ModelNotFoundException When the account was deleted in the meantime.
     * @throws AuthorizationException With status 404 when they do not belong to the workspace.
     */
    public function member(User $actor): WorkspaceMember
    {
        if ($this->account((int) $actor->getKey()) === null) {
            throw (new ModelNotFoundException)->setModel(User::class, [$actor->getKey()]);
        }

        return $this->membershipOf($actor) ?? throw (new AuthorizationException)->withStatus(404);
    }

    /**
     * The membership of another account that the person who acts is changing: it must belong to the workspace.
     *
     * @throws ModelNotFoundException When the account does not belong to the workspace (or no longer exists).
     */
    public function target(User $target): WorkspaceMember
    {
        return $this->membershipOf($target) ?? throw (new ModelNotFoundException)->setModel(User::class, [$target->getKey()]);
    }
}
