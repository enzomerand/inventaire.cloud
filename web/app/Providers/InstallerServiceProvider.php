<?php

declare(strict_types=1);

namespace App\Providers;

use App\Installer\ApplicationKey;
use App\Installer\DatabaseMigrator;
use App\Installer\EnvironmentFile;
use App\Installer\EnvironmentLocks;
use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallationState;
use App\Installer\InstallerSettings;
use App\Installer\PostMigrationHook;
use App\Installer\Probes\FilesystemStorageProbe;
use App\Installer\Probes\StorageProbe;
use App\Installer\ProcessEnvironment;
use App\Installer\SetupToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the installer and, while the instance is not installed, makes the
 * application runnable without a database or a complete .env.
 *
 * It must run in register(), not boot(): the session, cache and queue drivers
 * are read as soon as the first middleware needs them, and the application key
 * must exist before the first cookie is encrypted.
 */
final class InstallerServiceProvider extends ServiceProvider
{
    /**
     * Container key under which a startup failure is parked for the first
     * middleware to show (a provider cannot return a response, and cannot
     * translate a message yet).
     */
    public const STARTUP_FAILURE = 'installer.startup-failure';

    /**
     * Registers the installer services and, until the instance is installed, the not-installed runtime.
     */
    public function register(): void
    {
        $this->app->singleton(InstallationState::class, fn (Application $app): InstallationState => new InstallationState(
            $app->make('config')->string('installer.state_path'),
        ));

        $this->app->singleton(EnvironmentFile::class, fn (Application $app): EnvironmentFile => new EnvironmentFile(
            $app->make('config')->get('installer.env_file') ?? $app->environmentFilePath(),
            $app->basePath('.env.example'),
            $app->make(InstallationState::class)->path('env.backup'),
            $app->make(InstallationState::class)->path('env.lock'),
        ));

        $this->app->singleton(EnvironmentLocks::class, fn (): EnvironmentLocks => new EnvironmentLocks(
            ProcessEnvironment::variables(...),
        ));

        $this->app->singleton(InstallerSettings::class);
        $this->app->singleton(SetupToken::class);
        $this->app->when(DatabaseMigrator::class)->needs('$hooks')->giveTagged(PostMigrationHook::TAG);
        $this->app->bind(StorageProbe::class, FilesystemStorageProbe::class);
        $this->app->singleton(ApplicationKey::class);

        if ($this->app->make(InstallationState::class)->isInstalled()) {
            return;
        }

        // The not-installed runtime: a new instance has no database yet and the default session, cache and queue
        // drivers all live there, so they are swapped for ones that need nothing (files, jobs run inline). Nothing
        // is written to .env; the configured drivers apply again as soon as the instance is installed.
        $config = $this->app->make('config');
        $config->set('session.driver', 'file');
        $config->set('cache.default', 'file');
        $config->set('queue.default', 'sync');

        $this->ensureApplicationKey();
    }

    /**
     * Registers the rate limiters of the wizard.
     */
    public function boot(): void
    {
        RateLimiter::for('installer-token', fn (Request $request): Limit => Limit::perMinute(
            $this->app->make('config')->integer('installer.token_attempts_per_minute'),
        )->by((string) $request->ip()));

        // Each call sends an email to an address typed in the form: keep that from becoming a spam cannon.
        RateLimiter::for('installer-mail', fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->ip()));
    }

    /**
     * Generates the application key on the first web access.
     *
     * Console commands are left alone (`composer install` boots artisan before
     * .env is even copied): `inventaire:install` creates the key itself. The
     * unit-test environment is treated like the web so the behaviour can be
     * exercised.
     */
    private function ensureApplicationKey(): void
    {
        $key = $this->app->make(ApplicationKey::class);

        if (! $key->isMissing() || ($this->app->runningInConsole() && ! $this->app->runningUnitTests())) {
            return;
        }

        try {
            $key->ensure();
        } catch (InstallationException $exception) {
            $this->app->instance(self::STARTUP_FAILURE, $exception);
        }
    }
}
