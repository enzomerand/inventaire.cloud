<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Installer\DatabaseMigrator;
use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallationState;
use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider;

/**
 * Brings an installed instance up to date after its code was updated.
 *
 * Run it after every update (`git pull`, a new Docker image): it applies the
 * pending migrations, runs the post-migration hooks, and clears the caches
 * that could keep serving the previous version. With `--optimize` it then
 * rebuilds them. It can be run as often as needed: when everything is up to
 * date the migrations have nothing to do and the hooks, which are idempotent,
 * change nothing.
 *
 * The exit code is 0 only when every step succeeded, so a container entrypoint
 * or a deployment script can rely on it.
 */
final class UpgradeCommand extends Command
{
    protected $signature = 'inventaire:upgrade
        {--optimize : Rebuild the configuration, route, view and event caches afterwards}';

    protected $description = 'Apply the pending migrations and refresh the caches of an installed instance (run it after every update)';

    /**
     * Runs the upgrade.
     */
    public function handle(InstallationState $state, DatabaseMigrator $migrator): int
    {
        if (! $state->isInstalled()) {
            $this->components->error(__('The instance is not installed: nothing to upgrade. Install it first (php artisan inventaire:install, or open /install).'));

            return self::FAILURE;
        }

        try {
            $this->output->write($migrator->migrate());
            $hooks = $migrator->runHooks();
        } catch (InstallationException $exception) {
            $this->components->error($exception->userMessage());

            return self::FAILURE;
        }

        $this->components->info(__('Post-migration hooks run: :count.', ['count' => $hooks]));

        if (! $this->clearCaches()) {
            return self::FAILURE;
        }

        if ($this->option('optimize') && ! $this->runSilently('optimize')) {
            return self::FAILURE;
        }

        $this->components->info(__('The instance is up to date.'));

        return self::SUCCESS;
    }

    /**
     * Clears the caches that outlive an update: configuration, routes, views
     * and events, plus those packages register as part of `optimize`.
     *
     * The application cache (`cache:clear`) is left alone: it holds live data
     * such as rate limiters, not compiled code.
     *
     * @return bool Whether every cache was cleared.
     */
    private function clearCaches(): bool
    {
        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear', ...array_values(ServiceProvider::$optimizeClearCommands)] as $command) {
            if (! $this->runSilently($command)) {
                return false;
            }
        }

        $this->components->info(__('The caches were cleared.'));

        return true;
    }

    /**
     * Runs one of the framework's own commands without its output, and
     * reports a failure.
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
