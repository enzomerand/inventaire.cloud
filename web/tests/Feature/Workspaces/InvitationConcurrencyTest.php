<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Workspaces\Workspaces;
use Tests\Support\CommitsDatabaseChanges;

/*
 * Invitations, memberships and ownership changed at the same moment, in separate PHP processes against the real database.
 *
 * This is what a single process cannot reproduce: requests that judge the same workspace at the same time. "Two accepts for the last
 * place", "an owner who hands the ownership to two people" and "an invitation accepted while it is withdrawn" are each judged by reading
 * the state and then writing; they are kept right by the row locks of App\Workspaces\Locks (the accounts, then the workspace, then its
 * memberships), which these tests exercise on whichever engine runs the suite (PostgreSQL, MySQL, MariaDB). Each scenario is repeated,
 * as one lucky ordering proves nothing.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(fn () => removeConcurrencyRows());
afterEach(fn () => removeConcurrencyRows());

/**
 * An owner and an editor in a workspace, committed (the owner and the editor each have a personal workspace too).
 *
 * @return array{owner: User, editor: User, workspace: Workspace}
 */
function concurrentShop(): array
{
    $owner = User::factory()->withPersonalWorkspace()->create();
    $editor = User::factory()->withPersonalWorkspace()->create();
    $workspace = app(Workspaces::class)->create($owner, 'Shop');
    $workspace->members()->attach($editor->id, ['role' => 'editor']);

    return ['owner' => $owner, 'editor' => $editor, 'workspace' => $workspace];
}

/**
 * An account with its personal workspace and an invitation to the workspace waiting for its address.
 *
 * @return array{0: User, 1: WorkspaceInvitation}
 */
function concurrentInvitee(Workspace $workspace): array
{
    $user = User::factory()->withPersonalWorkspace()->create();

    return [$user, WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id, 'email' => $user->email])];
}

/**
 * How many owners a workspace has.
 */
function concurrentOwners(Workspace $workspace): int
{
    return WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('role', 'owner')->count();
}

describe('invitations accepted at the same moment', function (): void {
    it('take the last place once: the other one is refused, and keeps its invitation', function (): void {
        foreach (range(1, 4) as $_) {
            ['workspace' => $workspace] = concurrentShop();
            [$ada, $adaInvitation] = concurrentInvitee($workspace);
            [$bob, $bobInvitation] = concurrentInvitee($workspace);

            // Two members, three places: the invitations were sent when the limit was higher.
            $outcomes = actTogether($this->sandbox->root, [
                ['accept-invitation', $ada->id, $adaInvitation->id],
                ['accept-invitation', $bob->id, $bobInvitation->id],
            ], ['WORKSPACES_MAX_MEMBERS' => '3']);

            expect($outcomes)->toBe(['accepted', 'refused:member_limit_reached'])
                ->and(WorkspaceMember::query()->where('workspace_id', $workspace->id)->count())->toBe(3)
                ->and(WorkspaceInvitation::query()->count())->toBe(1);

            removeConcurrencyRows();
        }
    });

    it('both come in when there is room for both', function (): void {
        foreach (range(1, 3) as $_) {
            ['workspace' => $workspace] = concurrentShop();
            [$ada, $adaInvitation] = concurrentInvitee($workspace);
            [$bob, $bobInvitation] = concurrentInvitee($workspace);

            $outcomes = actTogether($this->sandbox->root, [
                ['accept-invitation', $ada->id, $adaInvitation->id],
                ['accept-invitation', $bob->id, $bobInvitation->id],
            ]);

            expect($outcomes)->toBe(['accepted', 'accepted'])
                ->and(WorkspaceMember::query()->where('workspace_id', $workspace->id)->count())->toBe(4)
                ->and(WorkspaceInvitation::query()->count())->toBe(0);

            removeConcurrencyRows();
        }
    });

    it('cannot take an account past the number of workspaces it may join, whichever it accepts first', function (): void {
        foreach (range(1, 4) as $_) {
            $first = concurrentShop();
            $second = concurrentShop();
            $ada = User::factory()->withPersonalWorkspace()->create();
            $inOne = WorkspaceInvitation::factory()->create(['workspace_id' => $first['workspace']->id, 'email' => $ada->email]);
            $inTwo = WorkspaceInvitation::factory()->create(['workspace_id' => $second['workspace']->id, 'email' => $ada->email]);

            // The personal workspace and room for one more.
            $outcomes = actTogether($this->sandbox->root, [
                ['accept-invitation', $ada->id, $inOne->id],
                ['accept-invitation', $ada->id, $inTwo->id],
            ], ['WORKSPACES_MAX_PER_USER' => '2']);

            expect($outcomes)->toBe(['accepted', 'refused:membership_limit_reached'])
                ->and(WorkspaceMember::query()->where('user_id', $ada->id)->count())->toBe(2);

            removeConcurrencyRows();
        }
    });

    it('either join or find the invitation withdrawn, never both and never neither', function (): void {
        foreach (range(1, 6) as $_) {
            ['owner' => $owner, 'workspace' => $workspace] = concurrentShop();
            [$ada, $invitation] = concurrentInvitee($workspace);

            $outcomes = actTogether($this->sandbox->root, [
                ['accept-invitation', $ada->id, $invitation->id],
                ['cancel-invitation', $owner->id, $workspace->id, $invitation->id],
            ]);

            $joined = in_array('accepted', $outcomes, true);

            // The one who came second finds nothing there.
            expect($outcomes)->toBe($joined ? ['accepted', 'refused:gone'] : ['cancelled', 'refused:gone'])
                ->and(WorkspaceInvitation::query()->count())->toBe(0)
                ->and(WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $ada->id)->exists())->toBe($joined);

            removeConcurrencyRows();
        }
    });

    it('either join or decline, never both', function (): void {
        foreach (range(1, 4) as $_) {
            ['workspace' => $workspace] = concurrentShop();
            [$ada, $invitation] = concurrentInvitee($workspace);

            $outcomes = actTogether($this->sandbox->root, [
                ['accept-invitation', $ada->id, $invitation->id],
                ['decline-invitation', $ada->id, $invitation->id],
            ]);

            $joined = in_array('accepted', $outcomes, true);

            expect($outcomes)->toBe($joined ? ['accepted', 'refused:gone'] : ['declined', 'refused:gone'])
                ->and(WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $ada->id)->exists())->toBe($joined);

            removeConcurrencyRows();
        }
    });

    it('leave nobody half-joined when the invited account is deleted at the same moment', function (): void {
        foreach (range(1, 4) as $_) {
            ['workspace' => $workspace] = concurrentShop();
            [$ada, $invitation] = concurrentInvitee($workspace);

            $outcomes = actTogether($this->sandbox->root, [
                ['accept-invitation', $ada->id, $invitation->id],
                ['delete-account', $ada->id],
            ]);

            // Whichever came first: a member who is then deleted with the account, or a deletion that the invitation finds out about.
            expect($outcomes)->toBeIn([['accepted', 'deleted'], ['deleted', 'refused:gone']])
                ->and(User::query()->whereKey($ada->id)->exists())->toBeFalse()
                ->and(WorkspaceMember::query()->where('user_id', $ada->id)->count())->toBe(0)
                ->and(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue()
                ->and(User::query()->whereDoesntHave('workspaces')->count())->toBe(0);

            removeConcurrencyRows();
        }
    });
});

describe('invitations sent at the same moment', function (): void {
    it('hold one invitation per address: the second of two for the same address is refused', function (): void {
        foreach (range(1, 4) as $_) {
            ['owner' => $owner, 'workspace' => $workspace] = concurrentShop();

            $outcomes = actTogether($this->sandbox->root, [
                ['invite', $owner->id, $workspace->id, 'new@example.test', 'editor'],
                ['invite', $owner->id, $workspace->id, 'new@example.test', 'viewer'],
            ]);

            expect($outcomes)->toBe(['invited', 'refused:already_invited'])
                ->and(WorkspaceInvitation::query()->count())->toBe(1);

            removeConcurrencyRows();
        }
    });

    it('fill the last place once: the other address is refused', function (): void {
        foreach (range(1, 4) as $_) {
            ['owner' => $owner, 'workspace' => $workspace] = concurrentShop();

            // Two members, three places.
            $outcomes = actTogether($this->sandbox->root, [
                ['invite', $owner->id, $workspace->id, 'first@example.test', 'editor'],
                ['invite', $owner->id, $workspace->id, 'second@example.test', 'viewer'],
            ], ['WORKSPACES_MAX_MEMBERS' => '3']);

            expect($outcomes)->toBe(['invited', 'refused:member_limit_reached'])
                ->and(WorkspaceInvitation::query()->count())->toBe(1);

            removeConcurrencyRows();
        }
    });

    it('are not sent by somebody who stopped being the owner meanwhile', function (): void {
        foreach (range(1, 4) as $_) {
            ['owner' => $owner, 'editor' => $editor, 'workspace' => $workspace] = concurrentShop();

            $outcomes = actTogether($this->sandbox->root, [
                ['transfer-ownership', $owner->id, $workspace->id, $editor->id],
                ['invite', $owner->id, $workspace->id, 'new@example.test', 'viewer'],
            ]);

            // Judged before the transfer: sent by the owner. Judged after: refused to an editor. Never half of each.
            expect($outcomes)->toBeIn([['invited', 'transferred'], ['refused:not_owner', 'transferred']])
                ->and(concurrentOwners($workspace))->toBe(1);

            removeConcurrencyRows();
        }
    });
});

describe('the ownership handed over at the same moment', function (): void {
    it('goes to one member: the second hand-over is refused to an owner who no longer is one', function (): void {
        foreach (range(1, 4) as $_) {
            ['owner' => $owner, 'editor' => $editor, 'workspace' => $workspace] = concurrentShop();
            $viewer = User::factory()->withPersonalWorkspace()->create();
            $workspace->members()->attach($viewer->id, ['role' => 'viewer']);

            $outcomes = actTogether($this->sandbox->root, [
                ['transfer-ownership', $owner->id, $workspace->id, $editor->id],
                ['transfer-ownership', $owner->id, $workspace->id, $viewer->id],
            ]);

            expect($outcomes)->toBe(['refused:not_owner', 'transferred'])
                ->and(concurrentOwners($workspace))->toBe(1)
                ->and(WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $owner->id)->where('role', 'editor')->exists())->toBeTrue();

            removeConcurrencyRows();
        }
    });

    it('leaves the workspace an owner when the member it is handed to is removed at the same moment', function (): void {
        foreach (range(1, 6) as $_) {
            ['owner' => $owner, 'editor' => $editor, 'workspace' => $workspace] = concurrentShop();

            $outcomes = actTogether($this->sandbox->root, [
                ['transfer-ownership', $owner->id, $workspace->id, $editor->id],
                ['remove-member', $owner->id, $workspace->id, $editor->id],
            ]);

            // Transferred, then the old owner (an editor now) is no one to remove anybody; or removed, then there is nobody to hand it to.
            expect($outcomes)->toBeIn([['refused:not_owner', 'transferred'], ['refused:gone', 'removed']])
                ->and(concurrentOwners($workspace))->toBe(1)
                ->and(User::query()->whereDoesntHave('workspaces')->count())->toBe(0);

            removeConcurrencyRows();
        }
    });

    it('lets two members leave two shared workspaces at the same moment, each judged against the memberships it locked first', function (): void {
        foreach (range(1, 6) as $_) {
            $first = concurrentShop();
            $second = concurrentShop();
            // The same two people (an owner of neither) belong to both workspaces, so that each leave reads rows the other holds.
            $alice = $first['editor'];
            $bob = User::factory()->withPersonalWorkspace()->create();
            foreach ([$first['workspace'], $second['workspace']] as $workspace) {
                foreach ([$alice, $bob] as $member) {
                    if (! $workspace->members()->whereKey($member->id)->exists()) {
                        $workspace->members()->attach($member->id, ['role' => 'viewer']);
                    }
                }
            }

            $outcomes = actTogether($this->sandbox->root, [
                ['leave-workspace', $alice->id, $first['workspace']->id],
                ['leave-workspace', $bob->id, $second['workspace']->id],
            ]);

            expect($outcomes)->toBe(['left', 'left'])
                ->and(WorkspaceMember::query()->where('workspace_id', $first['workspace']->id)->where('user_id', $alice->id)->exists())->toBeFalse()
                ->and(WorkspaceMember::query()->where('workspace_id', $second['workspace']->id)->where('user_id', $bob->id)->exists())->toBeFalse()
                ->and(WorkspaceMember::query()->where('workspace_id', $second['workspace']->id)->where('user_id', $alice->id)->exists())->toBeTrue()
                ->and(WorkspaceMember::query()->where('workspace_id', $first['workspace']->id)->where('user_id', $bob->id)->exists())->toBeTrue();

            removeConcurrencyRows();
        }
    });

    it('does not strand an owner who leaves while the ownership is being handed to them', function (): void {
        foreach (range(1, 4) as $_) {
            ['owner' => $owner, 'editor' => $editor, 'workspace' => $workspace] = concurrentShop();

            $outcomes = actTogether($this->sandbox->root, [
                ['transfer-ownership', $owner->id, $workspace->id, $editor->id],
                ['leave-workspace', $editor->id, $workspace->id],
            ]);

            // The editor left first (the hand-over then finds nobody), or became the owner first (and cannot leave any more).
            expect($outcomes)->toBeIn([['left', 'refused:gone'], ['refused:owner_cannot_leave', 'transferred']])
                ->and(concurrentOwners($workspace))->toBe(1);

            removeConcurrencyRows();
        }
    });
});
