<?php

declare(strict_types=1);

use App\Database\DeadlockRetries;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsDatabaseChanges;
use Tests\Support\ConcurrentChildren;

/*
 * Accounts and workspaces deleted at the same moment, in separate PHP processes against the real database.
 *
 * This is what a single process cannot reproduce. The rules "every workspace has a member", "every account belongs to
 * a workspace" and "an administrator remains" are judged by reading the state and then removing one's own part of
 * it; two requests that judge at the same time both see "somebody else is left" and break the rule together. They
 * are kept by the row locks of App\Workspaces\Locks, which these tests exercise on whichever engine runs the suite
 * (PostgreSQL, MySQL, MariaDB). Each scenario is repeated, as one lucky ordering proves nothing.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(fn () => removeConcurrencyRows());
afterEach(fn () => removeConcurrencyRows());

/**
 * Empties what these tests commit (they run on the test database only: see TestDatabaseGuard).
 */
function removeConcurrencyRows(): void
{
    DB::table('workspaces')->delete();
    DB::table('users')->delete();
}

/**
 * Runs actions in separate PHP processes that all start together, and returns what each one reported.
 *
 * @param  list<list<int|string>>  $commands  The arguments of each process after the go file: the action, then its ids.
 * @return list<array{result: string, reason?: string}>
 */
function actTogether(string $directory, array $commands): array
{
    return ConcurrentChildren::run($directory, base_path('tests/Support/concurrent-action.php'), $commands);
}

/**
 * What the processes reported, as sorted words ("deleted", "refused:last_administrator"...).
 *
 * @param  list<array{result: string, reason?: string}>  $answers
 * @return list<string>
 */
function outcomesOf(array $answers): array
{
    $words = array_map(fn (array $answer): string => $answer['result'].(isset($answer['reason']) ? ':'.$answer['reason'] : ''), $answers);
    sort($words);

    return $words;
}

describe('accounts deleted at the same moment', function (): void {
    it('leave no workspace without a member when two co-owners go together', function (): void {
        foreach (range(1, 4) as $_) {
            $ada = User::factory()->withPersonalWorkspace()->create();
            $grace = User::factory()->withPersonalWorkspace()->create();
            $shared = app(Workspaces::class)->create($ada, 'Shared');
            $shared->members()->attach($grace->id, ['role' => WorkspaceRole::Owner->value]);

            $answers = actTogether($this->sandbox->root, [['delete-account', $ada->id], ['delete-account', $grace->id]]);

            // Both go (each is an owner next to another owner when it is judged), and the workspace goes with the last one.
            expect(outcomesOf($answers))->toBe(['deleted', 'deleted'])
                ->and(User::query()->count())->toBe(0)
                ->and(Workspace::query()->count())->toBe(0);
        }
    });

    it('keep an administrator when two administrators go together, without a deadlock', function (): void {
        foreach (range(1, 4) as $_) {
            $first = User::factory()->admin()->withPersonalWorkspace()->create();
            $second = User::factory()->admin()->withPersonalWorkspace()->create();

            $answers = actTogether($this->sandbox->root, [['delete-account', $first->id], ['delete-account', $second->id]]);

            expect(outcomesOf($answers))->toBe(['deleted', 'refused:last_administrator'])
                ->and(User::query()->where('is_admin', true)->count())->toBe(1);

            DB::table('workspaces')->delete();
            DB::table('users')->delete();
        }
    });
});

describe('an account deleted by its owner', function (): void {
    it('is deleted again when the database picks the deletion as the loser of a deadlock', function (): void {
        // Committed rows and no transaction around the test: the deletion opens the outermost transaction, which is
        // the one that is retried (the other tests of the suite run inside a transaction of their own).
        $user = User::factory()->withPersonalWorkspace()->create();
        $bystander = User::factory()->withPersonalWorkspace()->create();
        $tries = 0;

        // Registered after the listener that releases what goes with the account: its work is rolled back with the rest.
        User::deleting(function () use (&$tries): void {
            if (++$tries === 1) {
                throw lostDeadlock();
            }
        });

        expect(DB::transactionLevel())->toBe(0)
            ->and($user->delete())->toBeTrue()
            ->and($tries)->toBe(2)
            ->and(User::query()->pluck('id')->all())->toBe([$bystander->id])
            ->and(Workspace::query()->count())->toBe(1);
    });

    it('gives up on the deadlock that does not go away, and leaves everything as it was', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $tries = 0;

        User::deleting(function () use (&$tries): void {
            $tries++;

            throw lostDeadlock();
        });

        expect(fn () => $user->delete())->toThrow(QueryException::class)
            ->and($tries)->toBe(DeadlockRetries::ATTEMPTS)
            ->and(User::query()->whereKey($user->id)->exists())->toBeTrue()
            ->and(Workspace::query()->count())->toBe(1);
    });
});

describe('workspaces deleted at the same moment', function (): void {
    it('do not leave two people without any workspace when each deletes a different one they share', function (): void {
        foreach (range(1, 4) as $_) {
            // No personal workspace: the two shared ones are all they have.
            $ada = User::factory()->create();
            $grace = User::factory()->create();
            $one = app(Workspaces::class)->create($ada, 'One');
            $one->members()->attach($grace->id, ['role' => WorkspaceRole::Owner->value]);
            $two = app(Workspaces::class)->create($grace, 'Two');
            $two->members()->attach($ada->id, ['role' => WorkspaceRole::Owner->value]);

            $answers = actTogether($this->sandbox->root, [['delete-workspace', $ada->id, $one->id], ['delete-workspace', $grace->id, $two->id]]);

            // Whoever came second found that the other workspace was their last.
            expect(outcomesOf($answers))->toBe(['deleted', 'refused:last_workspace'])
                ->and(Workspace::query()->count())->toBe(1)
                ->and(User::query()->whereDoesntHave('workspaces')->count())->toBe(0);

            DB::table('workspaces')->delete();
            DB::table('users')->delete();
        }
    });

    it('do not strand a member who deletes their account while the owner deletes the shared workspace', function (): void {
        foreach (range(1, 4) as $_) {
            $owner = User::factory()->withPersonalWorkspace()->create();
            $guest = User::factory()->create();
            $shared = app(Workspaces::class)->create($owner, 'Shared');
            $shared->members()->attach($guest->id, ['role' => WorkspaceRole::Editor->value]);

            $answers = actTogether($this->sandbox->root, [['delete-workspace', $owner->id, $shared->id], ['delete-account', $guest->id]]);

            // The guest's account is deleted, and the workspace too, in whichever order they were judged; and nothing
            // failed on a foreign key when the owner's side gave the guest a personal workspace for an account that was gone.
            expect(outcomesOf($answers))->toBe(['deleted', 'deleted'])
                ->and(User::query()->whereKey($guest->id)->exists())->toBeFalse()
                ->and(Workspace::query()->whereKey($shared->id)->exists())->toBeFalse()
                ->and(Workspace::query()->whereDoesntHave('members')->count())->toBe(0)
                ->and(User::query()->whereDoesntHave('workspaces')->count())->toBe(0);

            DB::table('workspaces')->delete();
            DB::table('users')->delete();
        }
    });
});
