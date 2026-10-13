<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Exceptions\InstallationException;
use App\Installer\Exceptions\LockBusyException;
use App\Installer\Settings\DatabaseSettings;
use App\Installer\Support\FileLock;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Completes the installation once every required step is validated.
 *
 * It creates the tables, makes the instance leave development defaults when it
 * is not a development one, and only then writes the marker that turns the
 * installer off: a failure at any point leaves the instance not installed, so
 * the wizard can simply be run again.
 */
final class InstallationFinalizer
{
    /** Name of the connection the migrations run on. */
    private const CONNECTION = 'installer_migrate';

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
        private readonly DatabaseManager $db,
        private readonly Kernel $artisan,
    ) {}

    /**
     * Runs the migrations, hardens the environment and marks the instance installed.
     *
     * The migrations run on a connection built from the stored settings rather
     * than on the application's default one: in a web request the default
     * connection was configured before the wizard wrote the database settings.
     *
     * @return string Where to send the administrator next (the home page).
     *
     * @throws InstallationException When a step is missing or something fails; the instance stays uninstalled.
     */
    public function finalize(): string
    {
        // One finalization at a time: a second request (a double click, a script) must not migrate in parallel.
        try {
            return FileLock::exclusively($this->state->path(self::LOCK), fn (): string => $this->run(), 0);
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
    private function run(): string
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

        // Creating dozens of tables can outlast the default execution limit of a web request.
        @set_time_limit(300);

        $this->migrate();
        $this->leaveDevelopmentDefaults();
        $this->artisan->call('config:clear');

        $this->state->markInstalled();
        $this->token->forget();
        $this->state->forgetProgress();

        return route('home', absolute: false);
    }

    /**
     * Creates the tables on the configured database.
     *
     * @throws InstallationException When the migrations fail.
     */
    private function migrate(): void
    {
        $settings = DatabaseSettings::fromArray($this->settings->current(InstallStep::Database), $this->settings->providedUrl(InstallStep::Database));
        $base = $this->config->get('database.connections.'.$settings->driver);

        if (! is_array($base)) {
            throw new InstallationException('The database engine is not supported.');
        }

        $this->config->set('database.connections.'.self::CONNECTION, $settings->connectionConfig($base));
        $output = new BufferedOutput;

        try {
            $status = $this->artisan->call('migrate', [
                '--database' => self::CONNECTION,
                '--force' => true,
                '--no-interaction' => true,
                // Refuses (exit 1) when another migration is already running instead of silently doing nothing.
                '--isolated' => 1,
            ], $output);
        } catch (Throwable $exception) {
            throw new InstallationException('The database migration failed: :reason', ['reason' => $this->lastLine($exception->getMessage())]);
        } finally {
            $this->db->purge(self::CONNECTION);
            $this->config->offsetUnset('database.connections.'.self::CONNECTION);
        }

        if ($status !== 0) {
            throw new InstallationException('The database migration failed: :reason', ['reason' => $this->lastLine($output->fetch())]);
        }
    }

    /**
     * Switches a production instance out of debug mode.
     *
     * A development instance (`local`) keeps its settings, and nothing is
     * written when the instance already runs in production or when the
     * environment imposes the value.
     *
     * @throws InstallationException When .env cannot be written.
     */
    private function leaveDevelopmentDefaults(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        $updates = [];

        if (! $this->app->environment('production') && ! $this->locks->isLocked('APP_ENV')) {
            $updates['APP_ENV'] = 'production';
        }

        if ($this->config->boolean('app.debug') && ! $this->locks->isLocked('APP_DEBUG')) {
            $updates['APP_DEBUG'] = 'false';
        }

        if ($updates === []) {
            return;
        }

        if (! $this->environment->isWritable()) {
            throw new InstallationException('The .env file is not writable.');
        }

        $this->environment->set($updates);
    }

    /**
     * The last meaningful line of an error or of command output.
     */
    private function lastLine(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));

        return mb_substr($lines === [] ? '' : $lines[array_key_last($lines)], 0, 240);
    }
}
