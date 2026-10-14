<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

/**
 * A throwaway folder holding everything the installer touches during a test.
 *
 * The installer writes the .env file, its state files (marker, progress,
 * token), the cached configuration, sessions, cache files and the local
 * storage disk. Tests must never change the real ones, so all of them live in
 * one folder below storage/framework/testing, which phpunit.xml points the
 * installer at through plain environment variables (INSTALLER_STATE_PATH,
 * INSTALLER_ENV_FILE, APP_CONFIG_CACHE): the application boots against the
 * sandbox with no special bootstrapping. The folder is emptied at the start and
 * at the end of every test.
 *
 * The settings that are read lazily (sessions, cache, disk, logs) are redirected
 * after the application has booted, see {@see self::configure()}.
 */
final class InstallerSandbox
{
    /** Where the sandbox lives, relative to the application; keep in sync with phpunit.xml. */
    private const DIRECTORY = 'storage/framework/testing/installer';

    private function __construct(public readonly string $root) {}

    /**
     * Creates the sandbox: an empty folder.
     */
    public static function create(): self
    {
        $sandbox = new self(dirname(__DIR__, 2).'/'.self::DIRECTORY);

        $sandbox->destroy();

        if (! mkdir($sandbox->root, 0700, true) && ! is_dir($sandbox->root)) {
            throw new RuntimeException("Cannot create the test sandbox {$sandbox->root}.");
        }

        foreach (['sessions', 'cache', 'storage', 'views'] as $directory) {
            mkdir($sandbox->root.'/'.$directory, 0700);
        }

        return $sandbox;
    }

    /**
     * Sets the installation state before the application boots: the provider
     * decides whether the instance is installed when it registers.
     *
     * @param  bool  $installed  Whether the "installed" marker exists.
     */
    public function prepare(bool $installed): void
    {
        $marker = $this->statePath().'/installed.json';

        if ($installed) {
            @mkdir($this->statePath(), 0750, true);
            file_put_contents($marker, '{"installed_at":"2000-01-01T00:00:00+00:00"}');
        } else {
            @unlink($marker);
        }
    }

    /**
     * Redirects what the application reads lazily to the sandbox. Called once
     * the application has booted.
     */
    public function configure(Application $app): void
    {
        $config = $app->make('config');

        $config->set('session.files', $this->root.'/sessions');
        $config->set('cache.stores.file.path', $this->root.'/cache');
        $config->set('filesystems.disks.local.root', $this->root.'/storage');
        // The setup token is logged on the first web access: keep test tokens out of the real log files.
        $config->set('logging.default', 'null');
        $config->set('logging.channels.installer.path', $this->root.'/installer.log');
    }

    /**
     * The sandbox's .env file (it does not exist until a test or the installer creates it).
     */
    public function envPath(): string
    {
        return $this->root.'/.env';
    }

    /**
     * The folder of the installer's state files.
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
