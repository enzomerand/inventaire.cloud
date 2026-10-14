<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Installer\InstallationState;
use App\Installer\InstallerSettings;
use Illuminate\Console\Command;

/**
 * Tells a script what the instance needs: the Docker entrypoint chooses
 * between upgrading, installing by itself and starting the web wizard from it.
 *
 * It prints a single word and runs none of the installer's checks (no database,
 * mail or storage connection is attempted), so it is cheap and safe to repeat.
 */
final class InstallStatusCommand extends Command
{
    protected $signature = 'inventaire:install-status';

    protected $description = 'Print the install state for scripts: installed, environment (not installed, every setting comes from the environment) or wizard';

    /**
     * Prints `installed`; or, for an instance that is not installed yet,
     * `environment` when every setting it needs is provided by the environment
     * (`inventaire:install --from-env` can finish the job) and `wizard`
     * otherwise.
     */
    public function handle(InstallationState $state, InstallerSettings $settings): int
    {
        $this->line(match (true) {
            $state->isInstalled() => 'installed',
            $settings->isEnvironmentOnly() => 'environment',
            default => 'wizard',
        });

        return self::SUCCESS;
    }
}
