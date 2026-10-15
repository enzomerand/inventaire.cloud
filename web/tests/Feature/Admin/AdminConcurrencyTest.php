<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsDatabaseChanges;
use Tests\Support\ConcurrentChildren;

/*
 * What administrators do at the same moment, in separate PHP processes against the real database (see
 * tests/Feature/Account/DeletionConcurrencyTest.php for why a single process cannot show it). "An administrator
 * remains" and "every workspace has an owner" are judged by reading the state and then changing one's own part of it;
 * the row locks of App\Workspaces\Locks make requests that collide judge one after the other, on every engine.
 * Each scenario is repeated, as one lucky ordering proves nothing.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(fn () => removeAdminRows());
afterEach(fn () => removeAdminRows());

/**
 * Empties what these tests commit (they run on the test database only: see TestDatabaseGuard).
 */
function removeAdminRows(): void
{
    DB::table('workspaces')->delete();
    DB::table('users')->delete();
}

/**
 * Runs actions in separate PHP processes that all start together, and returns what they reported as sorted words
 * ("deleted", "demoted", "refused:last_administrator"...).
 *
 * @param  list<list<int|string>>  $commands  The arguments of each process after the go file: the action, then its ids.
 * @return list<string>
 */
function adminActTogether(string $directory, array $commands): array
{
    $answers = ConcurrentChildren::run($directory, base_path('tests/Support/concurrent-action.php'), $commands);
    $words = array_map(fn (array $answer): string => $answer['result'].(isset($answer['reason']) ? ':'.$answer['reason'] : ''), $answers);
    sort($words);

    return $words;
}

describe('administrators acting at the same moment', function (): void {
    it('keep one administrator when two demote each other', function (): void {
        foreach (range(1, 4) as $_) {
            $first = User::factory()->admin()->withPersonalWorkspace()->create();
            $second = User::factory()->admin()->withPersonalWorkspace()->create();

            $outcomes = adminActTogether($this->sandbox->root, [['admin-demote', $second->id], ['admin-demote', $first->id]]);

            expect($outcomes)->toBe(['demoted', 'refused:last_administrator'])
                ->and(User::query()->where('is_admin', true)->count())->toBe(1);

            removeAdminRows();
        }
    });

    it('keep one administrator when two delete each other, without a deadlock', function (): void {
        foreach (range(1, 4) as $_) {
            $first = User::factory()->admin()->withPersonalWorkspace()->create();
            $second = User::factory()->admin()->withPersonalWorkspace()->create();

            $outcomes = adminActTogether($this->sandbox->root, [['admin-delete', $first->id, $second->id], ['admin-delete', $second->id, $first->id]]);

            expect($outcomes)->toBe(['deleted', 'refused:last_administrator'])
                ->and(User::query()->where('is_admin', true)->count())->toBe(1);

            removeAdminRows();
        }
    });

    it('never leave a shared workspace without an owner when its only owner is deleted while the member next in line deletes themselves', function (): void {
        foreach (range(1, 4) as $_) {
            $admin = User::factory()->admin()->withPersonalWorkspace()->create();
            $owner = User::factory()->withPersonalWorkspace()->create();
            $next = User::factory()->withPersonalWorkspace()->create();
            $last = User::factory()->withPersonalWorkspace()->create();
            $shared = app(Workspaces::class)->create($owner, 'Shared');
            $shared->members()->attach($next->id, ['role' => WorkspaceRole::Viewer->value]);
            $shared->members()->attach($last->id, ['role' => WorkspaceRole::Viewer->value]);

            $outcomes = adminActTogether($this->sandbox->root, [['admin-delete', $admin->id, $owner->id], ['delete-account', $next->id]]);

            // Either the member left first (and the next in line took over), or the owner went first and the member,
            // now the only owner of a workspace shared with somebody, was told to hand it over.
            expect($outcomes)->toBeIn([['deleted', 'deleted'], ['deleted', 'refused:sole_owner_of_shared_workspaces']])
                ->and(User::query()->whereKey($owner->id)->exists())->toBeFalse()
                ->and(Workspace::query()->whereKey($shared->id)->exists())->toBeTrue()
                ->and(WorkspaceMember::query()->where('workspace_id', $shared->id)->where('role', WorkspaceRole::Owner->value)->count())->toBe(1)
                ->and(User::query()->whereDoesntHave('workspaces')->count())->toBe(0);

            removeAdminRows();
        }
    });
});
