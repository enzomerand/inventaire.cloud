<?php

declare(strict_types=1);

use App\Auth\LoginCodes;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\WorkspaceRole;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\CommitsDatabaseChanges;

/*
 * Several people signing up at the same moment, each with their own address, through the real HTTP stack, in separate
 * PHP processes against the real database.
 *
 * A single process cannot reproduce this. Giving a new account its personal workspace starts by reading the
 * memberships of an account that has none, and on MySQL and MariaDB (REPEATABLE READ) that locking read takes a gap
 * lock on the place where they would be; the memberships of two new accounts go to the same place, so each sign-up
 * waits for the other's gap when it inserts its own membership and the database aborts one of them (deadlock). The
 * sign-up is retried, and every one of them must succeed with exactly one personal workspace. PostgreSQL has no gap
 * locks: there the test checks the same outcome without the contention.
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
    $go = $directory.'/go-sign-up';
    @unlink($go);
    $environment = array_merge(getenv(), $_ENV, ['CACHE_STORE' => 'database']);
    $children = [];

    foreach ($emails as $number => $email) {
        $child = new Process([PHP_BINARY, base_path('tests/Support/verify-code.php'), $email, $codes[$email], '10.8.0.'.($number + 1), $go, 'Person '.($number + 1)], base_path(), $environment);
        $child->setTimeout(120);
        $child->start();
        $children[] = $child;
    }

    // Everybody boots first, then everybody signs up.
    $deadline = microtime(true) + 90;

    foreach ($children as $child) {
        while (! str_contains($child->getOutput(), "ready\n")) {
            if (! $child->isRunning() || microtime(true) > $deadline) {
                throw new RuntimeException('A child process did not get ready: '.$child->getOutput().$child->getErrorOutput());
            }

            usleep(5_000);
        }
    }

    touch($go);

    $answers = [];

    foreach ($children as $child) {
        $child->wait();

        if ($child->getExitCode() !== 0) {
            throw new RuntimeException('A child process failed: '.$child->getOutput().$child->getErrorOutput());
        }

        $results = array_values(array_filter(explode("\n", $child->getOutput()), fn (string $line): bool => str_starts_with($line, '{')));
        $answers[] = json_decode($results === [] ? '' : $results[array_key_last($results)], true, flags: JSON_THROW_ON_ERROR);
    }

    @unlink($go);

    return $answers;
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
