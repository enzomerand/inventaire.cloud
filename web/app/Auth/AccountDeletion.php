<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Exceptions\AccountDeletionRefused;
use App\Models\LoginCode;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\WorkspaceRole;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

/**
 * Deleting an account, and everything that belongs to it alone.
 *
 * What goes with the account:
 *
 * - the workspaces nobody else belongs to, with everything in them (inventories, from lot 4 on);
 * - its place in the workspaces shared with others: it just leaves them;
 * - its sessions (the rows of the `sessions` table, when sessions are kept in the database), the sign-in code that
 *   may be waiting for its address, its deletion code and its API tokens.
 *
 * What stops it, so that nothing is left without anyone in charge (the person must act first):
 *
 * - being the only owner of a workspace that other people belong to (ownership has to be handed over);
 * - being the last administrator of the instance.
 *
 * Confirming the deletion with a code is the caller's job ({@see AccountDeletionCodes}).
 */
final class AccountDeletion
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Tells what would stop the account from being deleted, without changing anything.
     *
     * @throws AccountDeletionRefused When something does.
     */
    public function ensureDeletable(User $user): void
    {
        $this->check($user, lock: false);
    }

    /**
     * Deletes the account and what goes with it, in one transaction: if anything fails, nothing is deleted.
     *
     * @throws AccountDeletionRefused When the account cannot be deleted yet; nothing was changed.
     */
    public function delete(User $user): void
    {
        $this->db->transaction(function () use ($user): void {
            // Taken before anything is read, so that two requests (the same person twice, or two administrators
            // deleting themselves at the same moment) judge the state one after the other.
            if (User::query()->whereKey($user->getKey())->lockForUpdate()->first() === null) {
                return;
            }

            $alone = $this->check($user, lock: true);

            Workspace::query()->whereIn('id', $alone)->delete();

            LoginCode::query()->where('email', $user->email)->delete();
            $user->tokens()->delete();
            $this->forgetSessions($user);

            $user->delete();
        });
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
     * Applies the rules to the account.
     *
     * @param  bool  $lock  Whether to lock the rows the verdict depends on (inside a transaction that deletes).
     * @return list<int> The keys of the workspaces that belong to the account alone, which go with it.
     *
     * @throws AccountDeletionRefused
     */
    private function check(User $user, bool $lock): array
    {
        if ($user->is_admin) {
            $administrators = User::query()->where('is_admin', true)->orderBy('id');

            if ($lock) {
                $administrators->lockForUpdate();
            }

            if ($administrators->pluck('id')->reject(fn ($id): bool => (int) $id === (int) $user->getKey())->isEmpty()) {
                throw new AccountDeletionRefused(AccountDeletionRefused::LAST_ADMINISTRATOR);
            }
        }

        $mine = WorkspaceMember::query()->where('user_id', $user->getKey())->get();
        $others = WorkspaceMember::query()
            ->whereIn('workspace_id', $mine->pluck('workspace_id'))
            ->where('user_id', '!=', $user->getKey())
            ->get()
            ->groupBy('workspace_id');

        $alone = [];
        $stuck = [];

        foreach ($mine as $membership) {
            $companions = $others->get($membership->workspace_id);

            if ($companions === null) {
                $alone[] = $membership->workspace_id;
            } elseif ($membership->role === WorkspaceRole::Owner && $companions->doesntContain(fn (WorkspaceMember $other): bool => $other->role === WorkspaceRole::Owner)) {
                $stuck[] = $membership->workspace_id;
            }
        }

        if ($stuck !== []) {
            throw new AccountDeletionRefused(
                AccountDeletionRefused::SOLE_OWNER_OF_SHARED_WORKSPACES,
                array_values(Workspace::query()->whereIn('id', $stuck)->orderBy('id')->get()->all()),
            );
        }

        return $alone;
    }
}
