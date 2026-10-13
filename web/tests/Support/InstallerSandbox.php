<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

/**
 * A throwaway directory holding everything the installer touches during a test.
 *
 * The installer writes the .env file, its state files (marker, progress,
 * token), sessions, cache files and the local storage disk. Tests must never
 * change the real ones, so each test gets its own temporary directory and the
 * application is pointed at it before any provider registers.
 */
final class InstallerSandbox
{
    private function __construct(public readonly string $root) {}

    /**
     * Creates the temporary directory.
     */
    public static function create(): self
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'inventaire-tests-'.bin2hex(random_bytes(6));

        if (! mkdir($root, 0700, true)) {
            throw new RuntimeException("Cannot create the test sandbox {$root}.");
        }

        return new self($root);
    }

    /**
     * Redirects the application's writable paths to the sandbox and sets the
     * installation state. Called while the application bootstraps, after the
     * configuration is loaded and before the providers register.
     *
     * @param  bool  $installed  Whether the "installed" marker exists.
     * @param  bool  $appKey  Whether the configured application key is kept; false empties it.
     */
    public function apply(Application $app, bool $installed, bool $appKey): void
    {
        $config = $app->make('config');

        $config->set('installer.state_path', $this->statePath());
        $config->set('session.files', $this->root.'/sessions');
        $config->set('cache.stores.file.path', $this->root.'/cache');
        $config->set('filesystems.disks.local.root', $this->root.'/storage');

        if (! $appKey) {
            $config->set('app.key', '');
        }

        foreach (['sessions', 'cache', 'storage'] as $directory) {
            @mkdir($this->root.'/'.$directory, 0700, true);
        }

        $app->useEnvironmentPath($this->root);
        $app->loadEnvironmentFrom('.env');

        $marker = $this->statePath().'/installed.json';

        if ($installed) {
            @mkdir($this->statePath(), 0700, true);
            file_put_contents($marker, '{"installed_at":"2000-01-01T00:00:00+00:00"}');
        } else {
            @unlink($marker);
        }
    }

    /**
     * The sandbox's .env file (it does not exist until a test or the installer creates it).
     */
    public function envPath(): string
    {
        return $this->root.'/.env';
    }

    /**
     * The directory of the installer's state files.
     */
    public function statePath(): string
    {
        return $this->root.'/state';
    }

    /**
     * Deletes the sandbox, restoring permissions tests may have removed.
     */
    public function destroy(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        @chmod($path, 0700);

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path.DIRECTORY_SEPARATOR.$entry);
            }
        }

        @rmdir($path);
    }
}
