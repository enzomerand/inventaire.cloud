<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Workspaces\Exceptions\MembershipRefused;
use App\Workspaces\Invitations;
use App\Workspaces\Members;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/**
 * The refusal the rules raised, or null when nothing was refused.
 */
function invitationRefusal(Closure $act): ?MembershipRefused
{
    try {
        $act();
    } catch (MembershipRefused $refusal) {
        return $refusal;
    }

    return null;
}

/**
 * Whether the action was answered as if what it is about was not there.
 */
function invitationNotFound(Closure $act): bool
{
    try {
        $act();
    } catch (ModelNotFoundException) {
        return true;
    } catch (AuthorizationException $denied) {
        return $denied->status() === 404;
    }

    return false;
}

/**
 * Has something done right after the first read of the invitations table that follows: the moment the service has looked at an
 * invitation and is about to act on it, which is where somebody who declines (that takes no lock) can get in.
 */
function afterInvitationsAreRead(Closure $then): void
{
    $done = false;

    DB::listen(function (QueryExecuted $query) use ($then, &$done): void {
        if (! $done && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'workspace_invitations')) {
            $done = true;
            $then();
        }
    });
}

/**
 * The Invitations service.
 */
function invitations(): Invitations
{
    return app(Invitations::class);
}

describe('inviting', function (): void {
    it('stores an invitation for the address, in canonical form, with the role and a time limit', function (): void {
        $team = inventoryTeam();
        $this->travelTo(now()->startOfSecond());

        $invitation = invitations()->invite($team['owner'], $team['workspace'], '  Nouveau@Example.TEST ', WorkspaceRole::Editor);

        expect($invitation->exists)->toBeTrue()
            ->and($invitation->ulid)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
            ->and($invitation->email)->toBe('nouveau@example.test')
            ->and($invitation->role)->toBe(WorkspaceRole::Editor)
            ->and($invitation->workspace_id)->toBe($team['workspace']->id)
            ->and($invitation->invited_by_id)->toBe($team['owner']->id)
            ->and($invitation->expires_at->equalTo(now()->addDays(14)))->toBeTrue()
            ->and(WorkspaceInvitation::query()->count())->toBe(1);
    });

    it('gives a viewer role as readily as an editor one', function (): void {
        $team = inventoryTeam();

        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'lecteur@example.test', WorkspaceRole::Viewer);

        expect($invitation->fresh()->role)->toBe(WorkspaceRole::Viewer);
    });

    it('is good for as long as the configuration says', function (): void {
        config(['workspaces.invitation_ttl_days' => 3]);
        $team = inventoryTeam();
        $this->travelTo(now()->startOfSecond());

        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Editor);

        expect($invitation->expires_at->equalTo(now()->addDays(3)))->toBeTrue();
    });

    it('does not need the invited address to have an account, and does not care whether it has one', function (): void {
        $team = inventoryTeam();
        User::factory()->create(['email' => 'ada@example.test']);

        invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Editor);
        invitations()->invite($team['owner'], $team['workspace'], 'unknown@example.test', WorkspaceRole::Editor);

        expect(WorkspaceInvitation::query()->orderBy('id')->pluck('email')->all())->toBe(['ada@example.test', 'unknown@example.test']);
    });

    it('is the owner\'s alone: an editor or a viewer is refused, with the code of a member without the right', function (string $who): void {
        $team = inventoryTeam();

        $refusal = invitationRefusal(fn () => invitations()->invite($team[$who], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Viewer));

        expect($refusal?->reason)->toBe('not_owner')
            ->and($refusal?->status())->toBe(403)
            ->and(WorkspaceInvitation::query()->count())->toBe(0);
    })->with(['an editor' => ['editor'], 'a viewer' => ['viewer']]);

    it('is answered as if the workspace did not exist for somebody who does not belong to it', function (): void {
        $team = inventoryTeam();

        expect(invitationNotFound(fn () => invitations()->invite($team['stranger'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Viewer)))->toBeTrue()
            ->and(WorkspaceInvitation::query()->count())->toBe(0);
    });

    it('refuses an address that does not exist, and a role that is not an editor\'s or a viewer\'s', function (): void {
        $team = inventoryTeam();

        foreach (['not an address', '', 'a b@example.test', 'josé@example.test'] as $address) {
            expect(fn () => invitations()->invite($team['owner'], $team['workspace'], $address, WorkspaceRole::Editor))->toThrow(ValidationException::class);
        }

        try {
            invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Owner);
        } catch (ValidationException $invalid) {
            expect(array_keys($invalid->errors()))->toBe(['role']);
        }

        expect(WorkspaceInvitation::query()->count())->toBe(0);
    });

    it('refuses the address of a member, the owner included, however it is spelled', function (): void {
        $team = inventoryTeam();

        foreach (['owner' => $team['owner'], 'editor' => $team['editor'], 'viewer' => $team['viewer']] as $member) {
            $refusal = invitationRefusal(fn () => invitations()->invite($team['owner'], $team['workspace'], strtoupper(' '.$member->email), WorkspaceRole::Viewer));

            expect($refusal?->reason)->toBe('already_member')->and($refusal?->status())->toBe(409);
        }

        expect(WorkspaceInvitation::query()->count())->toBe(0);
    });

    it('refuses an address that is invited already, whatever the role asked, but not the same address to another workspace', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Editor);

        $refusal = invitationRefusal(fn () => invitations()->invite($team['owner'], $team['workspace'], 'Nouveau@Example.test', WorkspaceRole::Viewer));
        invitations()->invite($team['owner'], $other, 'nouveau@example.test', WorkspaceRole::Viewer);

        expect($refusal?->reason)->toBe('already_invited')->and($refusal?->status())->toBe(409)
            ->and(WorkspaceInvitation::query()->where('workspace_id', $team['workspace']->id)->sole()->role)->toBe(WorkspaceRole::Editor)
            ->and(WorkspaceInvitation::query()->count())->toBe(2);
    });

    it('replaces an invitation that expired: it is as good as gone', function (): void {
        $team = inventoryTeam();
        $old = WorkspaceInvitation::factory()->expired()->create(['workspace_id' => $team['workspace']->id, 'email' => 'nouveau@example.test', 'role' => 'viewer']);

        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Editor);

        expect(WorkspaceInvitation::query()->count())->toBe(1)
            ->and(WorkspaceInvitation::query()->whereKey($old->id)->exists())->toBeFalse()
            ->and($invitation->ulid)->not->toBe($old->ulid)
            ->and($invitation->fresh()->role)->toBe(WorkspaceRole::Editor)
            ->and($invitation->fresh()->expires_at->isFuture())->toBeTrue();
    });

    it('stops at the number of members and waiting invitations the configuration allows', function (): void {
        // Three members; one more place; the second invitation does not fit.
        config(['workspaces.max_members' => 4]);
        $team = inventoryTeam();

        invitations()->invite($team['owner'], $team['workspace'], 'first@example.test', WorkspaceRole::Viewer);
        $refusal = invitationRefusal(fn () => invitations()->invite($team['owner'], $team['workspace'], 'second@example.test', WorkspaceRole::Viewer));

        expect($refusal?->reason)->toBe('member_limit_reached')
            ->and($refusal?->status())->toBe(409)
            ->and($refusal?->limit)->toBe(4)
            ->and(WorkspaceInvitation::query()->count())->toBe(1);
    });

    it('does not count an invitation that expired against the places', function (): void {
        config(['workspaces.max_members' => 4]);
        $team = inventoryTeam();
        WorkspaceInvitation::factory()->expired()->create(['workspace_id' => $team['workspace']->id]);

        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'first@example.test', WorkspaceRole::Viewer);

        expect($invitation->exists)->toBeTrue();
    });

    it('frees a place when the member leaves or the invitation is withdrawn', function (): void {
        config(['workspaces.max_members' => 4]);
        $team = inventoryTeam();
        $first = invitations()->invite($team['owner'], $team['workspace'], 'first@example.test', WorkspaceRole::Viewer);

        invitations()->cancel($team['owner'], $team['workspace'], $first);

        expect(invitations()->invite($team['owner'], $team['workspace'], 'second@example.test', WorkspaceRole::Viewer)->exists)->toBeTrue();
    });

    it('is refused for a workspace that is gone', function (): void {
        $team = inventoryTeam();
        $team['workspace']->delete();

        expect(invitationNotFound(fn () => invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Viewer)))->toBeTrue();
    });
});

describe('sending an invitation again', function (): void {
    it('waits ten minutes from the moment the mail last went out, the first one included', function (): void {
        $team = inventoryTeam();
        $this->travelTo(now()->startOfSecond());
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Editor);

        $this->travel(9)->minutes();
        $soon = invitationRefusal(fn () => invitations()->resend($team['owner'], $team['workspace'], $invitation));

        expect($soon?->reason)->toBe('resend_too_soon')
            ->and($soon?->status())->toBe(429)
            ->and($soon?->retryAfter)->toBe(60);

        $this->travel(1)->minutes();
        $sent = invitations()->resend($team['owner'], $team['workspace'], $invitation);

        expect($sent->updated_at->equalTo(now()))->toBeTrue();

        // And the ten minutes start again from this one.
        $this->travel(5)->minutes();
        $again = invitationRefusal(fn () => invitations()->resend($team['owner'], $team['workspace'], $invitation));

        expect($again?->reason)->toBe('resend_too_soon')->and($again?->retryAfter)->toBe(300);

        $this->travel(5)->minutes();

        expect(invitations()->resend($team['owner'], $team['workspace'], $invitation)->exists)->toBeTrue();
    });

    it('extends the time limit, and names the owner who sends it now', function (): void {
        $team = inventoryTeam();
        $this->travelTo(now()->startOfSecond());
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Editor);
        $this->travel(11)->days();
        $expiring = $invitation->fresh()->expires_at;

        app(Members::class)->transferOwnership($team['owner'], $team['workspace'], $team['editor']);
        $sent = invitations()->resend($team['editor'], $team['workspace'], $invitation);

        expect($sent->expires_at->equalTo(now()->addDays(14)))->toBeTrue()
            ->and($sent->expires_at->greaterThan($expiring))->toBeTrue()
            ->and($sent->fresh()->invited_by_id)->toBe($team['editor']->id)
            // The role and the address are the same.
            ->and($sent->fresh()->role)->toBe(WorkspaceRole::Editor)
            ->and($sent->fresh()->email)->toBe('nouveau@example.test');
    });

    it('sends nothing for an invitation that was declined after it was read', function (): void {
        $team = inventoryTeam();
        $held = holdDeferred();
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Editor);
        $held->release();
        $mails = count(sentMails());
        $this->travel(11)->minutes();
        afterInvitationsAreRead(fn () => WorkspaceInvitation::query()->whereKey($invitation->id)->delete());

        expect(invitationNotFound(fn () => invitations()->resend($team['owner'], $team['workspace'], $invitation)))->toBeTrue();

        $held->release();

        // (The invitation is not counted: the deletion that simulates the other person was made in the transaction that was rolled back.)
        expect(count(sentMails()))->toBe($mails);
    });

    it('leaves the invitation as it was in the table, and says how many rows it changed: one', function (): void {
        $team = inventoryTeam();
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Editor);
        $this->travel(11)->minutes();

        $sent = invitations()->resend($team['owner'], $team['workspace'], $invitation);

        expect($sent->isDirty())->toBeFalse()
            ->and(WorkspaceInvitation::query()->count())->toBe(1)
            ->and($sent->fresh()->updated_at->equalTo(now()->startOfSecond()))->toBeTrue();
    });

    it('is the owner\'s alone', function (string $who): void {
        $team = inventoryTeam();
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Editor);
        $this->travel(11)->minutes();

        $refusal = invitationRefusal(fn () => invitations()->resend($team[$who], $team['workspace'], $invitation));

        expect($refusal?->reason)->toBe('not_owner')->and($refusal?->status())->toBe(403);
    })->with(['an editor' => ['editor'], 'a viewer' => ['viewer']]);

    it('is answered as if it did not exist for somebody who does not belong to the workspace', function (): void {
        $team = inventoryTeam();
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Editor);
        $this->travel(11)->minutes();

        expect(invitationNotFound(fn () => invitations()->resend($team['stranger'], $team['workspace'], $invitation)))->toBeTrue();
    });

    it('cannot reach an invitation of another workspace, nor one that expired', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        $elsewhere = invitations()->invite($team['owner'], $other, 'nouveau@example.test', WorkspaceRole::Editor);
        $expired = WorkspaceInvitation::factory()->expired()->create(['workspace_id' => $team['workspace']->id, 'invited_by_id' => $team['owner']->id]);
        $this->travel(11)->minutes();

        expect(invitationNotFound(fn () => invitations()->resend($team['owner'], $team['workspace'], $elsewhere)))->toBeTrue()
            ->and(invitationNotFound(fn () => invitations()->resend($team['owner'], $team['workspace'], $expired)))->toBeTrue();
    });
});

describe('withdrawing an invitation', function (): void {
    it('deletes it, and the address can be invited again at once', function (): void {
        $team = inventoryTeam();
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Editor);

        invitations()->cancel($team['owner'], $team['workspace'], $invitation);

        expect(WorkspaceInvitation::query()->count())->toBe(0)
            ->and(invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Viewer)->exists)->toBeTrue();
    });

    it('is the owner\'s alone', function (string $who): void {
        $team = inventoryTeam();
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Editor);

        $refusal = invitationRefusal(fn () => invitations()->cancel($team[$who], $team['workspace'], $invitation));

        expect($refusal?->reason)->toBe('not_owner')->and(WorkspaceInvitation::query()->count())->toBe(1);
    })->with(['an editor' => ['editor'], 'a viewer' => ['viewer']]);

    it('is answered as if it did not exist for somebody who does not belong to the workspace, and for the address that is invited', function (): void {
        $team = inventoryTeam();
        $invitee = User::factory()->withPersonalWorkspace()->create(['email' => 'nouveau@example.test']);
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Editor);

        expect(invitationNotFound(fn () => invitations()->cancel($team['stranger'], $team['workspace'], $invitation)))->toBeTrue()
            ->and(invitationNotFound(fn () => invitations()->cancel($invitee, $team['workspace'], $invitation)))->toBeTrue()
            ->and(WorkspaceInvitation::query()->count())->toBe(1);
    });

    it('cannot reach an invitation of another workspace, nor one that expired', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        $elsewhere = invitations()->invite($team['owner'], $other, 'nouveau@example.test', WorkspaceRole::Editor);
        $expired = WorkspaceInvitation::factory()->expired()->create(['workspace_id' => $team['workspace']->id]);

        expect(invitationNotFound(fn () => invitations()->cancel($team['owner'], $team['workspace'], $elsewhere)))->toBeTrue()
            ->and(invitationNotFound(fn () => invitations()->cancel($team['owner'], $team['workspace'], $expired)))->toBeTrue()
            ->and(WorkspaceInvitation::query()->count())->toBe(2);
    });
});

describe('the invitations a workspace has sent', function (): void {
    it('are listed for its owner, the newest first, without the ones that expired', function (): void {
        $team = inventoryTeam();
        invitations()->invite($team['owner'], $team['workspace'], 'first@example.test', WorkspaceRole::Editor);
        WorkspaceInvitation::factory()->expired()->create(['workspace_id' => $team['workspace']->id, 'email' => 'old@example.test']);
        invitations()->invite($team['owner'], $team['workspace'], 'second@example.test', WorkspaceRole::Viewer);
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        invitations()->invite($team['owner'], $other, 'elsewhere@example.test', WorkspaceRole::Viewer);

        $sent = invitations()->sent($team['owner'], $team['workspace']);

        expect($sent->pluck('email')->all())->toBe(['second@example.test', 'first@example.test']);
    });

    it('are the owner\'s to see: an editor is refused, a stranger is told there is nothing', function (): void {
        $team = inventoryTeam();

        expect(invitationRefusal(fn () => invitations()->sent($team['editor'], $team['workspace']))?->reason)->toBe('not_owner')
            ->and(invitationRefusal(fn () => invitations()->sent($team['viewer'], $team['workspace']))?->reason)->toBe('not_owner')
            ->and(invitationNotFound(fn () => invitations()->sent($team['stranger'], $team['workspace'])))->toBeTrue();
    });
});

describe('the invitations a person has received', function (): void {
    it('are the ones that wait for their address, spelled any way, newest first, with the workspace and who invited', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        invitations()->invite($team['owner'], $team['workspace'], 'Ada@Example.test', WorkspaceRole::Viewer);
        $other = app(Workspaces::class)->create($team['viewer'], 'Garage');
        invitations()->invite($team['viewer'], $other, 'ada@example.test', WorkspaceRole::Editor);
        invitations()->invite($team['owner'], $team['workspace'], 'bob@example.test', WorkspaceRole::Viewer);

        $received = invitations()->pendingFor($ada);

        expect($received->map(fn (WorkspaceInvitation $invitation): string => $invitation->workspace->name.'/'.$invitation->inviter?->name.'/'.$invitation->role->value)->all())
            ->toBe(['Garage/Viewer/editor', 'Shop/Owner/viewer']);
    });

    it('do not include the ones that expired, though they have not been pruned', function (): void {
        $ada = User::factory()->create(['email' => 'ada@example.test']);
        $workspace = Workspace::factory()->create();
        WorkspaceInvitation::factory()->expired()->create(['workspace_id' => $workspace->id, 'email' => 'ada@example.test']);

        expect(invitations()->pendingFor($ada)->all())->toBe([])
            ->and(WorkspaceInvitation::query()->count())->toBe(1)
            ->and(invitations()->hasTicketFor('ada@example.test'))->toBeFalse();
    });

    it('tell whether one waits for an address that can serve as its ticket', function (): void {
        $team = inventoryTeam();
        invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);

        expect(invitations()->hasTicketFor('ada@example.test'))->toBeTrue()
            ->and(invitations()->hasTicketFor(' ADA@example.test '))->toBeTrue()
            ->and(invitations()->hasTicketFor('bob@example.test'))->toBeFalse();
    });

    it('are nobody\'s ticket when no account sent them, though the account of the address can still answer them', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        invitations()->invite($team['owner'], $team['workspace'], 'bob@example.test', WorkspaceRole::Viewer);
        WorkspaceInvitation::factory()->create(['workspace_id' => $team['workspace']->id, 'email' => 'ada@example.test', 'invited_by_id' => null]);

        expect(invitations()->hasTicketFor('bob@example.test'))->toBeTrue()
            ->and(invitations()->hasTicketFor('ada@example.test'))->toBeFalse()
            ->and(invitations()->pendingFor($ada)->all())->toHaveCount(1)
            ->and(invitations()->countPendingFor('ada@example.test'))->toBe(1);
    });

    it('lose their ticket when the account that sent them is deleted', function (): void {
        $team = inventoryTeam();
        $sent = invitations()->invite($team['owner'], $team['workspace'], 'cora@example.test', WorkspaceRole::Viewer);
        app(Members::class)->transferOwnership($team['owner'], $team['workspace'], $team['editor']);

        expect(invitations()->hasTicketFor('cora@example.test'))->toBeTrue();

        $team['owner']->delete();

        expect($sent->fresh()->invited_by_id)->toBeNull()->and(invitations()->hasTicketFor('cora@example.test'))->toBeFalse();
    });

    it('still show who invited when that account is gone', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);
        WorkspaceInvitation::query()->update(['invited_by_id' => null]);

        $received = invitations()->pendingFor($ada);

        expect($received)->toHaveCount(1)->and($received->first()->inviter)->toBeNull();
    });
});

describe('accepting', function (): void {
    it('makes the account a member with the invited role and spends the invitation', function (string $role): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::from($role));
        $current = $ada->fresh()->current_workspace_id;

        $workspace = invitations()->accept($ada, $invitation);

        expect($workspace->is($team['workspace']))->toBeTrue()
            ->and(app(Workspaces::class)->roleOf($ada, $team['workspace']))->toBe(WorkspaceRole::from($role))
            ->and(WorkspaceInvitation::query()->count())->toBe(0)
            // Going to the workspace is the person's choice: nothing is switched for them.
            ->and($ada->fresh()->current_workspace_id)->toBe($current)
            ->and(WorkspaceMember::query()->where('workspace_id', $team['workspace']->id)->count())->toBe(4);
    })->with(['editor' => ['editor'], 'viewer' => ['viewer']]);

    it('does not need the address to have been invited with the spelling it signed up with', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada.lovelace@example.test']);
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'ADA.Lovelace@EXAMPLE.test', WorkspaceRole::Viewer);

        invitations()->accept($ada, $invitation);

        expect(app(Workspaces::class)->roleOf($ada, $team['workspace']))->toBe(WorkspaceRole::Viewer);
    });

    it('is refused as if the invitation did not exist to an account with another address', function (): void {
        $team = inventoryTeam();
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);

        foreach ([$team['stranger'], $team['editor'], $team['owner']] as $other) {
            expect(invitationNotFound(fn () => invitations()->accept($other, $invitation)))->toBeTrue();
        }

        expect(WorkspaceInvitation::query()->count())->toBe(1)
            ->and(WorkspaceMember::query()->where('workspace_id', $team['workspace']->id)->count())->toBe(3);
    });

    it('is refused when the invitation expired, though it was not pruned', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);

        $this->travel(15)->days();

        expect(invitationNotFound(fn () => invitations()->accept($ada, $invitation)))->toBeTrue()
            ->and(WorkspaceMember::query()->where('workspace_id', $team['workspace']->id)->count())->toBe(3);
    });

    it('is refused when the owner withdrew the invitation, or when it was accepted already', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $withdrawn = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);
        invitations()->cancel($team['owner'], $team['workspace'], $withdrawn);
        $spent = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);
        invitations()->accept($ada, $spent);

        expect(invitationNotFound(fn () => invitations()->accept($ada, $withdrawn)))->toBeTrue()
            ->and(invitationNotFound(fn () => invitations()->accept($ada, $spent)))->toBeTrue();
    });

    it('lets an account that is a member already just spend the invitation, keeping the role it has', function (): void {
        $team = inventoryTeam();
        $invitation = WorkspaceInvitation::factory()->create(['workspace_id' => $team['workspace']->id, 'email' => $team['editor']->email, 'role' => 'viewer']);
        $asOwner = WorkspaceInvitation::factory()->create(['workspace_id' => $team['workspace']->id, 'email' => $team['owner']->email, 'role' => 'viewer']);

        $workspace = invitations()->accept($team['editor'], $invitation);
        invitations()->accept($team['owner'], $asOwner);

        expect($workspace->is($team['workspace']))->toBeTrue()
            ->and(WorkspaceInvitation::query()->count())->toBe(0)
            ->and(app(Workspaces::class)->roleOf($team['editor'], $team['workspace']))->toBe(WorkspaceRole::Editor)
            ->and(app(Workspaces::class)->roleOf($team['owner'], $team['workspace']))->toBe(WorkspaceRole::Owner);
    });

    it('is refused when the workspace holds as many members as the configuration allows', function (): void {
        config(['workspaces.max_members' => 3]);
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        // Sent when the limit was higher.
        $invitation = WorkspaceInvitation::factory()->create(['workspace_id' => $team['workspace']->id, 'email' => 'ada@example.test']);

        $refusal = invitationRefusal(fn () => invitations()->accept($ada, $invitation));

        expect($refusal?->reason)->toBe('member_limit_reached')
            ->and($refusal?->status())->toBe(409)
            ->and($refusal?->limit)->toBe(3)
            ->and(WorkspaceInvitation::query()->count())->toBe(1)
            ->and(app(Workspaces::class)->roleOf($ada, $team['workspace']))->toBeNull();
    });

    it('is not refused for the place the invitation itself holds', function (): void {
        // Two members and the invitation: three places, all taken, and accepting takes none more.
        config(['workspaces.max_members' => 3]);
        $owner = inventoryPerson('Owner');
        $workspace = app(Workspaces::class)->create($owner, 'Shop');
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $workspace->members()->attach(inventoryPerson('Editor')->id, ['role' => 'editor']);
        $invitation = invitations()->invite($owner, $workspace, 'ada@example.test', WorkspaceRole::Viewer);

        invitations()->accept($ada, $invitation);

        expect(WorkspaceMember::query()->where('workspace_id', $workspace->id)->count())->toBe(3);
    });

    it('is refused to an account that belongs to as many workspaces as the configuration allows', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $other = app(Workspaces::class)->create($team['viewer'], 'Garage');
        $other->members()->attach($ada->id, ['role' => 'viewer']);
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);
        config(['workspaces.max_per_user' => 2]);

        $refusal = invitationRefusal(fn () => invitations()->accept($ada, $invitation));

        expect($refusal?->reason)->toBe('membership_limit_reached')
            ->and($refusal?->status())->toBe(409)
            ->and($refusal?->limit)->toBe(2)
            ->and(WorkspaceInvitation::query()->count())->toBe(1);

        config(['workspaces.max_per_user' => 3]);

        expect(invitations()->accept($ada, $invitation)->is($team['workspace']))->toBeTrue();
    });

    it('never gives the rights of an owner, whatever the row says: a role that is not an editor\'s is a viewer\'s', function (string $stored, string $given): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Editor);
        // Written by hand: the model refuses to store an owner\'s invitation, the table does not.
        WorkspaceInvitation::query()->whereKey($invitation->id)->update(['role' => $stored]);

        invitations()->accept($ada, $invitation);

        expect(app(Workspaces::class)->roleOf($ada, $team['workspace']))->toBe(WorkspaceRole::from($given))
            ->and(WorkspaceMember::query()->where('workspace_id', $team['workspace']->id)->where('role', 'owner')->count())->toBe(1);
    })->with(['owner' => ['owner', 'viewer'], 'unknown' => ['superuser', 'viewer'], 'editor' => ['editor', 'editor']]);

    it('joins nobody when the invitation was declined after it was read: it is spent by the statement that counts', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Editor);
        afterInvitationsAreRead(fn () => WorkspaceInvitation::query()->whereKey($invitation->id)->delete());

        expect(invitationNotFound(fn () => invitations()->accept($ada, $invitation)))->toBeTrue()
            ->and(app(Workspaces::class)->roleOf($ada, $team['workspace']))->toBeNull()
            ->and(WorkspaceMember::query()->where('workspace_id', $team['workspace']->id)->count())->toBe(3);
    });

    it('joins nobody when the invitation lapsed after it was read', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Editor);
        afterInvitationsAreRead(fn () => WorkspaceInvitation::query()->whereKey($invitation->id)->update(['expires_at' => now()->subSecond()]));

        expect(invitationNotFound(fn () => invitations()->accept($ada, $invitation)))->toBeTrue()
            ->and(app(Workspaces::class)->roleOf($ada, $team['workspace']))->toBeNull();
    });

    it('counts the workspaces of the account with the limit of creating them, and does not count the one it is invited to', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->create(['email' => 'ada@example.test']);
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);
        $other = app(Workspaces::class)->create($team['viewer'], 'Garage');
        $second = invitations()->invite($team['viewer'], $other, 'ada@example.test', WorkspaceRole::Viewer);
        config(['workspaces.max_per_user' => 1]);

        // An account that belongs to no workspace has room for one.
        expect(invitations()->accept($ada, $invitation)->is($team['workspace']))->toBeTrue();

        // Now it belongs to one, which is the limit: a second invitation is refused with the code that says so.
        expect(invitationRefusal(fn () => invitations()->accept($ada, $second))?->reason)->toBe('membership_limit_reached');
    });

    it('is refused when the workspace was deleted meanwhile', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);
        $team['workspace']->delete();

        expect(invitationNotFound(fn () => invitations()->accept($ada, $invitation)))->toBeTrue();
    });
});

describe('declining', function (): void {
    it('deletes the invitation and changes nothing else', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);

        invitations()->decline($ada, $invitation);

        expect(WorkspaceInvitation::query()->count())->toBe(0)
            ->and(WorkspaceMember::query()->where('workspace_id', $team['workspace']->id)->count())->toBe(3)
            ->and(invitations()->pendingFor($ada)->all())->toBe([]);
    });

    it('is the invitee\'s alone: the owner and anybody else are answered as if it did not exist', function (): void {
        $team = inventoryTeam();
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);

        foreach ([$team['owner'], $team['editor'], $team['stranger']] as $other) {
            expect(invitationNotFound(fn () => invitations()->decline($other, $invitation)))->toBeTrue();
        }

        expect(WorkspaceInvitation::query()->count())->toBe(1);
    });

    it('is refused when the invitation expired, or was answered already', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $invitation = invitations()->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);

        invitations()->decline($ada, $invitation);
        $expired = WorkspaceInvitation::factory()->expired()->create(['workspace_id' => $team['workspace']->id, 'email' => 'ada@example.test']);

        expect(invitationNotFound(fn () => invitations()->decline($ada, $invitation)))->toBeTrue()
            ->and(invitationNotFound(fn () => invitations()->decline($ada, $expired)))->toBeTrue();
    });
});

describe('pruning', function (): void {
    it('deletes the invitations that expired and only those', function (): void {
        $workspace = Workspace::factory()->create();
        WorkspaceInvitation::factory()->expired()->count(2)->create(['workspace_id' => $workspace->id]);
        $waiting = WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id]);

        expect(invitations()->prune())->toBe(2)
            ->and(WorkspaceInvitation::query()->pluck('id')->all())->toBe([$waiting->id])
            ->and(invitations()->prune())->toBe(0);
    });

    it('deletes them in several rounds when there are many', function (): void {
        $workspace = Workspace::factory()->create();
        $rows = [];

        foreach (range(1, 620) as $number) {
            $rows[] = ['ulid' => (string) Str::ulid(), 'workspace_id' => $workspace->id, 'email' => "old{$number}@example.test", 'role' => 'viewer', 'expires_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('workspace_invitations')->insert($chunk);
        }

        WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id]);

        expect(invitations()->prune())->toBe(620)->and(WorkspaceInvitation::query()->count())->toBe(1);
    });

    it('is a command, scheduled every hour without running twice at once', function (): void {
        $workspace = Workspace::factory()->create();
        WorkspaceInvitation::factory()->expired()->create(['workspace_id' => $workspace->id]);

        $this->artisan('inventaire:prune-invitations')->expectsOutputToContain('1 expired invitation(s) deleted.')->assertSuccessful();

        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'inventaire:prune-invitations'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            ->and($events->first()->expiresAt)->toBe(30)
            ->and(WorkspaceInvitation::query()->count())->toBe(0);
    });
});
