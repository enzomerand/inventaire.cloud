<?php

declare(strict_types=1);

use App\Admin\UserAdministration;
use App\Auth\AccountDeletion;
use App\Auth\Exceptions\AccountDeletionRefused;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Workspaces\Exceptions\MembershipRefused;
use App\Workspaces\Invitations;
use App\Workspaces\MemberEntry;
use App\Workspaces\Members;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/**
 * The Members service.
 */
function members(): Members
{
    return app(Members::class);
}

/**
 * The refusal the rules raised, or null when nothing was refused.
 */
function memberRefusal(Closure $act): ?MembershipRefused
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
function memberNotFound(Closure $act): bool
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
 * The role a person holds in a workspace, by its word, or null.
 */
function roleWord(User $user, Workspace $workspace): ?string
{
    return app(Workspaces::class)->roleOf($user->fresh(), $workspace)?->value;
}

/**
 * The tables a callback locked with a locking read, in the order of their first lock (a table locked twice in a row counts once).
 *
 * @return list<string>
 */
function lockedTablesOf(Closure $callback): array
{
    $tables = [];

    foreach (statementsOf($callback) as $statement) {
        if (preg_match('/\bfor update\b/i', $statement) === 1 && preg_match('/\bfrom [`"]?(\w+)[`"]?/i', $statement, $match) === 1 && ($tables === [] || end($tables) !== $match[1])) {
            $tables[] = $match[1];
        }
    }

    return $tables;
}

describe('the list of members', function (): void {
    it('shows every member to every member, the owner first, then in the order they joined', function (string $viewer): void {
        $team = inventoryTeam();

        $entries = members()->list($team[$viewer], $team['workspace']);

        expect(array_map(fn (MemberEntry $entry): string => $entry->name.':'.$entry->role->value, $entries))->toBe(['Owner:owner', 'Editor:editor', 'Viewer:viewer'])
            ->and($entries[0]->id)->toBe($team['owner']->id)
            ->and(array_map(fn (MemberEntry $entry): string => $entry->ulid, $entries))->toBe([$team['owner']->ulid, $team['editor']->ulid, $team['viewer']->ulid])
            ->and($entries[1]->joinedAt?->isToday())->toBeTrue();
    })->with(['the owner' => ['owner'], 'an editor' => ['editor'], 'a viewer' => ['viewer']]);

    it('shows the addresses to the owner, and to everybody else their own alone', function (): void {
        $team = inventoryTeam();

        $asOwner = members()->list($team['owner'], $team['workspace']);
        $asEditor = members()->list($team['editor'], $team['workspace']);
        $asViewer = members()->list($team['viewer'], $team['workspace']);

        expect(array_map(fn (MemberEntry $entry): ?string => $entry->email, $asOwner))->toBe([$team['owner']->email, $team['editor']->email, $team['viewer']->email])
            ->and(array_map(fn (MemberEntry $entry): ?string => $entry->email, $asEditor))->toBe([null, $team['editor']->email, null])
            ->and(array_map(fn (MemberEntry $entry): ?string => $entry->email, $asViewer))->toBe([null, null, $team['viewer']->email])
            ->and(array_map(fn (MemberEntry $entry): bool => $entry->self, $asEditor))->toBe([false, true, false]);
    });

    it('is answered as if the workspace did not exist for somebody who does not belong to it', function (): void {
        $team = inventoryTeam();

        expect(memberNotFound(fn () => members()->list($team['stranger'], $team['workspace'])))->toBeTrue();
    });
});

describe('changing a role', function (): void {
    it('makes an editor a viewer and a viewer an editor, and answers with the member as the owner sees them', function (): void {
        $team = inventoryTeam();

        $demoted = members()->changeRole($team['owner'], $team['workspace'], $team['editor'], WorkspaceRole::Viewer);
        $promoted = members()->changeRole($team['owner'], $team['workspace'], $team['viewer'], WorkspaceRole::Editor);

        expect($demoted->role)->toBe(WorkspaceRole::Viewer)
            ->and($demoted->email)->toBe($team['editor']->email)
            ->and($promoted->role)->toBe(WorkspaceRole::Editor)
            ->and(roleWord($team['editor'], $team['workspace']))->toBe('viewer')
            ->and(roleWord($team['viewer'], $team['workspace']))->toBe('editor')
            ->and(app(Workspaces::class)->roleOf($team['editor'], $team['workspace'])?->canEdit())->toBeFalse();
    });

    it('is the owner\'s alone', function (string $who): void {
        $team = inventoryTeam();

        $refusal = memberRefusal(fn () => members()->changeRole($team[$who], $team['workspace'], $team['viewer'], WorkspaceRole::Editor));

        expect($refusal?->reason)->toBe('not_owner')->and($refusal?->status())->toBe(403)
            ->and(roleWord($team['viewer'], $team['workspace']))->toBe('viewer');
    })->with(['an editor' => ['editor'], 'a viewer' => ['viewer']]);

    it('is answered as if the workspace did not exist for somebody who does not belong to it', function (): void {
        $team = inventoryTeam();

        expect(memberNotFound(fn () => members()->changeRole($team['stranger'], $team['workspace'], $team['viewer'], WorkspaceRole::Editor)))->toBeTrue();
    });

    it('refuses to change the role of the owner', function (): void {
        $team = inventoryTeam();

        $refusal = memberRefusal(fn () => members()->changeRole($team['owner'], $team['workspace'], $team['owner'], WorkspaceRole::Viewer));

        expect($refusal?->reason)->toBe('cannot_change_owner_role')->and($refusal?->status())->toBe(409)
            ->and(roleWord($team['owner'], $team['workspace']))->toBe('owner');
    });

    it('refuses to make anybody an owner: ownership is handed over', function (): void {
        $team = inventoryTeam();

        expect(fn () => members()->changeRole($team['owner'], $team['workspace'], $team['editor'], WorkspaceRole::Owner))->toThrow(ValidationException::class)
            ->and(roleWord($team['editor'], $team['workspace']))->toBe('editor');
    });

    it('is refused as if the member did not exist for somebody who is not one', function (): void {
        $team = inventoryTeam();

        expect(memberNotFound(fn () => members()->changeRole($team['owner'], $team['workspace'], $team['stranger'], WorkspaceRole::Viewer)))->toBeTrue()
            ->and(roleWord($team['stranger'], $team['workspace']))->toBeNull();
    });
});

describe('removing a member', function (): void {
    it('takes the access away at once, and leaves the person their other workspaces', function (): void {
        $team = inventoryTeam();

        members()->remove($team['owner'], $team['workspace'], $team['editor']);

        expect(roleWord($team['editor'], $team['workspace']))->toBeNull()
            ->and(array_map(fn ($membership): string => $membership->workspace->name, app(Workspaces::class)->memberships($team['editor']->fresh())))->toBe(['Espace personnel'])
            ->and(roleWord($team['viewer'], $team['workspace']))->toBe('viewer');
    });

    it('stops the removed member from working in the workspace', function (): void {
        $team = inventoryTeam();
        app(Workspaces::class)->makeCurrent($team['editor'], $team['workspace']);

        members()->remove($team['owner'], $team['workspace'], $team['editor']);

        $editor = $team['editor']->fresh();

        expect($editor->current_workspace_id)->toBeNull()
            ->and(app(Workspaces::class)->current($editor)?->name)->toBe('Espace personnel');
    });

    it('gives a personal workspace to a member who had no other', function (): void {
        $team = inventoryTeam();
        $newcomer = User::factory()->create(['name' => 'Newcomer']);
        $team['workspace']->members()->attach($newcomer->id, ['role' => 'editor']);

        members()->remove($team['owner'], $team['workspace'], $newcomer);

        $workspaces = app(Workspaces::class)->memberships($newcomer->fresh());

        expect($workspaces)->toHaveCount(1)
            ->and($workspaces[0]->workspace->name)->toBe('Espace personnel')
            ->and($workspaces[0]->role)->toBe(WorkspaceRole::Owner)
            ->and($workspaces[0]->current)->toBeTrue();
    });

    it('is the owner\'s alone', function (string $who): void {
        $team = inventoryTeam();

        $refusal = memberRefusal(fn () => members()->remove($team[$who], $team['workspace'], $team['viewer']));

        expect($refusal?->reason)->toBe('not_owner')
            ->and(roleWord($team['viewer'], $team['workspace']))->toBe('viewer');
    })->with(['an editor' => ['editor']]);

    it('is answered as if the workspace did not exist for somebody who does not belong to it', function (): void {
        $team = inventoryTeam();

        expect(memberNotFound(fn () => members()->remove($team['stranger'], $team['workspace'], $team['viewer'])))->toBeTrue()
            ->and(roleWord($team['viewer'], $team['workspace']))->toBe('viewer');
    });

    it('is refused as if the member did not exist for somebody who is not one', function (): void {
        $team = inventoryTeam();

        expect(memberNotFound(fn () => members()->remove($team['owner'], $team['workspace'], $team['stranger'])))->toBeTrue();
    });

    it('is leaving when the member is the person who asks', function (): void {
        $team = inventoryTeam();

        members()->remove($team['editor'], $team['workspace'], $team['editor']);

        expect(roleWord($team['editor'], $team['workspace']))->toBeNull();
    });

    it('does not let the owner remove themself', function (): void {
        $team = inventoryTeam();

        $refusal = memberRefusal(fn () => members()->remove($team['owner'], $team['workspace'], $team['owner']));

        expect($refusal?->reason)->toBe('owner_cannot_leave');
    });

    it('frees the place of the member, so that the address can be invited again', function (): void {
        $team = inventoryTeam();
        members()->remove($team['owner'], $team['workspace'], $team['editor']);

        $invitation = app(Invitations::class)->invite($team['owner'], $team['workspace'], $team['editor']->email, WorkspaceRole::Viewer);

        expect($invitation->exists)->toBeTrue();
    });
});

describe('leaving', function (): void {
    it('ends the membership of an editor or a viewer, who keep their other workspaces', function (string $who): void {
        $team = inventoryTeam();

        members()->leave($team[$who], $team['workspace']);

        expect(roleWord($team[$who], $team['workspace']))->toBeNull()
            ->and(Workspace::query()->whereKey($team['workspace']->id)->exists())->toBeTrue()
            ->and(app(Workspaces::class)->memberships($team[$who]->fresh()))->toHaveCount(1);
    })->with(['an editor' => ['editor'], 'a viewer' => ['viewer']]);

    it('brings the copy of the account the caller holds in line', function (): void {
        $team = inventoryTeam();
        app(Workspaces::class)->makeCurrent($team['editor'], $team['workspace']);

        members()->leave($team['editor'], $team['workspace']);

        expect($team['editor']->current_workspace_id)->toBeNull()->and($team['editor']->isDirty())->toBeFalse();
    });

    it('gives a personal workspace to a person who had no other', function (): void {
        $team = inventoryTeam();
        $newcomer = User::factory()->create();
        $team['workspace']->members()->attach($newcomer->id, ['role' => 'viewer']);

        members()->leave($newcomer, $team['workspace']);

        $workspaces = app(Workspaces::class)->memberships($newcomer->fresh());

        expect($workspaces)->toHaveCount(1)->and($workspaces[0]->workspace->name)->toBe('Espace personnel')
            ->and($workspaces[0]->workspace->is($team['workspace']))->toBeFalse()
            ->and($newcomer->current_workspace_id)->toBe($workspaces[0]->workspace->id);
    });

    it('is refused to the owner: ownership has to be handed over first', function (): void {
        $team = inventoryTeam();

        $refusal = memberRefusal(fn () => members()->leave($team['owner'], $team['workspace']));

        expect($refusal?->reason)->toBe('owner_cannot_leave')->and($refusal?->status())->toBe(409)
            ->and(roleWord($team['owner'], $team['workspace']))->toBe('owner');
    });

    it('is answered as if the workspace did not exist for somebody who does not belong to it', function (): void {
        $team = inventoryTeam();

        expect(memberNotFound(fn () => members()->leave($team['stranger'], $team['workspace'])))->toBeTrue();
    });

    it('lets the owner leave once ownership was handed over', function (): void {
        $team = inventoryTeam();
        members()->transferOwnership($team['owner'], $team['workspace'], $team['editor']);

        members()->leave($team['owner'], $team['workspace']);

        expect(roleWord($team['owner'], $team['workspace']))->toBeNull()->and(roleWord($team['editor'], $team['workspace']))->toBe('owner');
    });
});

describe('handing the ownership over', function (): void {
    it('makes the member the owner and the old owner an editor, together', function (string $target): void {
        $team = inventoryTeam();

        members()->transferOwnership($team['owner'], $team['workspace'], $team[$target]);

        expect(roleWord($team[$target], $team['workspace']))->toBe('owner')
            ->and(roleWord($team['owner'], $team['workspace']))->toBe('editor')
            ->and(WorkspaceMember::query()->where('workspace_id', $team['workspace']->id)->where('role', 'owner')->count())->toBe(1);
    })->with(['an editor' => ['editor'], 'a viewer' => ['viewer']]);

    it('moves the rights with it: the new owner manages, the old one does not', function (): void {
        $team = inventoryTeam();
        members()->transferOwnership($team['owner'], $team['workspace'], $team['editor']);

        $old = memberRefusal(fn () => app(Invitations::class)->invite($team['owner'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Viewer));
        $new = app(Invitations::class)->invite($team['editor'], $team['workspace'], 'nouveau@example.test', WorkspaceRole::Viewer);

        expect($old?->reason)->toBe('not_owner')->and($new->exists)->toBeTrue();
    });

    it('is the owner\'s alone, and only the owner at the moment it is judged', function (): void {
        $team = inventoryTeam();

        expect(memberRefusal(fn () => members()->transferOwnership($team['editor'], $team['workspace'], $team['viewer']))?->reason)->toBe('not_owner')
            ->and(memberRefusal(fn () => members()->transferOwnership($team['viewer'], $team['workspace'], $team['viewer']))?->reason)->toBe('not_owner');

        // A copy of the account that still believes it owns the workspace is not trusted.
        members()->transferOwnership($team['owner'], $team['workspace'], $team['editor']);

        expect(memberRefusal(fn () => members()->transferOwnership($team['owner'], $team['workspace'], $team['viewer']))?->reason)->toBe('not_owner')
            ->and(roleWord($team['viewer'], $team['workspace']))->toBe('viewer');
    });

    it('is answered as if the workspace did not exist for somebody who does not belong to it', function (): void {
        $team = inventoryTeam();

        expect(memberNotFound(fn () => members()->transferOwnership($team['stranger'], $team['workspace'], $team['viewer'])))->toBeTrue();
    });

    it('is refused as if the member did not exist for somebody who is not one', function (): void {
        $team = inventoryTeam();

        expect(memberNotFound(fn () => members()->transferOwnership($team['owner'], $team['workspace'], $team['stranger'])))->toBeTrue()
            ->and(roleWord($team['owner'], $team['workspace']))->toBe('owner');
    });

    it('is refused to the owner who hands it to themself', function (): void {
        $team = inventoryTeam();

        $refusal = memberRefusal(fn () => members()->transferOwnership($team['owner'], $team['workspace'], $team['owner']));

        expect($refusal?->reason)->toBe('already_owner')->and($refusal?->status())->toBe(409)
            ->and(roleWord($team['owner'], $team['workspace']))->toBe('owner');
    });

    it('lets the old owner delete their account, which was refused while they were the only owner of a shared workspace', function (): void {
        $team = inventoryTeam();

        expect(fn () => app(AccountDeletion::class)->ensureDeletable($team['owner']))->toThrow(AccountDeletionRefused::class);

        members()->transferOwnership($team['owner'], $team['workspace'], $team['editor']);

        app(AccountDeletion::class)->ensureDeletable($team['owner']);
        $team['owner']->delete();

        expect(User::query()->whereKey($team['owner']->id)->exists())->toBeFalse()
            ->and(roleWord($team['editor'], $team['workspace']))->toBe('owner');
    });
});

describe('the order of the locks', function (): void {
    it('is the global one for every change of who belongs to a workspace: the accounts, the workspace, then its memberships', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $invitations = app(Invitations::class);
        $expected = ['users', 'workspaces', 'workspace_user'];

        $invited = null;
        $locks = [
            'invite' => lockedTablesOf(function () use ($team, $invitations, &$invited): void {
                $invited = $invitations->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);
            }),
            'accept' => lockedTablesOf(fn () => $invitations->accept($ada, $invited)),
            'role' => lockedTablesOf(fn () => members()->changeRole($team['owner'], $team['workspace'], $ada, WorkspaceRole::Editor)),
            'transfer' => lockedTablesOf(fn () => members()->transferOwnership($team['owner'], $team['workspace'], $team['editor'])),
            'remove' => lockedTablesOf(fn () => members()->remove($team['editor'], $team['workspace'], $ada)),
            'leave' => lockedTablesOf(fn () => members()->leave($team['viewer'], $team['workspace'])),
        ];

        expect($locks)->toBe(array_fill_keys(array_keys($locks), $expected));
    });

    it('never locks the memberships of an account once those of the workspace are locked: what is left of the account is a plain count', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $invited = app(Invitations::class)->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);

        $runs = [
            'accept' => fn () => app(Invitations::class)->accept($ada, $invited),
            'remove' => fn () => members()->remove($team['owner'], $team['workspace'], $ada),
            'leave' => fn () => members()->leave($team['viewer'], $team['workspace']),
        ];

        foreach ($runs as $name => $run) {
            $locking = array_values(array_filter(
                statementsOf($run),
                fn (string $statement): bool => stripos($statement, 'for update') !== false && stripos($statement, 'workspace_user') !== false,
            ));

            // One locking read of the memberships, and it is the workspace's (the account's own would filter on user_id).
            expect($locking, $name)->toHaveCount(1)
                ->and($locking[0], $name)->toContain('workspace_id')->not->toContain('user_id');
        }
    });

    it('keeps the invitation in the workspace\'s lock: no locking read on the rows of the invitations', function (): void {
        $team = inventoryTeam();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);

        $statements = statementsOf(function () use ($team, $ada): void {
            $invitation = app(Invitations::class)->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);
            app(Invitations::class)->accept($ada, $invitation);
        });

        expect(array_filter($statements, fn (string $statement): bool => str_contains($statement, 'workspace_invitations') && stripos($statement, 'for update') !== false))->toBe([]);
    });
});

describe('invitations beside the other ways of ending an account or a workspace', function (): void {
    it('go with the workspace when its owner deletes it', function (): void {
        $team = inventoryTeam();
        app(Invitations::class)->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);

        app(Workspaces::class)->delete($team['owner'], $team['workspace']);

        expect(WorkspaceInvitation::query()->count())->toBe(0);
    });

    it('go with the workspace when its only member deletes their account', function (): void {
        $owner = inventoryPerson('Owner');
        $workspace = app(Workspaces::class)->create($owner, 'Shop');
        app(Invitations::class)->invite($owner, $workspace, 'ada@example.test', WorkspaceRole::Viewer);

        // Only invitations wait in the workspace: nobody else belongs to it, so it goes with the account.
        $owner->delete();

        expect(WorkspaceInvitation::query()->count())->toBe(0)->and(Workspace::query()->whereKey($workspace->id)->exists())->toBeFalse();
    });

    it('stay when the account that sent them is deleted, and are then sent by nobody', function (): void {
        $team = inventoryTeam();
        $invitation = app(Invitations::class)->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);
        members()->transferOwnership($team['owner'], $team['workspace'], $team['editor']);

        $team['owner']->delete();

        expect($invitation->fresh()->invited_by_id)->toBeNull()
            ->and(app(Invitations::class)->sent($team['editor'], $team['workspace']))->toHaveCount(1);
    });

    it('stay when the invited account is deleted: they are addressed to the address, and a new account can accept them', function (): void {
        $team = inventoryTeam();
        $first = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        $invitation = app(Invitations::class)->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Editor);
        $first->delete();

        $second = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.test']);
        app(Invitations::class)->accept($second, $invitation);

        expect(roleWord($second, $team['workspace']))->toBe('editor')->and(WorkspaceInvitation::query()->count())->toBe(0);
    });

    it('stay when an administrator deletes the owner, whose workspace goes to a member', function (): void {
        $team = inventoryTeam();
        $administrator = User::factory()->admin()->withPersonalWorkspace()->create();
        $invitation = app(Invitations::class)->invite($team['owner'], $team['workspace'], 'ada@example.test', WorkspaceRole::Viewer);

        app(UserAdministration::class)->delete($administrator, $team['owner']);

        expect($invitation->fresh()->invited_by_id)->toBeNull()
            ->and(roleWord($team['editor'], $team['workspace']))->toBe('owner')
            ->and(WorkspaceMember::query()->where('workspace_id', $team['workspace']->id)->where('role', 'owner')->count())->toBe(1);
    });
});
