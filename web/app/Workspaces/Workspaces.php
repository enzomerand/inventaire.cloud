<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\LocaleResolver;
use App\Workspaces\Exceptions\LastWorkspaceException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;

/**
 * Creating, finding and deleting the workspaces of a user.
 *
 * The rules that keep the data coherent live here, whatever the caller (sign-up, the API, the command line):
 *
 * - every account belongs to at least one workspace: a personal one is created with the account, and a workspace
 *   cannot be deleted from under the last member it has left a place in;
 * - the creator of a workspace is its owner and starts working in it;
 * - the current workspace of a user is the one they chose, or else the first they joined, so that it always exists
 *   even when the chosen one was deleted or the membership ended.
 */
final class Workspaces
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LocaleResolver $locales,
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
     * Safe to call again: it does nothing for a user who already has a workspace.
     */
    public function ensure(User $user): void
    {
        $this->db->transaction(function () use ($user): void {
            if (! WorkspaceMember::query()->where('user_id', $user->getKey())->exists()) {
                $this->createPersonal($user);
            }
        });
    }

    /**
     * Creates a workspace owned by the user, who starts working in it.
     *
     * @param  string  $name  Already validated and trimmed.
     */
    public function create(User $user, string $name): Workspace
    {
        return $this->db->transaction(function () use ($user, $name): Workspace {
            $workspace = new Workspace(['name' => $name]);
            $workspace->save();

            $workspace->members()->attach($user->getKey(), ['role' => WorkspaceRole::Owner->value]);

            $this->makeCurrent($user, $workspace);

            return $workspace;
        });
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
     * @throws LastWorkspaceException When it is the only workspace of the user.
     */
    public function delete(User $user, Workspace $workspace): void
    {
        $this->db->transaction(function () use ($user, $workspace): void {
            // Taken before anything is read: two requests deleting two workspaces of the same user must not both
            // find "another one is left" and take them both.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $others = $user->workspaces()->where('workspaces.id', '!=', $workspace->getKey())->orderBy('workspace_user.id')->get();

            if ($others->isEmpty()) {
                throw new LastWorkspaceException;
            }

            $stranded = User::query()
                ->whereIn('id', WorkspaceMember::query()->select('user_id')->where('workspace_id', $workspace->getKey())->where('user_id', '!=', $user->getKey()))
                ->whereDoesntHave('workspaces', fn ($query) => $query->where('workspaces.id', '!=', $workspace->getKey()))
                ->get();

            if ($user->current_workspace_id === $workspace->getKey()) {
                $this->makeCurrent($user, $others->firstOrFail());
            }

            $workspace->delete();

            foreach ($stranded as $member) {
                $this->createPersonal($member);
            }
        });
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
     * they joined. Null only for an account that has none (which {@see self::ensure()} prevents).
     */
    public function current(User $user): ?Workspace
    {
        foreach ($this->memberships($user) as $membership) {
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
