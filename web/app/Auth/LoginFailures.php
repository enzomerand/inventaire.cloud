<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Counts the wrong sign-in codes typed for an address over a sliding period.
 *
 * Each code tolerates a few wrong guesses ({@see LoginCodes::maxAttempts()}),
 * but a new code can always be requested, which would give those guesses back.
 * This count does not depend on the code: whatever was requested in between,
 * an address that collected too many wrong codes in the last hour accepts no
 * more until the oldest of them is an hour old.
 *
 * The failures are kept in the cache (the cache store the instance is
 * configured with), as the time of each. Emptying the cache forgets them,
 * which only ever gives guesses back to the attacker once; the lock on the
 * code's row makes the read-and-write sequence safe for one address.
 */
final class LoginFailures
{
    public function __construct(
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    /**
     * Whether the address has reached the limit and must wait.
     *
     * @param  string  $email  Address, already normalized.
     */
    public function exhausted(string $email): bool
    {
        return count($this->recent($email)) >= $this->limit();
    }

    /**
     * Seconds until the address may try again (at least one).
     *
     * @param  string  $email  Address, already normalized.
     */
    public function retryAfter(string $email): int
    {
        $recent = $this->recent($email);

        if ($recent === []) {
            return 1;
        }

        return max(1, min($recent) + $this->windowSeconds() - $this->now());
    }

    /**
     * Counts one more wrong code.
     *
     * @param  string  $email  Address, already normalized.
     */
    public function record(string $email): void
    {
        $times = $this->recent($email);
        $times[] = $this->now();

        // Only the latest ones matter to the limit: the list cannot grow without bound.
        $this->cache->put($this->key($email), array_slice($times, -$this->limit()), $this->windowSeconds());
    }

    /**
     * The failures of the last period, as timestamps, oldest first.
     *
     * @return list<int>
     */
    private function recent(string $email): array
    {
        $stored = $this->cache->get($this->key($email));
        $since = $this->now() - $this->windowSeconds();
        $recent = [];

        foreach (is_array($stored) ? $stored : [] as $time) {
            if (is_int($time) && $time > $since) {
                $recent[] = $time;
            }
        }

        return $recent;
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

    private function now(): int
    {
        return now()->getTimestamp();
    }
}
