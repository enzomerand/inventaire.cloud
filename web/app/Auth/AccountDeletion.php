<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Exceptions\AccountDeletionRefused;
use App\Models\LoginCode;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\Locks;
use App\Workspaces\WorkspaceRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Deleting an account, and everything that belongs to it alone.
 *
 * There is one way to delete an account, whoever asks (the person from their account page, an administrator
 * later, a script in `tinker`): `User::delete()`. It runs in a transaction and calls {@see self::release()} from
 * the model's `deleting` event, so that nothing can be forgotten by going around this class. (Deleting rows with a
 * query, `User::query()->delete()`, raises no model event and is not a way to delete an account.)
 *
 * What goes with the account:
 *
 * - the workspaces nobody else belongs to, with everything in them (inventories, from lot 4 on): each one is
 *   deleted through its own `delete()`, so that its model events fire and what is stored for it can be purged;
 * - its place in the workspaces shared with others: it just leaves them;
 * - its sessions (the rows of the `sessions` table, when sessions are kept in the database), the sign-in code that
 *   may be waiting for its address, its deletion code and its API tokens.
 *
 * The invitations to join a workspace are not the account's: the ones it sent stay (the database forgets who sent them, and a workspace
 * they were for goes on as it was) and the ones addressed to its address stay too, until they lapse (and are pruned): they are the data of
 * whoever sent them, who chose the address, and a new account made with that address can accept them. Those of a workspace that goes
 * with the account go with it.
 *
 * What stops it, so that nothing is left without anyone in charge (the person must act first; the deletion is
 * refused with an {@see AccountDeletionRefused} and nothing is changed):
 *
 * - being the only owner of a workspace that other people belong to (ownership has to be handed over);
 * - being the last administrator of the instance.
 *
 * The verdict is given under row locks ({@see Locks}), in the global order described there: the sign-in code of the
 * account's address first (signing in locks it before the account, so deleting must too, or the two would wait for
 * each other), then the administrators and the account, lowest id first, then the workspaces it belongs to, so that
 * two accounts deleted at the same moment (two administrators, two co-owners of a workspace) are judged one after the
 * other and neither leaves the instance without an administrator, nor a workspace without a member.
 *
 * Confirming the deletion with a code is the caller's job ({@see AccountDeletionCodes}).
 */
final class AccountDeletion
{
    public function __construct(private readonly Locks $locks, private readonly ApiTokens $tokens) {}

    /**
     * Tells what would stop the account from being deleted, without changing anything.
     *
     * @throws AccountDeletionRefused When something does.
     */
    public function ensureDeletable(User $user): void
    {
        if ($user->is_admin && ! User::query()->where('is_admin', true)->whereKeyNot($user->getKey())->exists()) {
            throw new AccountDeletionRefused(AccountDeletionRefused::LAST_ADMINISTRATOR);
        }

        $this->soleWorkspaces($user, lock: false);
    }

    /**
     * Removes what goes with the account, just before its row is deleted. Called by the `deleting` event of
     * {@see User}, inside the transaction of {@see User::delete()}; use that, not this.
     *
     * @throws AccountDeletionRefused When the account cannot be deleted yet.
     * @throws ModelNotFoundException When the account is already gone: a deletion that deletes nothing must not pass for one.
     */
    public function release(User $user): void
    {
        // The sign-in code of the address first, before any account: signing in locks them in that order, and the
        // reverse order here would make a deletion and a sign-in of the same address wait for each other. The address
        // is the one stored, not the one this copy of the account remembers (a stale or hand-made copy may spell it
        // differently); no account is locked yet, and none can change its address (nothing in the application edits
        // one), so it is the address of the account locked below.
        $email = User::query()->whereKey($user->getKey())->value('email');

        if (! is_string($email)) {
            throw (new ModelNotFoundException)->setModel(User::class, [$user->getKey()]);
        }

        $this->locks->loginCode($email);

        // The administrators and the account in one go, lowest id first, by primary key (see Locks::administratorsAnd()
        // for why not with a filter on is_admin): two administrators deleting themselves at the same moment would
        // otherwise each hold their own row and wait for the other's. When an administrator deletes the account, most
        // of these rows are held already and cost nothing (the exception is explained at
        // UserAdministration::handOverWorkspaces()).
        $accounts = $this->locks->administratorsAnd([$user->getKey()]);
        $locked = $accounts->get((int) $user->getKey());

        if ($locked === null) {
            // Deleted by somebody else while this waited (or before it began, for a stale copy of the account).
            throw (new ModelNotFoundException)->setModel(User::class, [$user->getKey()]);
        }

        // Another administrator among the locked rows is one for the whole transaction (see Locks::administratorsAnd()).
        if ($locked->is_admin && $accounts->doesntContain(fn (User $account): bool => $account->is_admin && ! $account->is($locked))) {
            throw new AccountDeletionRefused(AccountDeletionRefused::LAST_ADMINISTRATOR);
        }

        foreach ($this->soleWorkspaces($locked, lock: true) as $workspace) {
            $workspace->delete();
        }

        LoginCode::query()->where('email', $locked->email)->delete();
        $this->tokens->revokeAll($locked);
        $this->forgetSessions($locked);
    }

    /**
     * Deletes the sessions the account has in the database (nothing to delete when sessions are kept elsewhere).
     */
    private function forgetSessions(User $user): void
    {
        $connection = config('session.connection');

        DB::connection(is_string($connection) ? $connection : null)
            ->table(config()->string('session.table'))
            ->where('user_id', $user->getKey())
            ->delete();
    }

    /**
     * Applies the rules about workspaces to the account.
     *
     * @param  bool  $lock  Whether to lock the workspaces the verdict depends on, and read their members with locking
     *                      reads (inside the transaction that deletes). Otherwise it just looks.
     * @return Collection<int, Workspace> The workspaces that belong to the account alone, which go with it, lowest id first.
     *
     * @throws AccountDeletionRefused When the account is the only owner of workspaces that others belong to.
     */
    private function soleWorkspaces(User $user, bool $lock): Collection
    {
        // Which workspaces to lock is read first, without a lock: the account's row is locked, so nobody else can
        // remove a membership of it, and the locking read below catches whatever was added in the meantime (locking
        // those workspaces then comes after the memberships: one of the two exceptions to the order of Locks).
        $ids = WorkspaceMember::query()->where('user_id', $user->getKey())->pluck('workspace_id');

        if ($lock) {
            $workspaces = $this->locks->workspaces($ids);
            $missed = $this->locks->memberships('user_id', [$user->getKey()])->pluck('workspace_id')->diff($workspaces->keys());
            $workspaces = $workspaces->union($this->locks->workspaces($missed))->sortKeys();
            $members = $this->locks->memberships('workspace_id', $workspaces->keys());
        } else {
            $workspaces = Workspace::query()->whereIn('id', $ids)->orderBy('id')->get()->keyBy('id');
            $members = WorkspaceMember::query()->whereIn('workspace_id', $ids)->get();
        }

        $byWorkspace = $members->groupBy('workspace_id');
        $alone = new Collection;
        $stuck = [];

        foreach ($workspaces as $id => $workspace) {
            $present = $byWorkspace->get($id, new Collection);
            $mine = $present->first(fn (WorkspaceMember $member): bool => (int) $member->user_id === (int) $user->getKey());

            if ($mine === null) {
                continue;
            }

            $companions = $present->reject(fn (WorkspaceMember $member): bool => (int) $member->user_id === (int) $user->getKey());

            if ($companions->isEmpty()) {
                $alone->push($workspace);
            } elseif ($mine->role === WorkspaceRole::Owner && $companions->doesntContain(fn (WorkspaceMember $other): bool => $other->role === WorkspaceRole::Owner)) {
                $stuck[] = $workspace;
            }
        }

        if ($stuck !== []) {
            throw new AccountDeletionRefused(AccountDeletionRefused::SOLE_OWNER_OF_SHARED_WORKSPACES, $stuck);
        }

        return $alone;
    }
}
