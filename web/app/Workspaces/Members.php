<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Database\DeadlockRetries;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\PhotoEntry\PhotoEntry;
use App\Workspaces\Exceptions\MembershipRefused;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Who belongs to a workspace and with which role: listing the members, changing a role, removing a member, leaving, and handing the
 * ownership over. (Adding members is {@see Invitations}.)
 *
 * A workspace has one owner, and only the owner decides who belongs to it and what they may do. The owner is never removed, never
 * demoted and never leaves: ownership is handed over to a member first, which makes the old owner an editor. Nobody is left without a
 * workspace either: an account that loses its last membership (it left, or was removed) is given a personal workspace, as when a
 * workspace it belonged to is deleted ({@see Workspaces::delete()}).
 *
 * Every verdict (that the person who asks is still the owner, that the member is still a member) is given again under the row locks
 * of {@see Locks}, in their order (see {@see Roster}), and the outermost transaction is run again when the database picks it as the
 * loser of a deadlock ({@see DeadlockRetries}). A person who does not belong to the workspace is answered as if it did not exist
 * (`AuthorizationException` with status 404, or `ModelNotFoundException` for a member that is not one); a member without the right
 * gets a {@see MembershipRefused} (`not_owner`, 403).
 */
final class Members
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly Workspaces $workspaces,
        private readonly PhotoEntry $photoEntry,
    ) {}

    /**
     * The members of a workspace, the owner first, then in the order they joined.
     *
     * Every member sees every other: name, role and the date they joined. The address of a member is shown to the owner and to the
     * member it belongs to, and to nobody else ({@see MemberEntry}).
     *
     * @return list<MemberEntry>
     *
     * @throws AuthorizationException With status 404 when the viewer does not belong to the workspace.
     */
    public function list(User $viewer, Workspace $workspace): array
    {
        $own = $this->workspaces->roleOf($viewer, $workspace) ?? throw (new AuthorizationException)->withStatus(404);

        $members = WorkspaceMember::query()->where('workspace_id', $workspace->getKey())->orderBy('id')->get();
        $accounts = User::query()->whereIn('id', $members->pluck('user_id'))->get()->keyBy('id');

        $entries = [];

        foreach ($members->sortBy(fn (WorkspaceMember $member): int => $member->role->canManage() ? 0 : 1)->values() as $member) {
            $account = $accounts->get($member->user_id);

            if ($account !== null) {
                $entries[] = $this->entry($member, $account, $own->canManage() || (int) $account->getKey() === (int) $viewer->getKey(), $viewer);
            }
        }

        return $entries;
    }

    /**
     * The member of a workspace that an identifier names, or null when it names nobody who belongs to it (made up, malformed, the
     * `ulid` of somebody who left or of an account that never was in this workspace). The identifier is searched among the members of
     * this workspace only, never among all the accounts, so that a guess says nothing about accounts that are not here.
     *
     * @param  string  $ulid  The `ulid` of an account, in any case.
     */
    public function find(Workspace $workspace, string $ulid): ?User
    {
        $ulid = strtolower($ulid);

        if (preg_match('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/', $ulid) !== 1) {
            return null;
        }

        return $workspace->members()->where('users.ulid', $ulid)->first();
    }

    /**
     * Changes the role of a member to editor or viewer.
     *
     * @return MemberEntry The member as the owner sees them, with the new role.
     *
     * @throws ValidationException When the role is the owner's: a workspace has one owner, made by {@see self::transferOwnership()}.
     * @throws AuthorizationException With status 404 when the actor does not belong to the workspace.
     * @throws MembershipRefused With `not_owner` when the actor is not the owner, and `cannot_change_owner_role` when the member is the owner.
     * @throws ModelNotFoundException When the member does not belong to the workspace (or the workspace is gone).
     */
    public function changeRole(User $actor, Workspace $workspace, User $target, WorkspaceRole $role): MemberEntry
    {
        Validator::make(['role' => $role->value], ['role' => [Rule::in([WorkspaceRole::Editor->value, WorkspaceRole::Viewer->value])]])->validate();

        return DeadlockRetries::run(fn (): MemberEntry => $this->db->transaction(function () use ($actor, $workspace, $target, $role): MemberEntry {
            $roster = Roster::lock($this->locks, $workspace, [$actor->getKey(), $target->getKey()]);
            $roster->owner($actor);
            $member = $roster->target($target);

            if ($member->role->canManage()) {
                throw new MembershipRefused(MembershipRefused::CANNOT_CHANGE_OWNER_ROLE);
            }

            $this->setRole($member, $role);
            $member->setAttribute('role', $role);
            $account = $roster->account((int) $target->getKey()) ?? $target;

            return $this->entry($member, $account, true, $actor);
        }));
    }

    /**
     * Removes a member from the workspace: they lose access at once. When the member is the actor, this is {@see self::leave()} (which is
     * how the owner is refused: the owner is the only one the actor could not remove, and there is one).
     *
     * @throws AuthorizationException With status 404 when the actor does not belong to the workspace.
     * @throws MembershipRefused With `not_owner` when the actor is not the owner, `owner_cannot_leave` when it is the owner removing themselves.
     * @throws ModelNotFoundException When the member does not belong to the workspace (or the workspace is gone).
     */
    public function remove(User $actor, Workspace $workspace, User $target): void
    {
        if ((int) $actor->getKey() === (int) $target->getKey()) {
            $this->leave($actor, $workspace);

            return;
        }

        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($actor, $workspace, $target): void {
            $roster = Roster::lock($this->locks, $workspace, [$actor->getKey(), $target->getKey()]);
            $roster->owner($actor);

            $this->detach($roster, $roster->target($target), $target);
        }));
    }

    /**
     * Leaves a workspace the person belongs to without owning it. When it was their last one, they are given a personal workspace.
     *
     * @throws AuthorizationException With status 404 when the person does not belong to the workspace.
     * @throws MembershipRefused With `owner_cannot_leave` when they are the owner: ownership has to be handed over first.
     */
    public function leave(User $user, Workspace $workspace): void
    {
        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($user, $workspace): void {
            $roster = Roster::lock($this->locks, $workspace, [$user->getKey()]);
            $member = $roster->member($user);

            if ($member->role->canManage()) {
                throw new MembershipRefused(MembershipRefused::OWNER_CANNOT_LEAVE);
            }

            $this->detach($roster, $member, $user);
        }));
    }

    /**
     * Hands the ownership over to another member: they become the owner, and the actor becomes an editor. Both happen together. The
     * consent of the workspace to the photo entry is withdrawn: the new owner has to give their own ({@see PhotoEntry::withdrawConsent()}).
     *
     * @throws AuthorizationException With status 404 when the actor does not belong to the workspace.
     * @throws MembershipRefused With `not_owner` when the actor is not the owner, and `already_owner` when the member is the owner already.
     * @throws ModelNotFoundException When the member does not belong to the workspace (or the workspace is gone).
     */
    public function transferOwnership(User $actor, Workspace $workspace, User $target): void
    {
        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($actor, $workspace, $target): void {
            $roster = Roster::lock($this->locks, $workspace, [$actor->getKey(), $target->getKey()]);
            $mine = $roster->owner($actor);
            $theirs = $roster->target($target);

            if ($theirs->role->canManage()) {
                throw new MembershipRefused(MembershipRefused::ALREADY_OWNER);
            }

            $this->setRole($theirs, WorkspaceRole::Owner);
            $this->setRole($mine, WorkspaceRole::Editor);
            // What the previous owner consented to is not the new owner's decision.
            $this->photoEntry->withdrawConsent($workspace);
        }));
    }

    /**
     * Writes the role of a membership. The caller holds the locks and has judged.
     */
    private function setRole(WorkspaceMember $member, WorkspaceRole $role): void
    {
        WorkspaceMember::query()->whereKey($member->getKey())->update(['role' => $role->value, 'updated_at' => now()]);
    }

    /**
     * Ends a membership, under the locks of the caller.
     *
     * The workspace is no longer the one the account works in, if it was. An account left with no workspace at all is given its personal one.
     * Whether it has another is a plain count of its memberships, not a locking read (see {@see Locks}).
     *
     * @param  User  $copy  The caller's copy of the account, brought in line with the locked one.
     */
    private function detach(Roster $roster, WorkspaceMember $member, User $copy): void
    {
        $account = $roster->account((int) $member->user_id) ?? throw (new ModelNotFoundException)->setModel(User::class, [$member->user_id]);

        WorkspaceMember::query()->whereKey($member->getKey())->delete();

        if ($account->current_workspace_id === $roster->workspace->getKey()) {
            $account->current_workspace_id = null;
            $account->save();
        }

        if (! WorkspaceMember::query()->where('user_id', $account->getKey())->exists()) {
            $this->workspaces->createPersonal($account);
        }

        $copy->setAttribute('current_workspace_id', $account->current_workspace_id);
        $copy->syncOriginalAttribute('current_workspace_id');
    }

    /**
     * A line of the list.
     *
     * @param  bool  $showEmail  Whether the viewer may see the address of this member.
     */
    private function entry(WorkspaceMember $member, User $account, bool $showEmail, User $viewer): MemberEntry
    {
        return new MemberEntry(
            ulid: $account->ulid,
            name: $account->name,
            email: $showEmail ? $account->email : null,
            role: $member->role,
            joinedAt: $member->created_at,
            self: (int) $account->getKey() === (int) $viewer->getKey(),
        );
    }
}
