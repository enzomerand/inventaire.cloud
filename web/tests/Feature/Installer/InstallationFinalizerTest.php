<?php

declare(strict_types=1);

use App\Installer\CreateAdministrator;
use App\Installer\EnvironmentFile;
use App\Installer\EnvironmentLocks;
use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallationFinalizer;
use App\Installer\InstallationState;
use App\Installer\InstallerSettings;
use App\Installer\InstallStep;
use App\Installer\PostMigrationHook;
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
    $settings->persist(InstallStep::Administrator, ['name' => 'Jane Doe', 'email' => 'jane@example.test']);

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

    it('writes nothing but the marker when the whole configuration comes from the environment and .env cannot be written', function (): void {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('File permissions do not restrict root.');
        }

        // A container: every setting imposed except APP_ENV / APP_DEBUG, and a .env it cannot write.
        $this->processEnvironment = array_diff_key(containerEnvironment(), ['APP_ENV' => true, 'APP_DEBUG' => true]);
        $this->app->forgetInstance(EnvironmentLocks::class);
        $this->app->forgetInstance(InstallerSettings::class);
        $this->app->forgetInstance(InstallationFinalizer::class);
        $before = file_get_contents($this->sandbox->envPath());
        chmod($this->sandbox->envPath(), 0444);
        app()['env'] = 'local';
        config(['app.debug' => true]);

        try {
            expect(app(InstallationFinalizer::class)->needsProductionDefaults())->toBeFalse();

            app(InstallationFinalizer::class)->finalize();

            expect(app(InstallationState::class)->isInstalled())->toBeTrue()
                ->and(file_get_contents($this->sandbox->envPath()))->toBe($before);
        } finally {
            chmod($this->sandbox->envPath(), 0644);
        }
    });

    it('still applies the production defaults when the configuration comes from the environment but .env is writable', function (): void {
        // The environment imposes every setting except APP_ENV / APP_DEBUG, which live in a .env that can be written.
        $this->processEnvironment = array_diff_key(containerEnvironment(), ['APP_ENV' => true, 'APP_DEBUG' => true]);
        $this->app->forgetInstance(EnvironmentLocks::class);
        $this->app->forgetInstance(InstallerSettings::class);
        $this->app->forgetInstance(InstallationFinalizer::class);
        app()['env'] = 'local';
        config(['app.debug' => true]);

        expect(app(InstallationFinalizer::class)->needsProductionDefaults())->toBeTrue();

        app(InstallationFinalizer::class)->finalize();

        expect(app(InstallationState::class)->isInstalled())->toBeTrue()
            ->and((new EnvironmentFile($this->sandbox->envPath(), $this->sandbox->envPath()))->all())->toMatchArray(['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);
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

describe('the order of the finalization', function (): void {
    /**
     * A hook that records when it ran and what the installer looked like at that moment.
     */
    function recordingHook(array &$events): PostMigrationHook
    {
        return new class($events) implements PostMigrationHook
        {
            /** @param array<int, string> $events */
            public function __construct(private array &$events) {}

            public function run(): void
            {
                $this->events[] = 'hook: users table '.(Schema::hasTable('users') ? 'exists' : 'missing').', installed '.(app(InstallationState::class)->isInstalled() ? 'yes' : 'no');
            }
        };
    }

    /**
     * Finalizer wired with the given hooks, exactly like the container does.
     *
     * @param  list<PostMigrationHook>  $hooks
     */
    function finalizerWith(array $hooks): InstallationFinalizer
    {
        app()->forgetInstance(InstallationFinalizer::class);
        app()->when(InstallationFinalizer::class)->needs('$hooks')->give(fn (): array => $hooks);

        return app(InstallationFinalizer::class);
    }

    it('runs the post-migration hooks after the migrations and before the instance is marked installed', function (): void {
        readyInstance($this);
        wipeTestDatabase();
        $events = [];

        finalizerWith([recordingHook($events)])->finalize();

        expect($events)->toBe(['hook: users table exists, installed no'])
            ->and(app(InstallationState::class)->isInstalled())->toBeTrue();
    });

    it('registers hooks through the container tag', function (): void {
        readyInstance($this);
        $hook = new class implements PostMigrationHook
        {
            public function run(): void {}
        };
        app()->instance('test.hook', $hook);
        app()->tag(['test.hook'], PostMigrationHook::TAG);
        app()->forgetInstance(InstallationFinalizer::class);

        $hooks = (new ReflectionProperty(InstallationFinalizer::class, 'hooks'))->getValue(app(InstallationFinalizer::class));

        $registered = iterator_to_array($hooks, false);

        // The installer's own hook comes first, the one registered by the test after it.
        expect($registered)->toHaveCount(2)
            ->and($registered[0])->toBeInstanceOf(CreateAdministrator::class)
            ->and($registered[1])->toBe($hook);
    });

    it('stays uninstalled when a hook aborts, and can be run again', function (): void {
        readyInstance($this);
        wipeTestDatabase();
        $attempts = 0;
        $hook = new class($attempts) implements PostMigrationHook
        {
            public function __construct(private int &$attempts) {}

            public function run(): void
            {
                if (++$this->attempts === 1) {
                    throw new InstallationException('The administrator account could not be created.');
                }
            }
        };

        expect(fn () => finalizerWith([$hook])->finalize())->toThrow(InstallationException::class, 'administrator account');
        expect(app(InstallationState::class)->isInstalled())->toBeFalse()
            ->and(is_file(app(SetupToken::class)->path()))->toBeTrue()
            ->and(Schema::hasTable('users'))->toBeTrue();

        // Second attempt: the migrations have nothing left to do, the hook now succeeds.
        finalizerWith([$hook])->finalize();

        expect(app(InstallationState::class)->isInstalled())->toBeTrue()->and($attempts)->toBe(2);
    });

    it('clears the cached configuration the application is pointed at, never the real one', function (): void {
        readyInstance($this);
        $cache = app()->getCachedConfigPath();
        $real = base_path('bootstrap/cache/config.php');
        $realBefore = is_file($real) ? filemtime($real) : null;
        file_put_contents($cache, '<?php return [];');

        // phpunit.xml sets APP_CONFIG_CACHE: if it did not, this test would be deleting the real file.
        expect($cache)->not->toBe($real)->and($cache)->toStartWith($this->sandbox->root);

        finalizerWith([])->finalize();

        expect(is_file($cache))->toBeFalse()->and(is_file($real) ? filemtime($real) : null)->toBe($realBefore);
    });
});
