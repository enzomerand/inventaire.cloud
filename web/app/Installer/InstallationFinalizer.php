<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Exceptions\InstallationException;
use App\Installer\Exceptions\LockBusyException;
use App\Installer\Settings\DatabaseSettings;
use App\Installer\Support\FileLock;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

/**
 * Completes the installation once every required step is validated.
 *
 * It creates the tables, runs what has to happen once they exist (the first
 * administrator), makes the instance leave development defaults when it is not
 * a development one, and only then writes the marker that turns the installer off: a failure at any point leaves the instance not installed, so
 * the wizard can simply be run again.
 */
final class InstallationFinalizer
{
    /** Lock held while finalizing, so that two requests cannot do it together. */
    private const LOCK = 'finalize.lock';

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly InstallationState $state,
        private readonly InstallerSettings $settings,
        private readonly EnvironmentFile $environment,
        private readonly EnvironmentLocks $locks,
        private readonly SetupToken $token,
        private readonly DatabaseMigrator $migrator,
        /** @var iterable<PostMigrationHook> */
        private readonly iterable $hooks = [],
    ) {}

    /**
     * Completes the installation, in this order: the migrations, the
     * {@see PostMigrationHook}s, the production defaults, and finally the
     * "installed" marker.
     *
     * The migrations and the hooks run on a connection built from the stored
     * settings rather than on the application's default one: in a web request
     * (and in the interactive console installer) the default connection was
     * configured before the wizard wrote the database settings, so it may point
     * at another database. On that connection, which is the default one for the
     * duration, a hook just uses `DB` or its models.
     *
     * @param  bool  $productionDefaults  Whether to set APP_ENV=production and APP_DEBUG=false. The web wizard always does; the console installer asks, and `--from-env` (the developer path) does not.
     * @return string Where to send the administrator next (the sign-in page, where the administrator signs in with a code sent to the address given during the installation).
     *
     * @throws InstallationException When a step is missing or something fails; the instance stays uninstalled.
     */
    public function finalize(bool $productionDefaults = true): string
    {
        // One finalization at a time: a second request (a double click, a script) must not migrate in parallel.
        try {
            return FileLock::exclusively($this->state->path(self::LOCK), fn (): string => $this->run($productionDefaults), 0);
        } catch (LockBusyException) {
            throw new InstallationException('The installation is already running.');
        }
    }

    /**
     * The finalization itself, under the lock.
     *
     * Safe to run again after a failure at any point: the migrations only apply
     * what is missing, and the marker that ends the installation comes last.
     *
     * @throws InstallationException
     */
    private function run(bool $productionDefaults): string
    {
        if ($this->state->isInstalled()) {
            throw new InstallationException('The instance is already installed.');
        }

        $missing = $this->state->missingRequiredSteps();

        if ($missing !== []) {
            throw new InstallationException('These steps must be completed first: :steps.', [
                'steps' => implode(', ', array_map(static fn (InstallStep $step): string => $step->title(), $missing)),
            ]);
        }

        // Creating dozens of tables can outlast the default execution limit of a web request. The limit is a timer of the whole process,
        // which goes on after the installation in a command or in a run of tests (the same process runs thousands of them): it is put back
        // as it was, so that it does not go off, or deliver a signal to whatever handles it, five minutes later.
        $limit = (int) ini_get('max_execution_time');
        @set_time_limit(300);

        try {
            // 1. The tables. 2. Whatever else needs them. 3. Settings. 4. The marker, which ends the installation: last.
            $this->migrator->onConnection($this->connection(), function (): void {
                $this->migrator->migrate();
                $this->runHooks();
            });

            if ($productionDefaults) {
                $this->applyProductionDefaults();
            }

            // A stale cache would keep serving the old settings. getCachedConfigPath() honours APP_CONFIG_CACHE, so tests clear their own file.
            @unlink($this->app->getCachedConfigPath());

            $this->state->markInstalled();
            $this->token->forget();
            $this->state->forgetProgress();
        } finally {
            @set_time_limit($limit);
        }

        return route('login', absolute: false);
    }

    /**
     * Runs the {@see PostMigrationHook}s, in registration order.
     *
     * @throws InstallationException When a hook aborts the installation.
     */
    private function runHooks(): void
    {
        foreach ($this->hooks as $hook) {
            $hook->run();
        }
    }

    /**
     * The configuration of the connection to the database being installed, built from the wizard's settings.
     *
     * @return array<string, mixed>
     *
     * @throws InstallationException When the database engine is not supported.
     */
    private function connection(): array
    {
        $settings = DatabaseSettings::fromArray($this->settings->current(InstallStep::Database), $this->settings->providedUrl(InstallStep::Database));
        $base = $this->config->get('database.connections.'.$settings->driver);

        if (! is_array($base)) {
            throw new InstallationException('The database engine is not supported.');
        }

        return $settings->connectionConfig($base);
    }

    /**
     * Whether the instance still runs with development settings that
     * {@see self::applyProductionDefaults()} would change.
     */
    public function needsProductionDefaults(): bool
    {
        return $this->productionUpdates() !== [];
    }

    /**
     * Switches the instance to production mode: APP_ENV=production and
     * APP_DEBUG=false.
     *
     * Debug mode shows stack traces, configuration and credentials to anybody
     * who triggers an error, so an instance that has just been installed must
     * not keep it. Values imposed by the environment are left alone (the
     * installer cannot change them), and nothing is written when the instance
     * already runs in production without debug.
     *
     * An instance configured only through the environment whose .env cannot be
     * written (typically a read-only container) is not touched either:
     * finalizing it writes nothing but the "installed" marker. When that .env
     * is writable the defaults are applied as usual.
     *
     * @throws InstallationException When .env cannot be written.
     */
    private function applyProductionDefaults(): void
    {
        $updates = $this->productionUpdates();

        if ($updates === []) {
            return;
        }

        if (! $this->environment->isWritable()) {
            throw new InstallationException('The .env file is not writable.');
        }

        $this->environment->set($updates, EnvironmentFile::NEW_FILE_DEFAULTS);
    }

    /**
     * The .env assignments needed to reach production defaults; none for an
     * instance configured only through the environment (see
     * {@see InstallerSettings::isEnvironmentOnly()}) whose .env cannot be
     * written: there is nothing to write them to, and the operator owns those
     * settings.
     *
     * @return array<string, string>
     */
    private function productionUpdates(): array
    {
        if ($this->settings->isEnvironmentOnly() && ! $this->environment->isWritable()) {
            return [];
        }

        $updates = [];

        if (! $this->app->environment('production') && ! $this->locks->isLocked('APP_ENV')) {
            $updates['APP_ENV'] = 'production';
        }

        if ($this->config->boolean('app.debug') && ! $this->locks->isLocked('APP_DEBUG')) {
            $updates['APP_DEBUG'] = 'false';
        }

        return $updates;
    }
}
