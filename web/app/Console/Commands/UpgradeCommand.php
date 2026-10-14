<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\ArtisanRunner;
use App\Installer\DatabaseMigrator;
use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallationState;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Support\ServiceProvider;

/**
 * Brings an installed instance up to date after its code was updated.
 *
 * Run it after every update (`git pull`, a new Docker image). In this order it:
 *
 * 1. clears the cached configuration and reloads the configuration, so that
 *    the migrations use the settings as they are now (an edited `.env`, a new
 *    database password) and not the ones cached before;
 * 2. applies the pending migrations, under a file lock: if another upgrade is
 *    running, this one waits for it and then finds nothing to do;
 * 3. clears the route, view, event and package caches, which could keep
 *    serving the previous version;
 * 4. with `--optimize`, rebuilds the caches.
 *
 * The post-migration hooks are not run: they belong to the installation.
 *
 * It can be run as often as needed. The exit code is 0 when the instance is up
 * to date, so a container entrypoint or a deployment script can rely on it. A
 * cache that cannot be rebuilt does not make the upgrade fail (the application
 * simply runs without that cache): it is reported as a warning.
 */
final class UpgradeCommand extends Command
{
    protected $signature = 'inventaire:upgrade
        {--optimize : Rebuild the configuration, route, view and event caches afterwards}';

    protected $description = 'Apply the pending migrations and refresh the caches of an installed instance (run it after every update)';

    /**
     * Runs the upgrade.
     */
    public function handle(InstallationState $state): int
    {
        if (! $state->isInstalled()) {
            $this->components->error(__('The instance is not installed: nothing to upgrade. Install it first (php artisan inventaire:install, or open /install).'));

            return self::FAILURE;
        }

        if (! $this->refreshConfiguration()) {
            return self::FAILURE;
        }

        try {
            // Resolved only now: the migrator must hold the freshly loaded configuration.
            $this->output->write($this->laravel->make(DatabaseMigrator::class)->migrate());
        } catch (InstallationException $exception) {
            $this->components->error($exception->userMessage());

            return self::FAILURE;
        }

        if (! $this->clearCaches()) {
            return self::FAILURE;
        }

        if ($this->option('optimize')) {
            $this->optimize();
        }

        $this->components->info(__('The instance is up to date.'));

        return self::SUCCESS;
    }

    /**
     * Makes the process use the configuration as it is now.
     *
     * Clearing the cached configuration is not enough on its own: when this very
     * process booted from that cache, its settings are stale and the environment
     * file was never read, so both are loaded again.
     *
     * @return bool Whether the cached configuration could be cleared.
     */
    private function refreshConfiguration(): bool
    {
        $wasCached = $this->laravel->configurationIsCached();

        if (! $this->runSilently('config:clear')) {
            return false;
        }

        if ($wasCached) {
            // The framework remembers that this process booted from the cache, and then skips the environment file.
            $this->laravel->instance('config_loaded_from_cache', false);

            (new LoadEnvironmentVariables)->bootstrap($this->laravel);
            (new LoadConfiguration)->bootstrap($this->laravel);
        }

        return true;
    }

    /**
     * Clears the caches that outlive an update: routes, views and events, plus
     * those packages register as part of `optimize` (the configuration was
     * cleared before the migrations).
     *
     * The application cache (`cache:clear`) is left alone: it holds live data
     * such as rate limiters, not compiled code.
     *
     * @return bool Whether every cache was cleared.
     */
    private function clearCaches(): bool
    {
        foreach (['route:clear', 'view:clear', 'event:clear', ...array_values(ServiceProvider::$optimizeClearCommands)] as $command) {
            if (! $this->runSilently($command)) {
                return false;
            }
        }

        $this->components->info(__('The caches were cleared.'));

        return true;
    }

    /**
     * Rebuilds the caches, one command at a time, each in a process of its own
     * (see {@see ArtisanRunner}). They are the commands `optimize` runs, which
     * hides their errors and always succeeds.
     *
     * A failure is reported with the command's own output and never stops the
     * upgrade: the migrations are done, and the application works without a
     * cache.
     */
    private function optimize(): void
    {
        $runner = $this->laravel->make(ArtisanRunner::class);

        foreach (['config:cache', 'event:cache', 'route:cache', 'view:cache', ...array_values(ServiceProvider::$optimizeCommands)] as $command) {
            [$status, $output] = $runner->run($command);

            if ($status !== self::SUCCESS) {
                $this->line($output);
                $this->components->warn(__('The command :command failed, so that cache was not rebuilt: the instance works, only slower. Fix the cause above and run inventaire:upgrade --optimize again.', ['command' => $command]));
            }
        }
    }

    /**
     * Runs a command without its output, and reports a failure.
     *
     * @return bool Whether the command succeeded.
     */
    private function runSilently(string $command): bool
    {
        if ($this->callSilently($command) === self::SUCCESS) {
            return true;
        }

        $this->components->error(__('The command :command failed.', ['command' => $command]));

        return false;
    }
}
