<?php

declare(strict_types=1);

namespace App\Admin;

use App\Admin\Exceptions\UserAdministrationRefused;
use App\Auth\Accounts;
use App\Auth\EmailAddress;
use App\Auth\Exceptions\AccountDeletionRefused;
use App\Database\CaseInsensitiveSearch;
use App\Database\DeadlockRetries;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\LocaleResolver;
use App\Workspaces\Locks;
use App\Workspaces\WorkspaceRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * What the administrators of the instance do with its accounts: find them, create them, make them administrators
 * (or not any more) and delete them.
 *
 * The rules live here, whoever asks (the API today): an instance always keeps an administrator, an administrator does
 * not delete their own account from here (that takes the code mailed to its owner, from the account page), and no
 * workspace is left without an owner by the deletion of an account.
 *
 * Everything that changes existing accounts judges under row locks taken in the global order of {@see Locks}, in one transaction
 * that is retried when the database picks it as the loser of a deadlock ({@see DeadlockRetries}): two administrators
 * demoting each other at the same moment, or deleting each other, are judged one after the other and the last one is
 * refused. The administrator who asks is judged again under those locks: one who was demoted while the request was
 * on its way no longer does what only administrators do ({@see UserAdministrationRefused::NOT_ADMINISTRATOR}); creating
 * an account depends on no other account, so it is judged on a fresh read of the administrator, without a lock.
 *
 * Every change an administrator makes (an account created, made an administrator or not any more, deleted) leaves one
 * line in the log, with the id of the administrator and the id of the account, and nothing else: no address, no name.
 */
final class UserAdministration
{
    /** Accounts per page of the list. */
    public const PER_PAGE = 25;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Accounts $accounts,
        private readonly Locks $locks,
        private readonly LocaleResolver $locales,
    ) {}

    /**
     * A page of accounts, newest first, with the number of workspaces each one belongs to, optionally only those whose
     * name or address contains a word (case ignored).
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function search(string $term): LengthAwarePaginator
    {
        $query = User::query()->withCount('workspaces');

        if (trim($term) !== '') {
            CaseInsensitiveSearch::apply($query, ['name', 'email'], $term);
        }

        return $query->orderByDesc('id')->paginate(self::PER_PAGE);
    }

    /**
     * Creates an account for an address, with its personal workspace (the same way signing up does), in the default
     * language of the instance. The person signs in with a code like anybody else; telling them is up to the caller.
     *
     * @param  User  $actor  The administrator who asks.
     * @param  string  $email  Address, in any case: it is stored in canonical form.
     * @param  string  $name  Already validated and trimmed.
     *
     * @throws UserAdministrationRefused When the actor is no longer an administrator, or when the address already has an account.
     */
    public function create(User $actor, string $email, string $name): User
    {
        // Judged on the account as stored, not on the copy the request carries: an administrator demoted since the
        // request was authorized must not finish as one. A plain read is enough here, nothing is locked: creating an
        // account depends on no other account, so there is no verdict to keep true until the commit.
        if (! User::query()->whereKey($actor->getKey())->where('is_admin', true)->exists()) {
            throw new UserAdministrationRefused(UserAdministrationRefused::NOT_ADMINISTRATOR);
        }

        $email = EmailAddress::normalize($email);

        if (User::query()->where('email', $email)->exists()) {
            throw new UserAdministrationRefused(UserAdministrationRefused::ACCOUNT_EXISTS);
        }

        // The outermost transaction is the one the account opens: a deadlock between accounts that get their first
        // workspace at the same moment is retried from here (see Accounts::administrator()).
        [$user, $created] = DeadlockRetries::run(fn (): array => $this->accounts->register($email, $name, $this->defaultLocale()));

        // Somebody else created it between the check and the insert.
        if (! $created) {
            throw new UserAdministrationRefused(UserAdministrationRefused::ACCOUNT_EXISTS);
        }

        $this->log('account created', $actor, $user);

        return $user;
    }

    /**
     * The language of the accounts an administrator creates and of the mail they receive: the instance's default
     * (`app.default_locale`, not `app.locale`, which is the language of the request by now), or the fallback language
     * when the default is not one the application is translated into.
     */
    public function defaultLocale(): string
    {
        $locale = config()->string('app.default_locale');

        return $this->locales->isSupported($locale) ? $locale : config()->string('app.fallback_locale');
    }

    /**
     * Makes an account an administrator, or takes that away. Doing it again changes nothing.
     *
     * @param  User  $actor  The administrator who asks.
     * @param  User  $user  The account to change.
     * @return User The account as it is now.
     *
     * @throws UserAdministrationRefused When the actor is no longer an administrator, or when it is the last administrator and would stop being one.
     * @throws ModelNotFoundException When the account is gone.
     */
    public function setAdministrator(User $actor, User $user, bool $administrator): User
    {
        // Retried as a whole, like the other changes: a deadlock rolls the transaction back and the accounts are read
        // again for each attempt.
        [$locked, $changed] = DeadlockRetries::run(fn (): array => $this->db->transaction(function () use ($actor, $user, $administrator): array {
            // The administrators, the actor and the account in one go, lowest id first, like AccountDeletion: two
            // administrators demoting each other at the same moment would otherwise each hold their own row and wait
            // for the other's.
            $accounts = $this->locks->administratorsAnd([$actor->getKey(), $user->getKey()]);

            $this->ensureAdministrator($actor, $accounts);

            $locked = $accounts->get((int) $user->getKey());

            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel(User::class, [$user->getKey()]);
            }

            if ($locked->is_admin === $administrator) {
                return [$locked, false];
            }

            if (! $administrator && $accounts->doesntContain(fn (User $account): bool => $account->is_admin && ! $account->is($locked))) {
                throw new UserAdministrationRefused(UserAdministrationRefused::LAST_ADMINISTRATOR);
            }

            $locked->is_admin = $administrator;
            $locked->save();

            return [$locked, true];
        }));

        if ($changed) {
            $this->log($administrator ? 'account made an administrator' : 'administrator rights taken away', $actor, $locked);
        }

        return $locked;
    }

    /**
     * Deletes an account, the way the owner's own deletion does (see {@see User::delete()}: the workspaces nobody else
     * belongs to go with it, it just leaves the others), after making sure no shared workspace is left without an owner.
     *
     * When the account is the only owner of a workspace that other people belong to, one of the other members becomes
     * its owner first: the one who has been in it the longest among its editors, or, when it has none, the one who has
     * been in it the longest, whatever their role (the oldest membership in both cases). All of it is one transaction:
     * if the deletion is refused or fails, nobody's role has changed.
     *
     * @param  User  $actor  The administrator who asks.
     * @param  User  $target  The account to delete.
     *
     * @throws UserAdministrationRefused When the target is the actor, or the actor is no longer an administrator.
     * @throws AccountDeletionRefused When the target is the last administrator.
     * @throws ModelNotFoundException When the account is gone.
     */
    public function delete(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw new UserAdministrationRefused(UserAdministrationRefused::SELF_DELETION);
        }

        $id = $target->getKey();

        // Retried as a whole: a deadlock rolls back the transaction that the hand-over and the deletion share, and
        // the account is read again for each attempt.
        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($actor, $id): void {
            $user = User::query()->whereKey($id)->firstOrFail();

            $this->handOverWorkspaces($actor, $user);
            $user->delete();
        }));

        $this->log('account deleted', $actor, $target);
    }

    /**
     * Gives every workspace the account is the only owner of, and that other people belong to, a new owner: the editor
     * of the longest standing, or the member of the longest standing when it has no editor.
     *
     * Locks are taken in the order of {@see Locks}, and it is the same order as the deletion that follows takes them:
     * the sign-in code of the address, then the administrators, the actor, the account and the people who could take
     * over, in one statement, lowest id first, then the workspaces, then their memberships. Whom to lock is read before
     * any lock; the memberships are read again under the locks, and the accounts of members who joined in the meantime
     * are locked last, once the workspace is (the path that goes back up the order that `Workspaces::delete()` takes
     * too, see Locks).
     *
     * The deletion that follows asks for the same locks again, and a lock the transaction holds already costs nothing.
     * Not all of them are held, though: it reads the administrators again, and an account promoted since the first
     * read is locked only then, after the workspaces and their memberships. That is a step back up the order, so it can
     * deadlock with a request that holds that account and waits for one of the rows held here. The database then
     * aborts one of the two, and {@see self::delete()} runs the whole transaction again ({@see DeadlockRetries}).
     *
     * @param  User  $actor  The administrator who asks, judged again once the accounts are locked.
     *
     * @throws UserAdministrationRefused When the actor is no longer an administrator.
     */
    private function handOverWorkspaces(User $actor, User $user): void
    {
        // The address first, then the accounts (see AccountDeletion::release() for why the sign-in code comes first).
        $this->locks->loginCode($user->email);

        $owned = WorkspaceMember::query()
            ->where('user_id', $user->getKey())
            ->where('role', WorkspaceRole::Owner->value)
            ->pluck('workspace_id');

        $others = $owned->isEmpty()
            ? collect()
            : WorkspaceMember::query()->whereIn('workspace_id', $owned)->where('user_id', '!=', $user->getKey())->pluck('user_id');

        $accounts = $this->locks->administratorsAnd(array_values([$actor->getKey(), $user->getKey(), ...$others->all()]));

        $this->ensureAdministrator($actor, $accounts);

        $workspaces = $this->locks->workspaces($owned);
        $members = $this->locks->memberships('workspace_id', $workspaces->keys());
        $this->locks->users($members->pluck('user_id')->map(fn ($id): int => (int) $id)->diff($accounts->keys()));

        $byWorkspace = $members->groupBy('workspace_id');

        foreach ($workspaces->keys() as $id) {
            /** @var Collection<int, WorkspaceMember> $present */
            $present = $byWorkspace->get($id, new Collection);
            $mine = $present->first(fn (WorkspaceMember $member): bool => (int) $member->user_id === (int) $user->getKey());

            if ($mine === null || ! $mine->role->canManage()) {
                continue;
            }

            $companions = $present->reject(fn (WorkspaceMember $member): bool => (int) $member->user_id === (int) $user->getKey());

            if ($companions->isEmpty() || $companions->contains(fn (WorkspaceMember $member): bool => $member->role->canManage())) {
                continue;
            }

            // The oldest editor, or, without any, the oldest member: the order of joining is that of the membership ids.
            $editors = $companions->filter(fn (WorkspaceMember $member): bool => $member->role === WorkspaceRole::Editor);
            $successor = ($editors->isEmpty() ? $companions : $editors)->sortBy('id')->firstOrFail();

            WorkspaceMember::query()->whereKey($successor->getKey())->update(['role' => WorkspaceRole::Owner->value, 'updated_at' => now()]);
        }
    }

    /**
     * Makes sure the administrator who asks still is one, once the accounts are locked: they may have been demoted (or
     * deleted) by another administrator since the request was authorized, and a request that began as an administrator's
     * must not finish as one's after that.
     *
     * @param  Collection<int, User>  $locked  The accounts locked by {@see Locks::administratorsAnd()}, which include the actor.
     *
     * @throws UserAdministrationRefused When the actor is no longer an administrator.
     */
    private function ensureAdministrator(User $actor, Collection $locked): void
    {
        if ($locked->get((int) $actor->getKey())?->is_admin !== true) {
            throw new UserAdministrationRefused(UserAdministrationRefused::NOT_ADMINISTRATOR);
        }
    }

    /**
     * Writes the line of the log that says what an administrator did: who did it and to which account, as ids, and
     * nothing that identifies a person (no address, no name).
     */
    private function log(string $what, User $actor, User $target): void
    {
        Log::info('Administration: '.$what.'.', ['actor_id' => $actor->getKey(), 'target_id' => $target->getKey()]);
    }
}
