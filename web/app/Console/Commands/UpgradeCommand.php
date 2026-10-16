<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\ArtisanRunner;
use App\Installer\DatabaseMigrator;
use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallationState;
use App\Inventories\DerivedData;
use App\Inventories\Templates\TemplateCatalogue;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

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
 *    serving the previous version, and forgets the verdict on the inventory
 *    templates (the one entry of the application cache that is judged by
 *    code: see {@see TemplateCatalogue});
 * 4. brings the derived data right ({@see DerivedData}): the text the items are
 *    searched by is built again when the way it is made changed (or for the
 *    items that have none), and the counters of items and the storage counters
 *    of workspaces are counted again (a failure here is reported but does not
 *    fail the upgrade);
 * 5. with `--optimize`, rebuilds the caches.
 *
 * It can be run as often as needed. The exit code is 0 when the instance is up
 * to date, so a container entrypoint or a deployment script can rely on it. A
 * cache that cannot be rebuilt does not make the upgrade fail (the application
 * simply runs without that cache), and neither does derived data that cannot be
 * brought right (the next upgrade tries again): both are reported as warnings.
 * A container that restarts on a failing upgrade would otherwise never start for
 * a fault that stops nothing from working.
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

        $this->deriveData();

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
     * such as rate limiters, not compiled code. The one entry of it that is: the
     * verdict on the template files, which the new version of the code may
     * judge differently without saying so, is forgotten (and only that).
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

        $this->forgetTemplates();

        $this->components->info(__('The caches were cleared.'));

        return true;
    }

    /**
     * Forgets the verdict on the inventory templates kept in the application cache. A cache that cannot be reached does not
     * make the upgrade fail: the templates are judged again by the next request that finds nothing, or after a day.
     */
    private function forgetTemplates(): void
    {
        try {
            $this->laravel->make(TemplateCatalogue::class)->forget();
        } catch (Throwable $exception) {
            Log::warning('The verdict on the inventory templates could not be forgotten by the upgrade.', ['exception' => $exception]);

            $this->components->warn(__('The inventory templates could not be judged again (:error, see the log): a changed template may take up to a day to show.', ['error' => $exception::class]));
        }
    }

    /**
     * Builds again the search text of the items when it must be, counts the items of every inventory and adds up the files of
     * every workspace. It runs with
     * the code and the schema of the version just installed, which the migrations cannot (they must not call the
     * application). What it did is reported, and a counter that was wrong as a warning: the cause is worth looking for.
     *
     * It never makes the upgrade fail, like the caches: the migrations are done and the application works without this
     * (searching may miss items, a counter may be off) until the next upgrade, so a failure is logged and reported as a
     * warning, and the rest of the upgrade goes on.
     */
    private function deriveData(): void
    {
        try {
            $derived = $this->laravel->make(DerivedData::class);
            $rebuild = $derived->rebuildSearchText();

            if ($rebuild->failed > 0) {
                $this->components->warn(trans_choice('The search text of the items of :count inventory could not be built (see the log): searching may miss some of them until the next upgrade.|The search text of the items of :count inventories could not be built (see the log): searching may miss some of them until the next upgrade.', $rebuild->failed));
            } elseif ($rebuild->everything) {
                $this->components->info(__('The text the items are searched by was built again.'));
            }

            $wrong = $derived->recountItems();

            if ($wrong > 0) {
                $this->components->warn(trans_choice('The item counter of :count inventory was wrong and was corrected (see the log).|The item counter of :count inventories was wrong and was corrected (see the log).', $wrong));
            }

            $wrongStorage = $derived->recountStorage();

            if ($wrongStorage > 0) {
                $this->components->warn(trans_choice('The storage counter of :count workspace was wrong and was corrected (see the log).|The storage counter of :count workspaces was wrong and was corrected (see the log).', $wrongStorage));
            }
        } catch (Throwable $exception) {
            Log::error('The derived data could not be brought right by the upgrade.', ['exception' => $exception]);

            // The class only: the message of a database error can quote the data it was writing.
            $this->components->warn(__('The search text and the item counters could not be brought right (:error, see the log): the instance works, but searching may miss items and a counter may be off. Fix the cause and run inventaire:upgrade again.', ['error' => $exception::class]));
        }
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
