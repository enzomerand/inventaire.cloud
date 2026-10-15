<?php

declare(strict_types=1);

namespace App\Database;

use Closure;
use PDOException;
use Throwable;

/**
 * Runs a unit of work again when the database picks it as the loser of a deadlock, with a pause that differs from one
 * contender to the next.
 *
 * `transaction($callback, $attempts)` retries by itself, but with no pause: requests that collided retry at the same
 * instant and collide again, so with many of them at once the attempts run out before everybody got through. A short
 * random pause, longer at each attempt, spreads the retries out. A deadlock rolls the whole transaction back and
 * the database only tells the outermost one, so this wraps the outermost transaction (the work opens it, here), never
 * a nested one.
 */
final class DeadlockRetries
{
    /** How many times the work is tried in all. */
    public const ATTEMPTS = 5;

    /**
     * Runs the work, and runs it again (after a pause) while it fails as the loser of a deadlock, up to the attempts.
     * Any other failure, and the last deadlock, go through.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $work  Opens its own outermost transaction and leaves nothing behind when it fails.
     * @return TValue
     */
    public static function run(Closure $work): mixed
    {
        return retry(
            self::ATTEMPTS,
            $work,
            fn (int $attempt): int => random_int(1, 20) * $attempt,
            self::isDeadlock(...),
        );
    }

    /**
     * Whether the database aborted the work because it lost a deadlock, or because its snapshot was stale: running it
     * again then starts from what the winner committed.
     *
     * These are the errors worth a retry:
     *
     * - PostgreSQL `40P01` (deadlock detected);
     * - MySQL and MariaDB `1213` (deadlock found when trying to get lock);
     * - MariaDB `1020` (record has changed since last read in table): a locking read found a row committed after the
     *   snapshot of the transaction.
     *
     * The framework's own detector also counts a lock wait timeout (`1205`), which is not one: the lock is held by a
     * request that is slow or stuck (`innodb_lock_wait_timeout` is 50 seconds by default), and running the work again
     * would just wait as long again, five times over, before failing all the same.
     */
    public static function isDeadlock(Throwable $exception): bool
    {
        for ($error = $exception; $error !== null; $error = $error->getPrevious()) {
            if (! $error instanceof PDOException || ! is_array($error->errorInfo)) {
                continue;
            }

            $state = $error->errorInfo[0] ?? null;
            $code = (int) ($error->errorInfo[1] ?? 0);

            if ($state === '40P01' || ($state === '40001' && $code === 1213) || ($state === 'HY000' && $code === 1020)) {
                return true;
            }
        }

        return false;
    }
}
