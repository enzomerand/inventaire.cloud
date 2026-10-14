<?php

declare(strict_types=1);

use App\Auth\LoginCodes;
use App\Auth\LoginFailures;
use App\Models\LoginCode;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\CommitsDatabaseChanges;

/*
 * Wrong codes typed at the same moment from many visitors, through the real HTTP stack, in separate PHP processes
 * with the database cache store (the default of .env.example, and the one the unit-style tests do not exercise:
 * phpunit.xml uses the array store).
 *
 * This is the case a single process cannot reproduce. On MySQL and MariaDB a transaction reads from the snapshot
 * its first query opened; a request that then waits for the lock of the code's row reads the cache from that
 * snapshot, taken before the requests ahead of it committed. A count that is read and written back in separate
 * steps loses their updates, and the cap of ten wrong codes per address lets any number of parallel guesses through.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(fn () => removeCommittedRows());
afterEach(fn () => removeCommittedRows());

/**
 * Empties what these tests commit (they run on the test database only: see TestDatabaseGuard).
 */
function removeCommittedRows(): void
{
    DB::table('login_codes')->delete();
    DB::table('users')->delete();
    DB::table((string) config('cache.stores.database.table'))->delete();
}

/**
 * The failure count as the database cache store holds it (the processes under test write it there).
 */
function databaseFailures(): LoginFailures
{
    return new LoginFailures(new RateLimiter(Cache::store('database')), config());
}

/**
 * Types the same code for the same address from several PHP processes at once, each from its own IP address (so
 * that the per-IP throttle of the endpoint stays out of the way), and returns what each one was answered.
 *
 * @return list<array{status: int, code: string|null}>
 */
function typeCodeInParallel(string $directory, string $email, string $code, int $processes): array
{
    $go = $directory.'/go';
    @unlink($go);
    $environment = array_merge(getenv(), $_ENV, ['CACHE_STORE' => 'database']);
    $children = [];

    foreach (range(1, $processes) as $number) {
        $child = new Process([PHP_BINARY, base_path('tests/Support/verify-code.php'), $email, $code, "10.7.0.{$number}", $go], base_path(), $environment);
        $child->setTimeout(120);
        $child->start();
        $children[] = $child;
    }

    // Everybody boots first, then everybody starts typing.
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

        $lines = array_values(array_filter(explode("\n", trim($child->getOutput())), fn (string $line): bool => $line !== 'ready'));
        $answers[] = json_decode($lines[0] ?? '', true, flags: JSON_THROW_ON_ERROR);
    }

    return $answers;
}

/**
 * How many of the answers are this status and, when given, this `code`.
 *
 * @param  list<array{status: int, code: string|null}>  $answers
 */
function answersCount(array $answers, int $status, ?string $code = null): int
{
    return count(array_filter($answers, fn (array $answer): bool => $answer['status'] === $status && ($code === null || $answer['code'] === $code)));
}

describe('wrong codes typed in parallel', function (): void {
    it('are all counted, and the cap of ten holds', function (): void {
        $code = app(LoginCodes::class)->issue('ada@example.com');

        $answers = typeCodeInParallel($this->sandbox->root, 'ada@example.com', wrongCode($code), 16);

        // Ten guesses were judged (and counted), the six others found the address locked and were not judged at all.
        expect(answersCount($answers, 422, 'login_code_wrong'))->toBe(10)
            ->and(answersCount($answers, 429, 'login_code_locked'))->toBe(6)
            ->and(LoginCode::query()->findOrFail('ada@example.com')->attempts)->toBe(10)
            ->and(databaseFailures()->count('ada@example.com'))->toBe(10);
    });

    it('add up over the codes asked for in between', function (): void {
        $first = app(LoginCodes::class)->issue('ada@example.com');
        $answers = typeCodeInParallel($this->sandbox->root, 'ada@example.com', wrongCode($first), 6);

        expect(answersCount($answers, 422, 'login_code_wrong'))->toBe(6)
            ->and(databaseFailures()->count('ada@example.com'))->toBe(6);

        // A fresh code gives its own attempts back, not the six wrong codes already counted: four places are left.
        $second = app(LoginCodes::class)->issue('ada@example.com');
        $answers = typeCodeInParallel($this->sandbox->root, 'ada@example.com', wrongCode($second), 8);

        expect(answersCount($answers, 422, 'login_code_wrong'))->toBe(4)
            ->and(answersCount($answers, 429, 'login_code_locked'))->toBe(4)
            ->and(LoginCode::query()->findOrFail('ada@example.com')->attempts)->toBe(4)
            ->and(databaseFailures()->count('ada@example.com'))->toBe(10);
    });

    it('lock the right code too, however many ask at once', function (): void {
        $code = app(LoginCodes::class)->issue('ada@example.com');
        typeCodeInParallel($this->sandbox->root, 'ada@example.com', wrongCode($code), 10);

        $answers = typeCodeInParallel($this->sandbox->root, 'ada@example.com', $code, 4);

        expect(answersCount($answers, 429, 'login_code_locked'))->toBe(4)
            ->and(LoginCode::query()->count())->toBe(1);
    });
});
