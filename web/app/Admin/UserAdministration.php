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
use Illuminate\Support\Collection as SupportCollection;

/**
 * What the administrators of the instance do with its accounts: find them, create them, make them administrators
 * (or not any more) and delete them.
 *
 * The rules live here, whoever asks (the API today): an instance always keeps an administrator, an administrator does
 * not delete their own account from here (that takes the code mailed to its owner, from the account page), and no
 * workspace is left without an owner by the deletion of an account.
 *
 * Everything that changes accounts judges under row locks taken in the global order of {@see Locks}, in one transaction
 * that is retried when the database picks it as the loser of a deadlock ({@see DeadlockRetries}): two administrators
 * demoting each other at the same moment, or deleting each other, are judged one after the other and the last one is
 * refused.
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
     * @param  string  $email  Address, in any case: it is stored in canonical form.
     * @param  string  $name  Already validated and trimmed.
     *
     * @throws UserAdministrationRefused When the address already has an account.
     */
    public function create(string $email, string $name): User
    {
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
     * @return User The account as it is now.
     *
     * @throws UserAdministrationRefused When it is the last administrator and would stop being one.
     * @throws ModelNotFoundException When the account is gone.
     */
    public function setAdministrator(User $user, bool $administrator): User
    {
        return $this->db->transaction(function () use ($user, $administrator): User {
            // The administrators and the account in one go, lowest id first, like AccountDeletion: two administrators
            // demoting each other at the same moment would otherwise each hold their own row and wait for the other's.
            $accounts = $this->lockAdministratorsAnd([$user->getKey()]);
            $locked = $accounts->get((int) $user->getKey());

            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel(User::class, [$user->getKey()]);
            }

            if ($locked->is_admin === $administrator) {
                return $locked;
            }

            if (! $administrator && $accounts->doesntContain(fn (User $account): bool => $account->is_admin && ! $account->is($locked))) {
                throw new UserAdministrationRefused(UserAdministrationRefused::LAST_ADMINISTRATOR);
            }

            $locked->is_admin = $administrator;
            $locked->save();

            return $locked;
        }, 3);
    }

    /**
     * Deletes an account, the way the owner's own deletion does (see {@see User::delete()}: the workspaces nobody else
     * belongs to go with it, it just leaves the others), after making sure no shared workspace is left without an owner.
     *
     * When the account is the only owner of a workspace that other people belong to, the member who has been in it the
     * longest (the oldest membership) becomes its owner first, whatever their role was. All of it is one transaction:
     * if the deletion is refused or fails, nobody's role has changed.
     *
     * @param  User  $actor  The administrator who asks.
     * @param  User  $target  The account to delete.
     *
     * @throws UserAdministrationRefused When the target is the actor.
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
        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($id): void {
            $user = User::query()->whereKey($id)->firstOrFail();

            $this->handOverWorkspaces($user);
            $user->delete();
        }));
    }

    /**
     * Gives every workspace the account is the only owner of, and that other people belong to, a new owner: the member
     * of the longest standing.
     *
     * Locks are taken in the order of {@see Locks}, and it is the same order as the deletion that follows takes them
     * (which then finds them held): the sign-in code of the address, then the administrators, the account and the
     * people who could take over, in one statement, lowest id first, then the workspaces, then their memberships.
     * Whom to lock is read before any lock; the memberships are read again under the locks, and the accounts of
     * members who joined in the meantime are locked last, once the workspace is (the path that goes back up the order
     * that `Workspaces::delete()` takes too, see Locks).
     */
    private function handOverWorkspaces(User $user): void
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

        $accounts = $this->lockAdministratorsAnd(array_values([$user->getKey(), ...$others->all()]));

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
            $successor = $companions->sortBy('id')->first();

            if ($successor === null || $companions->contains(fn (WorkspaceMember $member): bool => $member->role->canManage())) {
                continue;
            }

            WorkspaceMember::query()->whereKey($successor->getKey())->update(['role' => WorkspaceRole::Owner->value, 'updated_at' => now()]);
        }
    }

    /**
     * Locks every administrator and the given accounts in one statement, lowest id first, and returns them by id.
     *
     * @param  list<int|string>  $ids  Accounts to lock besides the administrators.
     * @return SupportCollection<int, User>
     */
    private function lockAdministratorsAnd(array $ids): SupportCollection
    {
        /** @var SupportCollection<int, User> $accounts */
        $accounts = User::query()
            ->where(fn ($query) => $query->where('is_admin', true)->orWhereIn('id', $ids))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return $accounts;
    }
}
