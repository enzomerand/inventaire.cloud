<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\Exceptions\LastWorkspaceException;
use App\Workspaces\Exceptions\WorkspaceLimitReachedException;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * The names of the workspaces of a user, in the order of their memberships, "*" marking the current one.
 *
 * @return list<string>
 */
function workspaceNames(User $user): array
{
    return array_map(
        fn ($membership): string => $membership->workspace->name.($membership->current ? '*' : ''),
        app(Workspaces::class)->memberships($user->refresh()),
    );
}

describe('creating', function (): void {
    it('makes the creator the owner and the workspace their current one', function (): void {
        $user = User::factory()->create();

        $workspace = app(Workspaces::class)->create($user, 'Shop');

        expect($workspace->name)->toBe('Shop')
            ->and(app(Workspaces::class)->roleOf($user, $workspace))->toBe(WorkspaceRole::Owner)
            ->and($user->fresh()->current_workspace_id)->toBe($workspace->id);
    });

    it('switches the current workspace to the new one each time', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();

        app(Workspaces::class)->create($user, 'Shop');

        expect(workspaceNames($user))->toBe(['Espace personnel', 'Shop*']);
    });
});

describe('the current workspace', function (): void {
    it('is the one that was chosen', function (): void {
        $user = User::factory()->create();
        $first = app(Workspaces::class)->create($user, 'First');
        app(Workspaces::class)->create($user, 'Second');
        app(Workspaces::class)->makeCurrent($user, $first);

        expect(app(Workspaces::class)->current($user->fresh())->is($first))->toBeTrue()
            ->and(workspaceNames($user))->toBe(['First*', 'Second']);
    });

    it('falls back to the first membership when none was chosen', function (): void {
        $user = User::factory()->create();
        $first = app(Workspaces::class)->create($user, 'First');
        app(Workspaces::class)->create($user, 'Second');
        $user->forceFill(['current_workspace_id' => null])->save();

        expect(app(Workspaces::class)->current($user->fresh())->is($first))->toBeTrue();
    });

    it('falls back to the first membership when the chosen workspace is no longer one the user belongs to', function (): void {
        $user = User::factory()->create();
        $first = app(Workspaces::class)->create($user, 'First');
        $second = app(Workspaces::class)->create($user, 'Second');
        WorkspaceMember::query()->where('workspace_id', $second->id)->delete();

        expect($user->fresh()->current_workspace_id)->toBe($second->id)
            ->and(app(Workspaces::class)->current($user->fresh())->is($first))->toBeTrue();
    });

    it('is the personal workspace given again to an account that has none, once', function (): void {
        // An account that lost every membership (a row removed by hand, a restore, a bug): it is never left stuck.
        $user = User::factory()->create();

        $workspace = app(Workspaces::class)->current($user);

        expect($workspace?->name)->toBe('Espace personnel')
            ->and(app(Workspaces::class)->roleOf($user, $workspace))->toBe(WorkspaceRole::Owner)
            ->and($user->current_workspace_id)->toBe($workspace->id)
            ->and($user->fresh()->current_workspace_id)->toBe($workspace->id);

        expect(app(Workspaces::class)->current($user->fresh())?->is($workspace))->toBeTrue()
            ->and($user->workspaces()->count())->toBe(1);
    });

    it('is none only when the account itself is gone', function (): void {
        $user = User::factory()->create();
        DB::table('users')->delete();

        expect(app(Workspaces::class)->current($user))->toBeNull()
            ->and(Workspace::query()->count())->toBe(0);
    });

    it('does not write when it is already the current one', function (): void {
        $user = User::factory()->create();
        $workspace = app(Workspaces::class)->create($user, 'Shop');
        $stamp = $user->fresh()->updated_at;

        $this->travel(5)->minutes();
        app(Workspaces::class)->makeCurrent($user->fresh(), $workspace);

        expect($user->fresh()->updated_at->equalTo($stamp))->toBeTrue();
    });
});

describe('listing', function (): void {
    it('gives the role in each workspace, oldest membership first, and no workspace of anybody else', function (): void {
        $user = User::factory()->create();
        $other = User::factory()->withPersonalWorkspace()->create();
        $mine = app(Workspaces::class)->create($user, 'Mine');
        $shared = app(Workspaces::class)->create($other, 'Shared');
        $shared->members()->attach($user->id, ['role' => 'viewer']);
        app(Workspaces::class)->makeCurrent($user, $mine);

        $memberships = app(Workspaces::class)->memberships($user->refresh());

        expect(array_map(fn ($m) => [$m->workspace->name, $m->role, $m->current], $memberships))->toBe([
            ['Mine', WorkspaceRole::Owner, true],
            ['Shared', WorkspaceRole::Viewer, false],
        ]);
    });

    it('finds one membership, or none for a workspace the user does not belong to', function (): void {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = app(Workspaces::class)->create($user, 'Mine');
        $theirs = app(Workspaces::class)->create($other, 'Theirs');

        expect(app(Workspaces::class)->membership($user, $mine)?->role)->toBe(WorkspaceRole::Owner)
            ->and(app(Workspaces::class)->membership($user, $mine)?->current)->toBeTrue()
            ->and(app(Workspaces::class)->membership($user, $theirs))->toBeNull();
    });
});

describe('renaming', function (): void {
    it('changes the name', function (): void {
        $workspace = app(Workspaces::class)->create(User::factory()->create(), 'Shop');

        app(Workspaces::class)->rename($workspace, 'Warehouse');

        expect($workspace->fresh()->name)->toBe('Warehouse');
    });
});

describe('deleting', function (): void {
    it('removes the workspace and its memberships', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $shop = app(Workspaces::class)->create($user, 'Shop');

        app(Workspaces::class)->delete($user->refresh(), $shop);

        expect(Workspace::query()->whereKey($shop->id)->exists())->toBeFalse()
            ->and(WorkspaceMember::query()->where('workspace_id', $shop->id)->exists())->toBeFalse()
            ->and(workspaceNames($user))->toBe(['Espace personnel*']);
    });

    it('moves the user to their first remaining workspace when it was the current one', function (): void {
        $user = User::factory()->create();
        $first = app(Workspaces::class)->create($user, 'First');
        app(Workspaces::class)->create($user, 'Second');
        $third = app(Workspaces::class)->create($user, 'Third');

        app(Workspaces::class)->delete($user->refresh(), $third);

        expect($user->fresh()->current_workspace_id)->toBe($first->id)
            ->and(workspaceNames($user))->toBe(['First*', 'Second']);
    });

    it('keeps the current workspace when another one is deleted', function (): void {
        $user = User::factory()->create();
        $first = app(Workspaces::class)->create($user, 'First');
        $second = app(Workspaces::class)->create($user, 'Second');

        app(Workspaces::class)->delete($user->refresh(), $first);

        expect($user->fresh()->current_workspace_id)->toBe($second->id);
    });

    it('refuses to delete the last workspace of the user', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $only = $user->workspaces()->sole();

        expect(fn () => app(Workspaces::class)->delete($user, $only))->toThrow(LastWorkspaceException::class)
            ->and(Workspace::query()->whereKey($only->id)->exists())->toBeTrue();
    });

    it('counts the workspaces of other people in which the user is a member', function (): void {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $mine = app(Workspaces::class)->create($user, 'Mine');
        $theirs = app(Workspaces::class)->create($owner, 'Theirs');
        $theirs->members()->attach($user->id, ['role' => 'viewer']);

        app(Workspaces::class)->delete($user->refresh(), $mine);

        expect(workspaceNames($user))->toBe(['Theirs*']);
    });

    it('gives a personal workspace to a member who had no other one', function (): void {
        $owner = User::factory()->withPersonalWorkspace()->create();
        $guest = User::factory()->create();
        $shared = app(Workspaces::class)->create($owner, 'Shared');
        $shared->members()->attach($guest->id, ['role' => 'editor']);
        $guest->forceFill(['current_workspace_id' => $shared->id])->save();

        app(Workspaces::class)->delete($owner->refresh(), $shared);

        expect(workspaceNames($guest))->toBe(['Espace personnel*'])
            ->and($guest->fresh()->workspaces()->sole()->pivot->role)->toBe(WorkspaceRole::Owner);
    });

    it('leaves a member who has another workspace with it', function (): void {
        $owner = User::factory()->withPersonalWorkspace()->create();
        $guest = User::factory()->withPersonalWorkspace()->create();
        $shared = app(Workspaces::class)->create($owner, 'Shared');
        $shared->members()->attach($guest->id, ['role' => 'editor']);

        app(Workspaces::class)->delete($owner->refresh(), $shared);

        expect($guest->workspaces()->count())->toBe(1);
    });
});

describe('making sure an account has a workspace', function (): void {
    it('gives the personal workspace to an account that has none, and does nothing for one that has', function (): void {
        $bare = User::factory()->create();
        $settled = User::factory()->withPersonalWorkspace()->create();

        app(Workspaces::class)->ensure($bare);
        app(Workspaces::class)->ensure($bare);
        app(Workspaces::class)->ensure($settled);

        expect($bare->workspaces()->count())->toBe(1)
            ->and($bare->current_workspace_id)->toBe($bare->workspaces()->sole()->id)
            ->and($settled->workspaces()->count())->toBe(1);
    });

    it('does not fail on an account that was deleted in the meantime, and creates nothing', function (): void {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->delete();

        app(Workspaces::class)->ensure($user);

        expect(Workspace::query()->count())->toBe(0);
    });
});

describe('the number of workspaces of an account', function (): void {
    it('is capped, whoever created them', function (): void {
        config(['workspaces.max_per_user' => 3]);
        $user = User::factory()->withPersonalWorkspace()->create();
        $owner = User::factory()->withPersonalWorkspace()->create();
        app(Workspaces::class)->create($owner, 'Hers')->members()->attach($user->id, ['role' => 'viewer']);
        app(Workspaces::class)->create($user, 'Mine');

        expect(fn () => app(Workspaces::class)->create($user, 'One too many'))->toThrow(WorkspaceLimitReachedException::class)
            ->and($user->workspaces()->count())->toBe(3)
            ->and(Workspace::query()->where('name', 'One too many')->exists())->toBeFalse();
    });

    it('leaves the personal workspace of a new account out of the way', function (): void {
        config(['workspaces.max_per_user' => 1]);

        $user = User::factory()->withPersonalWorkspace()->create();

        expect($user->workspaces()->count())->toBe(1);
    });

    it('fails on an account deleted in the meantime, not on a foreign key', function (): void {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->delete();

        expect(fn () => app(Workspaces::class)->create($user, 'Shop'))->toThrow(ModelNotFoundException::class);
    });
});

describe('deleting under the locks', function (): void {
    it('fails on a workspace that another request deleted first', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $shop = app(Workspaces::class)->create($user, 'Shop');
        $stale = Workspace::query()->findOrFail($shop->id);
        app(Workspaces::class)->delete($user->refresh(), $shop);

        expect(fn () => app(Workspaces::class)->delete($user->refresh(), $stale))->toThrow(ModelNotFoundException::class);
    });

    it('refuses somebody who is no longer a member, as if the workspace did not exist', function (): void {
        $owner = User::factory()->withPersonalWorkspace()->create();
        $other = User::factory()->withPersonalWorkspace()->create();
        $shop = app(Workspaces::class)->create($owner, 'Shop');

        try {
            app(Workspaces::class)->delete($other, $shop);
        } catch (AuthorizationException $exception) {
            expect($exception->status())->toBe(404)
                ->and(Workspace::query()->whereKey($shop->id)->exists())->toBeTrue();

            return;
        }

        throw new RuntimeException('A stranger deleted the workspace.');
    });

    it('refuses a member who is no longer an owner', function (): void {
        $owner = User::factory()->withPersonalWorkspace()->create();
        $editor = User::factory()->withPersonalWorkspace()->create();
        $shop = app(Workspaces::class)->create($owner, 'Shop');
        $shop->members()->attach($editor->id, ['role' => 'editor']);

        expect(fn () => app(Workspaces::class)->delete($editor, $shop))->toThrow(AuthorizationException::class, 'Seul un propriétaire')
            ->and(Workspace::query()->whereKey($shop->id)->exists())->toBeTrue();
    });

    it('goes through the model, one workspace at a time, so that its events fire', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $shop = app(Workspaces::class)->create($user, 'Shop');
        $deleted = [];
        Event::listen('eloquent.deleted: '.Workspace::class, function (Workspace $workspace) use (&$deleted): void {
            $deleted[] = $workspace->id;
        });

        app(Workspaces::class)->delete($user->refresh(), $shop);

        expect($deleted)->toBe([$shop->id]);
    });

    it('gives a personal workspace to every member who had no other one, and none to the others', function (): void {
        $owner = User::factory()->withPersonalWorkspace()->create();
        $stranded = User::factory()->create();
        $settled = User::factory()->withPersonalWorkspace()->create();
        $shared = app(Workspaces::class)->create($owner, 'Shared');
        $shared->members()->attach($stranded->id, ['role' => 'viewer']);
        $shared->members()->attach($settled->id, ['role' => 'editor']);

        app(Workspaces::class)->delete($owner->refresh(), $shared);

        expect($stranded->workspaces()->count())->toBe(1)
            ->and($settled->workspaces()->count())->toBe(1)
            ->and($stranded->fresh()->current_workspace_id)->toBe($stranded->workspaces()->sole()->id);
    });
});
