<?php

declare(strict_types=1);

use App\Installer\DatabaseMigrator;
use App\Installer\Exceptions\InstallationException;
use App\Installer\PostMigrationHook;
use Illuminate\Console\CacheCommandMutex;
use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the container hand these hooks to the migrator, as the tag does in production.
 *
 * @param  list<PostMigrationHook>  $hooks
 */
function upgradeWithHooks(array $hooks): void
{
    app()->when(DatabaseMigrator::class)->needs('$hooks')->give(fn (): array => $hooks);
}

/**
 * A hook that counts how many times it ran.
 */
function countingHook(int &$runs): PostMigrationHook
{
    return new class($runs) implements PostMigrationHook
    {
        public function __construct(private int &$runs) {}

        public function run(): void
        {
            $this->runs++;
        }
    };
}

/**
 * Replaces the framework's `optimize` command with one that only records the call:
 * the real one writes the route, event and view caches of this checkout.
 *
 * @param  array<int, string>  $calls
 */
function recordOptimizeCalls(array &$calls, int $exitCode = 0): void
{
    $recorder = new class($calls, $exitCode) extends Command
    {
        protected $signature = 'optimize';

        /** @param array<int, string> $calls */
        public function __construct(private array &$calls, private readonly int $exitCode)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            $this->calls[] = 'optimize';

            return $this->exitCode;
        }
    };

    Artisan::registerCommand($recorder);
}

it('refuses to run on an instance that is not installed', function (): void {
    $this->bootInstaller(installed: false);
    $runs = 0;
    upgradeWithHooks([countingHook($runs)]);

    $this->artisan('inventaire:upgrade')
        ->expectsOutputToContain('The instance is not installed')
        ->assertExitCode(1);

    expect($runs)->toBe(0);
});

it('applies the pending migrations', function (): void {
    Artisan::call('db:wipe', ['--force' => true]);

    expect(Schema::hasTable('users'))->toBeFalse();

    $this->artisan('inventaire:upgrade')->assertExitCode(0);

    expect(Schema::hasTable('users'))->toBeTrue()->and(Schema::hasTable('migrations'))->toBeTrue();
});

it('is a no-op the second time', function (): void {
    Artisan::call('db:wipe', ['--force' => true]);
    $this->artisan('inventaire:upgrade')->assertExitCode(0);
    $applied = DB::table('migrations')->pluck('migration')->all();

    $this->artisan('inventaire:upgrade')
        ->expectsOutputToContain('Nothing to migrate')
        ->assertExitCode(0);

    expect(DB::table('migrations')->pluck('migration')->all())->toBe($applied);
});

it('runs the post-migration hooks once per call, after the migrations', function (): void {
    Artisan::call('db:wipe', ['--force' => true]);
    $runs = 0;
    $sawUsersTable = null;
    upgradeWithHooks([countingHook($runs), new class($sawUsersTable) implements PostMigrationHook
    {
        public function __construct(private ?bool &$saw) {}

        public function run(): void
        {
            $this->saw = Schema::hasTable('users');
        }
    }]);

    $this->artisan('inventaire:upgrade')->expectsOutputToContain('Post-migration hooks run: 2.')->assertExitCode(0);

    expect($runs)->toBe(1)->and($sawUsersTable)->toBeTrue();

    $this->artisan('inventaire:upgrade')->assertExitCode(0);

    expect($runs)->toBe(2);
});

it('fails, without clearing anything, when a hook aborts', function (): void {
    $hook = new class implements PostMigrationHook
    {
        public function run(): void
        {
            throw new InstallationException('The administrator account could not be created.');
        }
    };
    upgradeWithHooks([$hook]);
    file_put_contents(app()->getCachedConfigPath(), '<?php return [];');

    $this->artisan('inventaire:upgrade')
        ->expectsOutputToContain('The administrator account could not be created.')
        ->assertExitCode(1);

    expect(is_file(app()->getCachedConfigPath()))->toBeTrue();
});

it('fails when the migrations fail, and runs no hook', function (): void {
    $driver = config('database.default');
    config(["database.connections.{$driver}.password" => 'wrong-password']);
    DB::purge();
    $runs = 0;
    upgradeWithHooks([countingHook($runs)]);

    $this->artisan('inventaire:upgrade')
        ->expectsOutputToContain('The database migration failed')
        ->assertExitCode(1);

    expect($runs)->toBe(0);
});

it('refuses to migrate while another migration is running', function (): void {
    $mutex = app(CacheCommandMutex::class);
    $migrate = app(MigrateCommand::class);

    expect($mutex->create($migrate))->toBeTrue();

    try {
        $this->artisan('inventaire:upgrade')->assertExitCode(1);
    } finally {
        $mutex->forget($migrate);
    }
});

it('clears the configuration, route, event and view caches, and only those', function (): void {
    $views = $this->sandbox->root.'/views';
    mkdir($views);
    config(['view.compiled' => $views]);
    $caches = [app()->getCachedConfigPath(), app()->getCachedRoutesPath(), app()->getCachedEventsPath(), $views.'/compiled.php'];
    $kept = base_path('bootstrap/cache/services.php');
    $keptBefore = is_file($kept) ? filemtime($kept) : null;

    foreach ($caches as $cache) {
        file_put_contents($cache, '<?php return [];');
    }

    // phpunit.xml points the caches at the sandbox: otherwise this test would delete the real ones.
    expect($caches[1])->toStartWith($this->sandbox->root)->and($caches[2])->toStartWith($this->sandbox->root);
    cache()->put('upgrade-test', 'kept', 60);

    $this->artisan('inventaire:upgrade')->expectsOutputToContain('The caches were cleared.')->assertExitCode(0);

    foreach ($caches as $cache) {
        expect(is_file($cache))->toBeFalse($cache);
    }

    expect(cache()->get('upgrade-test'))->toBe('kept')
        ->and(is_file($kept) ? filemtime($kept) : null)->toBe($keptBefore);
});

it('rebuilds the caches only with --optimize, after clearing them', function (): void {
    $calls = [];
    recordOptimizeCalls($calls);

    $this->artisan('inventaire:upgrade')->assertExitCode(0);

    expect($calls)->toBe([]);

    $this->artisan('inventaire:upgrade', ['--optimize' => true])->assertExitCode(0);

    expect($calls)->toBe(['optimize']);
});

it('fails when the optimization fails', function (): void {
    $calls = [];
    recordOptimizeCalls($calls, exitCode: 1);

    $this->artisan('inventaire:upgrade', ['--optimize' => true])
        ->expectsOutputToContain('The command optimize failed.')
        ->assertExitCode(1);
});

it('speaks French', function (): void {
    $this->useLanguage('fr');

    $this->artisan('inventaire:upgrade')->expectsOutputToContain("L'instance est à jour.")->assertExitCode(0);
});
