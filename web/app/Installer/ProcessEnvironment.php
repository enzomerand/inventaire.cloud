<?php

declare(strict_types=1);

namespace App\Installer;

use RuntimeException;

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

    /**
     * The configuration file the process was told to use, if any.
     *
     * APP_ENVIRONMENT_FILE is a real environment variable (it has to be known
     * before any file is read, so it cannot live in the file it names). It
     * lets a container keep the file on a persistent volume, outside the
     * application folder. Unset or empty: Laravel keeps loading `.env` next to
     * the application.
     *
     * Beware of Laravel's own rule: when a file named like this one followed by
     * `.` and APP_ENV exists in the same folder (`.env.production` next to
     * `.env`), it is loaded instead. Keep such files out of that folder.
     *
     * @return string|null An absolute path, or null when the variable is not set.
     *
     * @throws RuntimeException When the value is not an absolute path: silently ignoring it would leave the instance on the wrong file.
     */
    public static function environmentFile(): ?string
    {
        $path = self::variables()['APP_ENVIRONMENT_FILE'] ?? '';

        if ($path === '') {
            return null;
        }

        if (! str_starts_with($path, '/')) {
            throw new RuntimeException('APP_ENVIRONMENT_FILE must be an absolute path, got "'.$path.'".');
        }

        return $path;
    }
}
