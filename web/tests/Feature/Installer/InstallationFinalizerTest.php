<?php

declare(strict_types=1);

use App\Installer\EnvironmentFile;
use App\Installer\EnvironmentLocks;
use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallationFinalizer;
use App\Installer\InstallationState;
use App\Installer\InstallerSettings;
use App\Installer\InstallStep;
use App\Installer\SetupToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Prepares a fresh instance whose wizard steps are all done, pointing at the test database.
 *
 * @param  array<string, mixed>  $database  Overrides of the database settings.
 */
function readyInstance(object $test, array $database = []): void
{
    $test->bootInstaller(installed: false);

    $settings = app(InstallerSettings::class);
    $state = app(InstallationState::class);
    $db = testDatabaseSettings($database);

    $settings->persist(InstallStep::Database, ['driver' => $db->driver, 'host' => $db->host, 'port' => $db->port, 'database' => $db->database, 'username' => $db->username, 'password' => $db->password]);

    foreach (InstallStep::required() as $step) {
        $state->record($step);
    }

    app(SetupToken::class)->ensure();
}

/**
 * Empties the test database so the migrations have to create everything.
 */
function wipeTestDatabase(): void
{
    Artisan::call('db:wipe', ['--force' => true]);
}

it('refuses to finalize while a required step is missing', function (): void {
    $this->bootInstaller(installed: false);
    app(InstallationState::class)->record(InstallStep::Database);

    try {
        app(InstallationFinalizer::class)->finalize();
    } catch (InstallationException $exception) {
        expect($exception->userMessage())->toContain('Application')->toContain('Email')->toContain('File storage')->not->toContain('Database');
        expect(app(InstallationState::class)->isInstalled())->toBeFalse();

        return;
    }

    $this->fail('Expected an InstallationException.');
});

it('does not require the optional Documenso step', function (): void {
    readyInstance($this);

    expect(app(InstallationState::class)->status(InstallStep::Documenso))->toBe('pending');
    wipeTestDatabase();

    app(InstallationFinalizer::class)->finalize();

    expect(app(InstallationState::class)->isInstalled())->toBeTrue();
});

it('refuses when the instance is already installed', function (): void {
    app(InstallationFinalizer::class)->finalize();
})->throws(InstallationException::class, 'already installed');

it('creates the tables, marks the instance installed and removes the token and progress', function (): void {
    readyInstance($this);
    wipeTestDatabase();
    $token = app(SetupToken::class);

    expect(Schema::hasTable('users'))->toBeFalse();

    $redirect = app(InstallationFinalizer::class)->finalize();

    $state = new InstallationState(config('installer.state_path'));

    expect($redirect)->toBe('/')
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('migrations'))->toBeTrue()
        ->and(Schema::hasTable('sessions'))->toBeTrue()
        ->and($state->isInstalled())->toBeTrue()
        ->and(is_file($token->path()))->toBeFalse()
        ->and(is_file($state->path('progress.json')))->toBeFalse();
});

it('runs on a connection of its own and leaves none behind', function (): void {
    readyInstance($this);
    wipeTestDatabase();

    app(InstallationFinalizer::class)->finalize();

    expect(config('database.connections.installer_migrate'))->toBeNull()
        ->and(array_keys(app('db')->getConnections()))->not->toContain('installer_migrate');
});

it('can be run again over an already migrated database', function (): void {
    readyInstance($this);

    app(InstallationFinalizer::class)->finalize();

    expect(Schema::hasTable('users'))->toBeTrue();
});

it('stays uninstalled when the migrations fail', function (): void {
    readyInstance($this, ['password' => 'wrong-password']);

    try {
        app(InstallationFinalizer::class)->finalize();
    } catch (InstallationException $exception) {
        expect($exception->userMessage())->toContain('migration failed');
        expect(app(InstallationState::class)->isInstalled())->toBeFalse()
            ->and(is_file(app(SetupToken::class)->path()))->toBeTrue()
            ->and(app(InstallationState::class)->status(InstallStep::Database))->toBe('done');

        return;
    }

    $this->fail('Expected an InstallationException.');
});

/**
 * The sandbox .env after finalizing an instance that runs in the given environment.
 *
 * @return array<string, string>
 */
function finalizeIn(object $test, string $environment, bool $debug = true, bool $productionDefaults = true): array
{
    app()['env'] = $environment;
    config(['app.debug' => $debug]);

    app(InstallationFinalizer::class)->finalize($productionDefaults);

    return (new EnvironmentFile($test->sandbox->envPath(), $test->sandbox->envPath()))->all();
}

describe('leaving development defaults', function (): void {
    beforeEach(function (): void {
        readyInstance($this);
        app(EnvironmentFile::class)->set(['APP_ENV' => 'staging', 'APP_DEBUG' => 'true']);
    });

    it('switches a non-local instance to production without debug', function (): void {
        expect(finalizeIn($this, 'staging'))->toMatchArray(['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);
    });

    it('switches a local instance too: the web wizard always leaves development mode', function (): void {
        expect(finalizeIn($this, 'local'))->toMatchArray(['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);
    });

    it('leaves the environment alone when asked to (the developer path)', function (): void {
        expect(finalizeIn($this, 'local', productionDefaults: false))->toMatchArray(['APP_ENV' => 'staging', 'APP_DEBUG' => 'true']);
    });

    it('says whether the instance still has development settings', function (): void {
        app()['env'] = 'local';
        config(['app.debug' => true]);
        expect(app(InstallationFinalizer::class)->needsProductionDefaults())->toBeTrue();

        app()['env'] = 'production';
        config(['app.debug' => false]);
        expect(app(InstallationFinalizer::class)->needsProductionDefaults())->toBeFalse();
    });

    it('only turns debug off when already in production', function (): void {
        expect(finalizeIn($this, 'production'))->toMatchArray(['APP_ENV' => 'staging', 'APP_DEBUG' => 'false']);
    });

    it('does not touch .env when production without debug is already in place', function (): void {
        $before = file_get_contents($this->sandbox->envPath());

        finalizeIn($this, 'production', false);

        expect(file_get_contents($this->sandbox->envPath()))->toBe($before);
    });

    it('never writes what the environment imposes', function (): void {
        // Docker imposes values that differ from the stale ones in .env.
        $this->processEnvironment = ['APP_ENV' => 'staging-docker', 'APP_DEBUG' => '1'];
        $this->app->forgetInstance(EnvironmentLocks::class);
        $this->app->forgetInstance(InstallerSettings::class);
        $this->app->forgetInstance(InstallationFinalizer::class);
        app()['env'] = 'staging-docker';
        config(['app.debug' => true]);

        app(InstallationFinalizer::class)->finalize();

        expect((new EnvironmentFile($this->sandbox->envPath(), $this->sandbox->envPath()))->all())->toMatchArray(['APP_ENV' => 'staging', 'APP_DEBUG' => 'true']);
    });
});
