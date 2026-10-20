<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Auth\EmailAddress;
use App\Database\DeadlockRetries;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Workspaces\Exceptions\MembershipRefused;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inviting people to a workspace, and what they do with the invitation.
 *
 * An invitation is bound to an email address, not to a secret link: the invitee signs in with that address (the code mailed to it
 * proves they own it), sees the invitation among theirs and accepts or declines it. The mail only points at the page where they
 * do (see {@see InvitationMailer}), so forwarding it gives nothing to anybody. Only the owner of a workspace invites, as an editor
 * or a viewer; there is one invitation per address and workspace, and it lapses after `workspaces.invitation_ttl_days`. One that has
 * lapsed is invisible and refused everywhere (it is answered as if it was not there), even before {@see self::prune()} clears it.
 *
 * While registration is closed, a waiting invitation is also what lets an address without an account sign up ({@see self::hasPendingFor()}).
 *
 * The workspace holds at most `workspaces.max_members` places, taken by its members and by the invitations that wait; an account
 * cannot belong to more than `workspaces.max_memberships_per_user` workspaces.
 *
 * Whatever changes the members runs in one transaction under the row locks of {@see Locks}, in their order (the accounts, the workspace,
 * its memberships: see {@see Roster}), and the person who acts is judged again there. The invitations are not locked row by row: every
 * writer of the invitations of a workspace holds the workspace's lock, which keeps them one after the other, and a locking read on
 * `workspace_invitations (workspace_id, email)` would lock the gap after its last row on MySQL and MariaDB, where the first invitation
 * of the next workspace is inserted. Declining is the exception: it deletes one row by its key, without the workspace's lock.
 * Refusals are {@see MembershipRefused}, except what is not there for the caller (an `AuthorizationException` with status 404,
 * a `ModelNotFoundException`) and what the rules of a role or an address do not allow (a `ValidationException`).
 */
final class Invitations
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly Workspaces $workspaces,
        private readonly InvitationMailer $mailer,
    ) {}

    /**
     * Invites an address to the workspace, and mails it. The address may belong to an account or not.
     *
     * An invitation to that address that has lapsed is replaced. Inviting the same address twice, or an address of a member (the
     * owner's included), is refused.
     *
     * @param  string  $email  In any case: it is normalized here.
     * @param  WorkspaceRole  $role  Editor or viewer.
     *
     * @throws ValidationException When the address is not valid or the role is the owner's.
     * @throws AuthorizationException With status 404 when the actor does not belong to the workspace.
     * @throws MembershipRefused With `not_owner`, `already_member`, `already_invited` or `member_limit_reached`.
     * @throws ModelNotFoundException When the workspace or the actor's account is gone.
     */
    public function invite(User $actor, Workspace $workspace, string $email, WorkspaceRole $role): WorkspaceInvitation
    {
        $email = EmailAddress::normalize($email);
        $this->validate($email, $role);

        $invitation = DeadlockRetries::run(fn (): WorkspaceInvitation => $this->db->transaction(function () use ($actor, $workspace, $email, $role): WorkspaceInvitation {
            $roster = Roster::lock($this->locks, $workspace, [$actor->getKey()]);
            $roster->owner($actor);

            // Plain reads from here on: the first of the transaction opens its snapshot, after the locks.
            $account = User::query()->where('email', $email)->first();

            if ($account !== null && $roster->membershipOf($account) !== null) {
                throw new MembershipRefused(MembershipRefused::ALREADY_MEMBER);
            }

            $existing = WorkspaceInvitation::query()->where('workspace_id', $roster->workspace->getKey())->where('email', $email)->first();

            if ($existing !== null && ! $existing->isExpired()) {
                throw new MembershipRefused(MembershipRefused::ALREADY_INVITED);
            }

            $limit = config()->integer('workspaces.max_members');
            $waiting = WorkspaceInvitation::query()->where('workspace_id', $roster->workspace->getKey())->pending()->count();

            if ($roster->members->count() + $waiting >= $limit) {
                throw new MembershipRefused(MembershipRefused::MEMBER_LIMIT_REACHED, limit: $limit);
            }

            // Its place is taken by the new one (the pair workspace and address is unique, and the new one is a new invitation).
            $existing?->delete();

            $invitation = new WorkspaceInvitation([
                'workspace_id' => $roster->workspace->getKey(),
                'email' => $email,
                'role' => $role,
                'invited_by_id' => $actor->getKey(),
                'expires_at' => $this->expiry(),
            ]);
            $invitation->save();
            $invitation->setRelation('workspace', $roster->workspace);

            return $invitation;
        }));

        $this->mailer->send($invitation, $actor);

        return $invitation;
    }

    /**
     * Sends an invitation again: the mail goes out once more and the invitation is good for another `workspaces.invitation_ttl_days`.
     * It is allowed at most once every `workspaces.invitation_resend_minutes`, counted from the last time it was sent.
     *
     * @throws AuthorizationException With status 404 when the actor does not belong to the workspace.
     * @throws MembershipRefused With `not_owner`, or `resend_too_soon` (and the seconds to wait).
     * @throws ModelNotFoundException When the invitation is not one of this workspace, or lapsed, or the workspace or the account is gone.
     */
    public function resend(User $actor, Workspace $workspace, WorkspaceInvitation $invitation): WorkspaceInvitation
    {
        $sent = DeadlockRetries::run(fn (): WorkspaceInvitation => $this->db->transaction(function () use ($actor, $workspace, $invitation): WorkspaceInvitation {
            $roster = Roster::lock($this->locks, $workspace, [$actor->getKey()]);
            $roster->owner($actor);

            $current = $this->pendingOf($roster->workspace, $invitation);
            $available = $current->updated_at?->copy()->addMinutes(config()->integer('workspaces.invitation_resend_minutes'));

            if ($available !== null && $available->isFuture()) {
                throw new MembershipRefused(MembershipRefused::RESEND_TOO_SOON, retryAfter: max(1, $available->getTimestamp() - now()->getTimestamp()));
            }

            $current->expires_at = $this->expiry();
            $current->invited_by_id = $actor->getKey();
            $current->updated_at = now();
            $current->save();
            $current->setRelation('workspace', $roster->workspace);

            return $current;
        }));

        $this->mailer->send($sent, $actor);

        return $sent;
    }

    /**
     * Withdraws an invitation: it can no longer be accepted.
     *
     * @throws AuthorizationException With status 404 when the actor does not belong to the workspace.
     * @throws MembershipRefused With `not_owner`.
     * @throws ModelNotFoundException When the invitation is not one of this workspace, or lapsed, or the workspace or the account is gone.
     */
    public function cancel(User $actor, Workspace $workspace, WorkspaceInvitation $invitation): void
    {
        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($actor, $workspace, $invitation): void {
            $roster = Roster::lock($this->locks, $workspace, [$actor->getKey()]);
            $roster->owner($actor);

            $this->pendingOf($roster->workspace, $invitation)->delete();
        }));
    }

    /**
     * The invitations of a workspace that wait for an answer, the newest first. For its owner.
     *
     * @return Collection<int, WorkspaceInvitation>
     *
     * @throws AuthorizationException With status 404 when the viewer does not belong to the workspace.
     * @throws MembershipRefused With `not_owner`.
     */
    public function sent(User $viewer, Workspace $workspace): Collection
    {
        $role = $this->workspaces->roleOf($viewer, $workspace) ?? throw (new AuthorizationException)->withStatus(404);

        if (! $role->canManage()) {
            throw new MembershipRefused(MembershipRefused::NOT_OWNER);
        }

        return WorkspaceInvitation::query()->where('workspace_id', $workspace->getKey())->pending()->orderByDesc('id')->get();
    }

    /**
     * The invitations addressed to the address of an account that wait for an answer, the newest first, with their workspace and sender.
     *
     * @return Collection<int, WorkspaceInvitation>
     */
    public function pendingFor(User $user): Collection
    {
        return WorkspaceInvitation::query()->addressedTo($user->email)->pending()->with(['workspace', 'inviter'])->orderByDesc('id')->get();
    }

    /**
     * Whether an invitation waits for an address: what lets it sign up while registration is closed.
     */
    public function hasPendingFor(string $email): bool
    {
        return WorkspaceInvitation::query()->addressedTo($email)->pending()->exists();
    }

    /**
     * Accepts an invitation: the account joins the workspace with the invited role, and the invitation is spent.
     *
     * It must be addressed to the address of the account and not have lapsed (else it is answered as if it was not there). An account
     * that already belongs to the workspace just spends the invitation (doing it again changes nothing). Judged under the locks, so
     * that two invitations accepted at the same moment cannot take the same last place.
     *
     * @return Workspace The workspace the account has joined.
     *
     * @throws ModelNotFoundException When the invitation is not addressed to the account, or lapsed, or was withdrawn, or the workspace is gone.
     * @throws MembershipRefused With `member_limit_reached` or `membership_limit_reached`.
     */
    public function accept(User $user, WorkspaceInvitation $invitation): Workspace
    {
        return DeadlockRetries::run(fn (): Workspace => $this->db->transaction(function () use ($user, $invitation): Workspace {
            $account = $this->locks->users([$user->getKey()])->get((int) $user->getKey()) ?? throw (new ModelNotFoundException)->setModel(User::class, [$user->getKey()]);
            $workspace = $this->locks->workspace($invitation->workspace_id);
            $members = $this->locks->memberships('workspace_id', [$workspace->getKey()]);

            // The first plain read of the transaction: the invitation as it is now, after the locks.
            $current = WorkspaceInvitation::query()
                ->whereKey($invitation->getKey())
                ->where('workspace_id', $workspace->getKey())
                ->addressedTo($account->email)
                ->pending()
                ->first() ?? throw (new ModelNotFoundException)->setModel(WorkspaceInvitation::class, [$invitation->getKey()]);

            if ($members->contains(fn ($member): bool => (int) $member->user_id === (int) $account->getKey())) {
                $current->delete();

                return $workspace;
            }

            // The invitation being spent is one of the places counted when it was sent: the places taken by members are what is left to check.
            $limit = config()->integer('workspaces.max_members');

            if ($members->count() >= $limit) {
                throw new MembershipRefused(MembershipRefused::MEMBER_LIMIT_REACHED, limit: $limit);
            }

            $joined = config()->integer('workspaces.max_memberships_per_user');

            if ($this->locks->memberships('user_id', [$account->getKey()])->count() >= $joined) {
                throw new MembershipRefused(MembershipRefused::MEMBERSHIP_LIMIT_REACHED, limit: $joined);
            }

            $workspace->members()->attach($account->getKey(), ['role' => $current->role->value]);
            $current->delete();

            return $workspace;
        }));
    }

    /**
     * Declines an invitation: it is deleted. Only the account it is addressed to can.
     *
     * @throws ModelNotFoundException When the invitation is not addressed to the account, or lapsed, or is gone.
     */
    public function decline(User $user, WorkspaceInvitation $invitation): void
    {
        $deleted = WorkspaceInvitation::query()->whereKey($invitation->getKey())->addressedTo($user->email)->pending()->delete();

        if ($deleted === 0) {
            throw (new ModelNotFoundException)->setModel(WorkspaceInvitation::class, [$invitation->getKey()]);
        }
    }

    /**
     * Deletes the invitations that lapsed. Safe to call at any time and as often as wanted.
     *
     * The rows are found without a lock and deleted by their keys, a few hundred at a time: a delete that filtered on the date would
     * lock every row it passes on MySQL and MariaDB (and the gaps between them, where invitations are inserted).
     *
     * @return int How many were deleted.
     */
    public function prune(): int
    {
        $deleted = 0;
        $chunk = 500;

        do {
            $ids = WorkspaceInvitation::query()->where('expires_at', '<=', now())->orderBy('id')->limit($chunk)->pluck('id');

            // The date is checked again: an invitation sent again since it was found is not lapsed any more.
            $deleted += $ids->isEmpty() ? 0 : WorkspaceInvitation::query()->whereIn('id', $ids)->where('expires_at', '<=', now())->delete();
        } while ($ids->count() === $chunk);

        return $deleted;
    }

    /**
     * The invitation of this workspace, as it is now, when it still waits for an answer. Read after the locks.
     *
     * @throws ModelNotFoundException When it is gone, lapsed, or belongs to another workspace.
     */
    private function pendingOf(Workspace $workspace, WorkspaceInvitation $invitation): WorkspaceInvitation
    {
        return WorkspaceInvitation::query()
            ->whereKey($invitation->getKey())
            ->where('workspace_id', $workspace->getKey())
            ->pending()
            ->first() ?? throw (new ModelNotFoundException)->setModel(WorkspaceInvitation::class, [$invitation->getKey()]);
    }

    /**
     * When an invitation sent now lapses.
     */
    private function expiry(): Carbon
    {
        return now()->addDays(config()->integer('workspaces.invitation_ttl_days'));
    }

    /**
     * Refuses an address that is not valid and a role that is not an editor's or a viewer's.
     *
     * @throws ValidationException
     */
    private function validate(string $email, WorkspaceRole $role): void
    {
        Validator::make(
            ['email' => $email, 'role' => $role->value],
            ['email' => EmailAddress::rules(), 'role' => [Rule::in([WorkspaceRole::Editor->value, WorkspaceRole::Viewer->value])]],
        )->validate();
    }
}
