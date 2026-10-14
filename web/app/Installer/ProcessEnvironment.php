<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * The environment variables the process was started with, taken before the
 * .env file is loaded.
 *
 * Laravel copies the values of .env into the process environment, so once the
 * application is running nothing tells a variable set by Docker or a process
 * manager from one that came from .env. Capturing the environment at the very
 * top of bootstrap/app.php, before dotenv reads anything, is the only moment
 * when it is certain every variable is "real": those are the ones the installer
 * must treat as imposed, even when their value happens to equal the one in .env.
 */
final class ProcessEnvironment
{
    /** @var array<string, string>|null */
    private static ?array $snapshot = null;

    /**
     * Records the environment, once: later calls (a second application booted
     * in the same process, as in tests) keep the first snapshot.
     */
    public static function capture(): void
    {
        if (self::$snapshot !== null) {
            return;
        }

        $variables = [];

        foreach ([getenv(), $_SERVER] as $source) {
            foreach ($source as $name => $value) {
                // HTTP_* entries of $_SERVER are request headers, which a visitor controls.
                if (is_string($name) && is_scalar($value) && ! str_starts_with($name, 'HTTP_')) {
                    $variables[$name] = (string) $value;
                }
            }
        }

        self::$snapshot = $variables;
    }

    /**
     * The captured variables.
     *
     * @return array<string, string>
     */
    public static function variables(): array
    {
        self::capture();

        return self::$snapshot ?? [];
    }
}
