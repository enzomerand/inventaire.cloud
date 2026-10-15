<?php

declare(strict_types=1);

namespace App\Database;

use Closure;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;

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
     * Runs the work, and runs it again (after a pause) while it fails with a concurrency error, up to the attempts.
     * Any other failure, and the last concurrency error, go through.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $work  Opens its own outermost transaction and leaves nothing behind when it fails.
     * @return TValue
     */
    public static function run(Closure $work): mixed
    {
        $detector = app(ConcurrencyErrorDetector::class);

        return retry(
            self::ATTEMPTS,
            $work,
            fn (int $attempt): int => random_int(1, 20) * $attempt,
            $detector->causedByConcurrencyError(...),
        );
    }
}
