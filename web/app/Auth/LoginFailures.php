<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Cache\RateLimiter;
use Illuminate\Config\Repository as Config;
use RuntimeException;

/**
 * Counts the wrong sign-in codes typed for an address over a period.
 *
 * Each code tolerates a few wrong guesses ({@see LoginCodes::maxAttempts()}),
 * but a new code can always be requested, which would give those guesses back.
 * This count does not depend on the code: whatever was requested in between,
 * an address that collected too many wrong codes in the period accepts no more
 * until the period is over.
 *
 * The period is a fixed one (one hour by default): it opens with the first wrong
 * code and ends one period later, then the count starts again from zero. A
 * sliding period cannot be counted atomically, and a count that is not atomic is
 * worthless here (see below).
 *
 * The count is kept in the cache (the cache store the rate limiter is configured
 * with) and every change of it is one atomic increment or decrement, so it holds
 * under parallel requests. It is never read to decide anything, because a plain
 * read can be stale inside a transaction (see {@see LoginCodes::lock()}): the
 * guess is paid for in advance ({@see self::reserve()}), and the answer to "may
 * this guess be judged?" is the value the increment returned. Emptying the cache
 * forgets the count, which gives the guesses back once.
 */
final class LoginFailures
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Config $config,
    ) {}

    /**
     * Takes one place in the budget of wrong codes of the address, before a guess is judged.
     *
     * It returns false, and takes nothing, when the budget is spent: the guess
     * must not be judged at all. Otherwise the place stays taken until
     * {@see self::release()} gives it back, which the caller does for whatever
     * turns out not to be a wrong code (the right one, a code that was already
     * burnt). Call it where the guesses of one address cannot overlap (the
     * caller holds the lock of the code's row): the count itself is atomic, but
     * a place given back later would be missing to a guess that came in between.
     *
     * @param  string  $email  Address, already normalized.
     *
     * @throws RuntimeException When the count cannot be written: refusing to judge is the only safe answer.
     */
    public function reserve(string $email): bool
    {
        $hits = $this->limiter->hit($this->key($email), $this->windowSeconds());

        // Not a count: the cache store could not increment (a "null" store, a value that is not a number...).
        // Letting the guess through would silently switch the limit off.
        if ($hits < 1) {
            throw new RuntimeException('The count of wrong sign-in codes could not be updated: check the cache store (CACHE_STORE).');
        }

        if ($hits > $this->limit()) {
            $this->release($email);

            return false;
        }

        return true;
    }

    /**
     * Gives back a place taken by {@see self::reserve()}.
     *
     * When nothing is left to count the period is over: the next wrong code
     * opens a new one.
     *
     * @param  string  $email  Address, already normalized.
     */
    public function release(string $email): void
    {
        if ($this->limiter->decrement($this->key($email), $this->windowSeconds()) <= 0) {
            $this->limiter->clear($this->key($email));
        }
    }

    /**
     * Seconds until the address may try again (at least one).
     *
     * The end of the period is read as is, which can be a little late for a
     * request that began before the period did: it then gets the length of a
     * whole period, the most that can be asked of it.
     *
     * @param  string  $email  Address, already normalized.
     */
    public function retryAfter(string $email): int
    {
        $wait = $this->limiter->availableIn($this->key($email));

        return max(1, $wait > 0 ? $wait : $this->windowSeconds());
    }

    /**
     * Wrong codes tolerated per period.
     */
    private function limit(): int
    {
        return (int) $this->setting('attempts');
    }

    /**
     * Length of the period, in seconds.
     */
    private function windowSeconds(): int
    {
        return (int) $this->setting('minutes') * 60;
    }

    /**
     * One value of `auth.login_codes.failures_per_email`.
     */
    private function setting(string $name): mixed
    {
        return $this->config->array('auth.login_codes.failures_per_email')[$name];
    }

    /**
     * Cache key of an address (hashed: the cache holds no addresses).
     */
    private function key(string $email): string
    {
        return 'login-failures:'.sha1($email);
    }
}
