<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Database\DeadlockRetries;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\LocaleResolver;
use App\Workspaces\Exceptions\LastWorkspaceException;
use App\Workspaces\Exceptions\WorkspaceLimitReachedException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Creating, finding and deleting the workspaces of a user.
 *
 * The rules that keep the data coherent live here, whatever the caller (sign-up, the API, the command line):
 *
 * - every account belongs to at least one workspace: a personal one is created with the account, a workspace
 *   cannot be deleted from under the last member it has left a place in, and an account found without any (which
 *   only a row changed by hand can be) is given one again by {@see self::current()};
 * - the creator of a workspace is its owner and starts working in it, and nobody belongs to more workspaces than
 *   `workspaces.max_per_user` allows;
 * - the current workspace of a user is the one they chose, or else the first they joined, so that it always exists
 *   even when the chosen one was deleted or the membership ended.
 *
 * Whatever removes memberships or workspaces does it under the row locks of {@see Locks}, so that requests made at
 * the same moment judge the state one after the other.
 */
final class Workspaces
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LocaleResolver $locales,
        private readonly Locks $locks,
    ) {}

    /**
     * Creates the workspace every account starts with.
     *
     * Its name is a label, not the person's name, written in the language of the account (or of the request while
     * it has none yet): a name is personal data that has no business in a title shown to the people one shares with.
     */
    public function createPersonal(User $user): Workspace
    {
        $locale = $user->locale !== null && $this->locales->isSupported($user->locale) ? $user->locale : $this->locales->resolved();

        return $this->create($user, __('Personal space', [], $locale));
    }

    /**
     * Makes sure the user belongs to at least one workspace, creating the personal one if they do not.
     *
     * Safe to call again: it does nothing for a user who already has a workspace. The account's row is locked
     * before its memberships are looked at, so that two requests for the same account (two sign-ins at once) do
     * not each create a personal workspace, and an account deleted in the meantime is left alone instead of
     * failing on a foreign key.
     *
     * Reading the memberships of an account that has none locks the gap where they would be (MySQL and MariaDB, at
     * their default isolation level), and two accounts that get their first workspace at the same moment each wait
     * for the other's gap when they insert: the database aborts one of them. It is retried (3 attempts) when this is
     * the outermost transaction; inside another one (the sign-up's, which is run through {@see DeadlockRetries}), that
     * one is retried as a whole instead, since a deadlock rolls all of it back.
     */
    public function ensure(User $user): void
    {
        $this->db->transaction(function () use ($user): void {
            $locked = $this->locks->users([$user->getKey()])->first();

            if ($locked === null || $this->locks->memberships('user_id', [$locked->getKey()])->isNotEmpty()) {
                return;
            }

            $this->createPersonal($locked);
            $this->adopt($user, $locked);
        }, 3);
    }

    /**
     * Creates a workspace owned by the user, who starts working in it.
     *
     * Retried (3 attempts) when the database picks it as the loser of a deadlock, like {@see self::ensure()}, which
     * calls it for an account's first workspace: when it runs inside another transaction, that one is retried instead.
     *
     * @param  string  $name  Already validated and trimmed.
     *
     * @throws WorkspaceLimitReachedException When the user already belongs to as many workspaces as allowed.
     */
    public function create(User $user, string $name): Workspace
    {
        return $this->db->transaction(function () use ($user, $name): Workspace {
            // The account's row, locked: two requests of the same person count their workspaces one after the other
            // (the limit cannot be crossed by going fast), and an account deleted meanwhile fails here, not on a key.
            if ($this->locks->users([$user->getKey()])->isEmpty()) {
                throw (new ModelNotFoundException)->setModel(User::class, [$user->getKey()]);
            }

            $limit = config()->integer('workspaces.max_per_user');

            if ($this->locks->memberships('user_id', [$user->getKey()])->count() >= $limit) {
                throw new WorkspaceLimitReachedException($limit);
            }

            $workspace = new Workspace(['name' => $name]);
            $workspace->save();

            $workspace->members()->attach($user->getKey(), ['role' => WorkspaceRole::Owner->value]);

            $this->makeCurrent($user, $workspace);

            return $workspace;
        }, 3);
    }

    /**
     * Changes the name of a workspace.
     *
     * @param  string  $name  Already validated and trimmed.
     */
    public function rename(Workspace $workspace, string $name): Workspace
    {
        $workspace->name = $name;
        $workspace->save();

        return $workspace;
    }

    /**
     * Makes a workspace the one the user is working in. The caller checks that they belong to it.
     */
    public function makeCurrent(User $user, Workspace $workspace): void
    {
        if ($user->current_workspace_id === $workspace->getKey()) {
            return;
        }

        $user->current_workspace_id = $workspace->getKey();
        $user->save();
    }

    /**
     * Deletes a workspace, and everything that belongs to it.
     *
     * The user keeps working: if the workspace was their current one, another takes its place. A member of the
     * workspace who has no other one is given a personal workspace, since nobody is left without any.
     *
     * Everything this judges (that the user is still an owner, that they have another workspace, who the members
     * are and whether they have another one) is judged under the locks of {@see Locks}: the user, the members and
     * the workspace, so that two requests deleting two workspaces that the same people share cannot both find
     * "another one is left" and take them both. The workspace goes through its own `delete()`, so that its model
     * events fire (what is stored for a workspace is purged there).
     *
     * @throws LastWorkspaceException When it is the only workspace of the user.
     * @throws ModelNotFoundException When the workspace was deleted in the meantime.
     * @throws AuthorizationException When the user is no longer an owner of it.
     */
    public function delete(User $user, Workspace $workspace): void
    {
        $this->db->transaction(function () use ($user, $workspace): void {
            // The accounts first: the user and the members as far as they can be told before any lock; the ones
            // who joined while this waited are locked below.
            $candidates = WorkspaceMember::query()->where('workspace_id', $workspace->getKey())->pluck('user_id')->push($user->getKey());
            $accounts = $this->locks->users($candidates);

            $locked = $this->locks->workspaces([$workspace->getKey()])->first();

            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel(Workspace::class, [$workspace->getKey()]);
            }

            $members = $this->locks->memberships('workspace_id', [$workspace->getKey()]);
            $accounts = $accounts->union($this->locks->users($members->pluck('user_id')->diff($accounts->keys())));

            $own = $members->firstWhere('user_id', $user->getKey());

            if ($own === null) {
                throw (new AuthorizationException)->withStatus(404);
            }

            if (! $own->role->canManage()) {
                throw new AuthorizationException(__('Only an owner of this workspace can delete it.'));
            }

            $others = $this->locks->memberships('user_id', [$user->getKey()])
                ->reject(fn (WorkspaceMember $other): bool => (int) $other->workspace_id === (int) $workspace->getKey())
                ->values();

            if ($others->isEmpty()) {
                throw new LastWorkspaceException;
            }

            $companions = $members->pluck('user_id')->map(fn ($id): int => (int) $id)->reject(fn (int $id): bool => $id === (int) $user->getKey())->values();
            $kept = $this->locks->memberships('user_id', $companions)
                ->reject(fn (WorkspaceMember $other): bool => (int) $other->workspace_id === (int) $workspace->getKey())
                ->pluck('user_id')
                ->map(fn ($id): int => (int) $id);
            $stranded = $companions->reject(fn (int $id): bool => $kept->contains($id));

            $actor = $accounts->get((int) $user->getKey()) ?? $user;

            if ($actor->current_workspace_id === $workspace->getKey()) {
                $actor->current_workspace_id = (int) $others->firstOrFail()->workspace_id;
                $actor->save();
            }

            $locked->delete();

            foreach ($stranded as $id) {
                $member = $accounts->get($id);

                if ($member !== null) {
                    $this->createPersonal($member);
                }
            }

            $this->adopt($user, $actor);
        }, 3);
    }

    /**
     * The workspaces of the user, oldest membership first, with the role they hold in each and which one is current.
     *
     * @return list<Membership>
     */
    public function memberships(User $user): array
    {
        $workspaces = $user->workspaces()->orderBy('workspace_user.id')->get();
        $current = $this->currentOf($user, $workspaces);

        return array_values($workspaces
            ->map(fn (Workspace $workspace): Membership => $this->membershipOf($workspace, $current))
            ->all());
    }

    /**
     * The workspace of the user the given way, or null when they do not belong to it.
     */
    public function membership(User $user, Workspace $workspace): ?Membership
    {
        $found = $user->workspaces()->whereKey($workspace->getKey())->first();

        if ($found === null) {
            return null;
        }

        return $this->membershipOf($found, $this->currentOf($user, $user->workspaces()->orderBy('workspace_user.id')->get()));
    }

    /**
     * The workspace the user is working in: the one they chose if they still belong to it, else the first one
     * they joined.
     *
     * An account that belongs to none (the rules prevent it, but a row changed by hand, a restore or a bug can
     * break them) is given its personal workspace here, so that nobody is ever stuck without somewhere to work.
     * Null only when the account itself no longer exists.
     */
    public function current(User $user): ?Workspace
    {
        $memberships = $this->memberships($user);

        if ($memberships === []) {
            $this->ensure($user);
            $memberships = $this->memberships($user);
        }

        foreach ($memberships as $membership) {
            if ($membership->current) {
                return $membership->workspace;
            }
        }

        return null;
    }

    /**
     * The role of the user in the workspace, or null when they are not a member.
     */
    public function roleOf(User $user, Workspace $workspace): ?WorkspaceRole
    {
        $membership = WorkspaceMember::query()
            ->where('user_id', $user->getKey())
            ->where('workspace_id', $workspace->getKey())
            ->first();

        return $membership?->role;
    }

    /**
     * Brings the current workspace of the caller's copy of the account in line with the one that was locked and saved.
     */
    private function adopt(User $user, User $fresh): void
    {
        $user->setAttribute('current_workspace_id', $fresh->current_workspace_id);
        $user->syncOriginalAttribute('current_workspace_id');
    }

    /**
     * The key of the workspace the user is working in, among those they belong to.
     *
     * @param  Collection<int, Workspace>  $workspaces  The workspaces of the user, oldest membership first.
     */
    private function currentOf(User $user, Collection $workspaces): ?int
    {
        $chosen = $user->current_workspace_id;

        if ($chosen !== null && $workspaces->contains(fn (Workspace $workspace): bool => $workspace->getKey() === $chosen)) {
            return $chosen;
        }

        $first = $workspaces->first();

        return $first === null ? null : $first->getKey();
    }

    /**
     * A workspace loaded through the `workspaces` relation of a user, with its role.
     */
    private function membershipOf(Workspace $workspace, ?int $current): Membership
    {
        /** @var WorkspaceMember $pivot */
        $pivot = $workspace->getRelation('pivot');

        return new Membership($workspace, $pivot->role, $workspace->getKey() === $current);
    }
}
