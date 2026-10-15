<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
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

beforeEach(fn () => removeConcurrencyRows());
afterEach(fn () => removeConcurrencyRows());

describe('administrators acting at the same moment', function (): void {
    it('keep one administrator when two step down at the same moment', function (): void {
        foreach (range(1, 4) as $_) {
            $first = User::factory()->admin()->withPersonalWorkspace()->create();
            $second = User::factory()->admin()->withPersonalWorkspace()->create();

            $outcomes = actTogether($this->sandbox->root, [['admin-demote', $first->id, $first->id], ['admin-demote', $second->id, $second->id]]);

            expect($outcomes)->toBe(['demoted', 'refused:last_administrator'])
                ->and(User::query()->where('is_admin', true)->count())->toBe(1);

            removeConcurrencyRows();
        }
    });

    it('keep one administrator when two demote each other: the one who lost is no administrator any more', function (): void {
        foreach (range(1, 4) as $_) {
            $first = User::factory()->admin()->withPersonalWorkspace()->create();
            $second = User::factory()->admin()->withPersonalWorkspace()->create();

            $outcomes = actTogether($this->sandbox->root, [['admin-demote', $first->id, $second->id], ['admin-demote', $second->id, $first->id]]);

            expect($outcomes)->toBe(['demoted', 'refused:not_administrator'])
                ->and(User::query()->where('is_admin', true)->count())->toBe(1);

            removeConcurrencyRows();
        }
    });

    it('keep one administrator when two delete each other, without a deadlock', function (): void {
        foreach (range(1, 4) as $_) {
            $first = User::factory()->admin()->withPersonalWorkspace()->create();
            $second = User::factory()->admin()->withPersonalWorkspace()->create();

            $outcomes = actTogether($this->sandbox->root, [['admin-delete', $first->id, $second->id], ['admin-delete', $second->id, $first->id]]);

            // The one who comes second was deleted by the first: it is judged again under the locks, and is refused.
            expect($outcomes)->toBe(['deleted', 'refused:not_administrator'])
                ->and(User::query()->where('is_admin', true)->count())->toBe(1);

            removeConcurrencyRows();
        }
    });

    it('refuse what an administrator demoted meanwhile asks for', function (): void {
        foreach (range(1, 4) as $_) {
            $demoter = User::factory()->admin()->withPersonalWorkspace()->create();
            $demoted = User::factory()->admin()->withPersonalWorkspace()->create();
            $victim = User::factory()->withPersonalWorkspace()->create();

            $outcomes = actTogether($this->sandbox->root, [['admin-demote', $demoter->id, $demoted->id], ['admin-delete', $demoted->id, $victim->id]]);

            // Either the deletion came first, while its author still was an administrator, or it was refused.
            expect($outcomes)->toBeIn([['deleted', 'demoted'], ['demoted', 'refused:not_administrator']])
                ->and(User::query()->whereKey($victim->id)->exists())->toBe($outcomes === ['demoted', 'refused:not_administrator'])
                ->and(User::query()->whereKey($demoted->id)->value('is_admin'))->toBeFalse();

            removeConcurrencyRows();
        }
    });

    it('do not make sign-ups wait while they hold the locks of a verdict', function (): void {
        User::factory()->admin()->withPersonalWorkspace()->create();
        $member = User::factory()->withPersonalWorkspace()->create();

        // One process holds the locks of the administrators and of an account; another inserts an account meanwhile. A
        // lock on every row and gap the filter on is_admin passes (MySQL and MariaDB) would make that insert wait for
        // the other to finish, which it never does: it gives up when its lock wait times out.
        $signals = [$this->sandbox->root.'/go.locked', $this->sandbox->root.'/go.released'];
        array_map(fn (string $signal): bool => @unlink($signal), $signals);

        try {
            $answers = ConcurrentChildren::run($this->sandbox->root, base_path('tests/Support/concurrent-action.php'), [['hold-admin-lock', $member->id], ['insert-user']]);
        } finally {
            array_map(fn (string $signal): bool => @unlink($signal), $signals);
        }

        expect($answers[0]['result'])->toBe('held')
            ->and($answers[1]['result'])->toBe('inserted')
            ->and(User::query()->where('email', 'newcomer@example.com')->exists())->toBeTrue();
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

            $outcomes = actTogether($this->sandbox->root, [['admin-delete', $admin->id, $owner->id], ['delete-account', $next->id]]);

            // Either the member left first (and the next in line took over), or the owner went first and the member,
            // now the only owner of a workspace shared with somebody, was told to hand it over.
            expect($outcomes)->toBeIn([['deleted', 'deleted'], ['deleted', 'refused:sole_owner_of_shared_workspaces']])
                ->and(User::query()->whereKey($owner->id)->exists())->toBeFalse()
                ->and(Workspace::query()->whereKey($shared->id)->exists())->toBeTrue()
                ->and(WorkspaceMember::query()->where('workspace_id', $shared->id)->where('role', WorkspaceRole::Owner->value)->count())->toBe(1)
                ->and(User::query()->whereDoesntHave('workspaces')->count())->toBe(0);

            removeConcurrencyRows();
        }
    });
});
