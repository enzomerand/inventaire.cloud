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
 * Laravel copies the .env values into the process environment too, so presence
 * alone proves nothing. A variable is imposed when it is set in the environment
 * and is either absent from the .env file or different from it.
 *
 * The .env contents are captured when this object is created, before the
 * request writes anything: once a step has rewritten the file, the process
 * environment still holds the old values and every written key would
 * otherwise look imposed.
 */
final class EnvironmentLocks
{
    /** @var array<string, string> */
    private readonly array $file;

    /** @var array<string, string> */
    private readonly array $process;

    /**
     * @param  Closure(): array<array-key, mixed>  $processEnvironment  Returns the environment variables (getenv in production).
     */
    public function __construct(EnvironmentFile $file, Closure $processEnvironment)
    {
        $this->file = $file->all();

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
        if (! array_key_exists($key, $this->process)) {
            return false;
        }

        return ! array_key_exists($key, $this->file) || $this->file[$key] !== $this->process[$key];
    }

    /**
     * The imposed value of a key, or null when it is not imposed.
     */
    public function value(string $key): ?string
    {
        return $this->isLocked($key) ? $this->process[$key] : null;
    }
}
