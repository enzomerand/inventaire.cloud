<?php

declare(strict_types=1);

use App\Auth\LoginCodes;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\WorkspaceRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsDatabaseChanges;
use Tests\Support\ConcurrentChildren;

/*
 * Several people signing up at the same moment, each with their own address, through the real HTTP stack, in separate
 * PHP processes against the real database.
 *
 * A single process cannot reproduce this. Giving a new account its personal workspace used to start by reading the
 * memberships of an account that has none, and on MySQL and MariaDB (REPEATABLE READ) that locking read takes a gap
 * lock on the place where they would be; the memberships of two new accounts go to the same place, so each sign-up
 * waited for the other's gap when it inserted its own membership and the database aborted one of them (deadlock):
 * dozens of times per run, which the retries absorbed until, once in a while, they ran out. The workspace is now
 * inserted without that read (see WorkspacesServiceTest for the guard that does not depend on timing), and every
 * sign-up must succeed with exactly one personal workspace, without relying on a retry.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(fn () => removeSignUpRows());
afterEach(fn () => removeSignUpRows());

/**
 * Empties what these tests commit (they run on the test database only: see TestDatabaseGuard).
 */
function removeSignUpRows(): void
{
    DB::table('login_codes')->delete();
    DB::table('workspaces')->delete();
    DB::table('users')->delete();
    DB::table((string) config('cache.stores.database.table'))->delete();
}

/**
 * Signs several new people up at the same moment, each one from its own IP address (so that the per-IP throttle of
 * the endpoint stays out of the way), and returns what each one was answered.
 *
 * @param  list<string>  $emails  Addresses without an account, each with a pending code.
 * @param  array<string, string>  $codes  The code of each address.
 * @return list<array{status: int, code: string|null}>
 */
function signUpTogether(string $directory, array $emails, array $codes): array
{
    $commands = [];

    foreach ($emails as $number => $email) {
        $commands[] = [$email, $codes[$email], '10.8.0.'.($number + 1), 'Person '.($number + 1)];
    }

    return ConcurrentChildren::run($directory, base_path('tests/Support/verify-code.php'), $commands, ['CACHE_STORE' => 'database']);
}

describe('people signing up at the same moment', function (): void {
    it('all succeed, each with exactly one personal workspace', function (): void {
        foreach (range(1, 4) as $round) {
            $emails = array_map(fn (int $number): string => "person{$round}x{$number}@example.com", range(1, 8));
            $codes = [];

            foreach ($emails as $email) {
                $codes[$email] = app(LoginCodes::class)->issue($email);
            }

            $answers = signUpTogether($this->sandbox->root, $emails, $codes);

            // Every sign-up was answered "created" (not a server error, which a deadlock that is not retried gives)...
            expect(array_column($answers, 'status'))->toBe(array_fill(0, 8, 201))
                ->and(User::query()->count())->toBe(8)
                ->and(Workspace::query()->count())->toBe(8)
                ->and(WorkspaceMember::query()->count())->toBe(8);

            // ...and each person has one workspace, theirs alone, which they own and work in.
            foreach (User::query()->withCount('workspaces')->get() as $user) {
                $workspace = $user->workspaces()->sole();

                expect($user->workspaces_count)->toBe(1)
                    ->and($workspace->pivot->role)->toBe(WorkspaceRole::Owner)
                    ->and($user->current_workspace_id)->toBe($workspace->id)
                    ->and($workspace->members()->count())->toBe(1);
            }

            removeSignUpRows();
        }
    });
});
