<?php

declare(strict_types=1);

namespace App\Installer;

use Closure;

/**
 * Tells which settings are imposed by the process environment.
 *
 * With Docker or a process manager, configuration arrives as real environment
 * variables, which Laravel prefers over the .env file. A wizard that wrote such
 * a value to .env would appear to work but change nothing, so those values are
 * "locked": shown read-only and never written.
 *
 * A variable is imposed when it was in the environment the process started
 * with (see {@see ProcessEnvironment}), whatever the .env file says, even when
 * the value is identical.
 */
final class EnvironmentLocks
{
    /** @var array<string, string> */
    private readonly array $process;

    /**
     * @param  Closure(): array<array-key, mixed>  $processEnvironment  Returns the variables the process started with.
     */
    public function __construct(Closure $processEnvironment)
    {
        $process = [];

        foreach ($processEnvironment() as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $process[$key] = (string) $value;
            }
        }

        $this->process = $process;
    }

    /**
     * Whether the process environment imposes this key.
     */
    public function isLocked(string $key): bool
    {
        return array_key_exists($key, $this->process);
    }

    /**
     * The imposed value of a key, or null when it is not imposed.
     */
    public function value(string $key): ?string
    {
        return $this->process[$key] ?? null;
    }
}
