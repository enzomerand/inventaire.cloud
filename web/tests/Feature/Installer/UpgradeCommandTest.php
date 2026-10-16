<?php

declare(strict_types=1);

use App\Console\ArtisanRunner;
use App\Console\ProcessArtisanRunner;
use App\Installer\PostMigrationHook;
use App\Inventories\SearchText;
use App\Settings\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/inventories.php';

/**
 * Replaces one of the framework's (or a package's) commands with a stand-in that
 * records its call: the real cache commands would write to, or wipe, the working
 * copy's own bootstrap/cache.
 *
 * @param  string  $name  The command to replace, e.g. `route:cache`.
 * @param  int  $exitCode  What the stand-in returns.
 * @param  string  $prints  What it prints.
 */
function standIn(ArrayObject $calls, string $name, int $exitCode = 0, string $prints = '', ?Throwable $throws = null): void
{
    $standIn = new class($calls, $name, $exitCode, $prints, $throws) extends Command
    {
        public function __construct(private readonly ArrayObject $calls, string $name, private readonly int $exitCode, private readonly string $prints, private readonly ?Throwable $throws)
        {
            $this->signature = $name;

            parent::__construct();
        }

        public function handle(): int
        {
            $this->calls[] = $this->getName();

            if ($this->prints !== '') {
                $this->line($this->prints);
            }

            return $this->throws instanceof Throwable ? throw $this->throws : $this->exitCode;
        }
    };

    Artisan::registerCommand($standIn);
}

beforeEach(function (): void {
    // The package caches (Blade icons) live in bootstrap/cache: never let a test reach them.
    $this->calls = new ArrayObject;
    standIn($this->calls, 'icons:clear');
});

it('refuses to run on an instance that is not installed', function (): void {
    $this->bootInstaller(installed: false);

    $this->artisan('inventaire:upgrade')
        ->expectsOutputToContain('The instance is not installed')
        ->assertExitCode(1);
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

it('does not run the post-migration hooks: they belong to the installation', function (): void {
    $runs = 0;
    $hook = new class($runs) implements PostMigrationHook
    {
        public function __construct(private int &$runs) {}

        public function run(): void
        {
            $this->runs++;
        }
    };
    app()->instance('test.hook', $hook);
    app()->tag(['test.hook'], PostMigrationHook::TAG);

    $this->artisan('inventaire:upgrade')->assertExitCode(0);

    expect($runs)->toBe(0);
});

it('fails when the migrations fail, and leaves the caches alone', function (): void {
    $driver = config('database.default');
    $password = config("database.connections.{$driver}.password");
    config(["database.connections.{$driver}.password" => 'wrong-password']);
    DB::purge();
    file_put_contents(app()->getCachedRoutesPath(), '<?php return [];');

    try {
        $this->artisan('inventaire:upgrade')
            ->expectsOutputToContain('The database migration failed')
            ->assertExitCode(1);
    } finally {
        // The cleanup that follows every installer test connects to the database: give it its password back.
        config(["database.connections.{$driver}.password" => $password]);
        DB::purge();
    }

    expect(is_file(app()->getCachedRoutesPath()))->toBeTrue();
});

it('migrates with the configuration as it is now, not as it was cached', function (): void {
    Artisan::call('db:wipe', ['--force' => true]);

    // The process booted from a cached configuration that holds an outdated database password.
    $driver = config('database.default');
    config(["database.connections.{$driver}.password" => 'outdated-password']);
    DB::purge();
    file_put_contents(app()->getCachedConfigPath(), '<?php return [];');
    app()->instance('config_loaded_from_cache', true);

    $this->artisan('inventaire:upgrade')->assertExitCode(0);

    expect(is_file(app()->getCachedConfigPath()))->toBeFalse()
        ->and(Schema::hasTable('users'))->toBeTrue();
});

it('clears the configuration, route, view and event caches and the packages\' ones, and only those', function (): void {
    $views = $this->sandbox->root.'/views';
    config(['view.compiled' => $views]);
    $caches = [app()->getCachedConfigPath(), app()->getCachedRoutesPath(), app()->getCachedEventsPath(), $views.'/compiled.php'];
    $kept = base_path('bootstrap/cache/services.php');
    $keptBefore = is_file($kept) ? filemtime($kept) : null;

    foreach ($caches as $cache) {
        file_put_contents($cache, '<?php return [];');
    }

    // phpunit.xml points the caches at the sandbox: otherwise this test would delete the real ones.
    foreach ($caches as $cache) {
        expect($cache)->toStartWith($this->sandbox->root);
    }

    cache()->put('upgrade-test', 'kept', 60);

    $this->artisan('inventaire:upgrade')->expectsOutputToContain('The caches were cleared.')->assertExitCode(0);

    foreach ($caches as $cache) {
        expect(is_file($cache))->toBeFalse($cache);
    }

    expect($this->calls->getArrayCopy())->toBe(['icons:clear'])
        ->and(cache()->get('upgrade-test'))->toBe('kept')
        ->and(is_file($kept) ? filemtime($kept) : null)->toBe($keptBefore);
});

describe('the derived data', function (): void {
    it('builds the search text again when its version changed and counts the items again, and says so', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');
        $name = fieldIn($inventory, 'text', 'Name');
        $item = itemIn($inventory, [$name->ulid => 'Électricité']);
        DB::table('items')->where('id', $item->id)->update(['search_text' => 'stale']);
        DB::table('inventories')->where('id', $inventory->id)->update(['items_count' => 9]);

        $this->artisan('inventaire:upgrade')
            ->expectsOutputToContain('The text the items are searched by was built again.')
            ->expectsOutputToContain('The item counter of 1 inventory was wrong and was corrected (see the log).')
            ->expectsOutputToContain('The instance is up to date.')
            ->assertExitCode(0);

        expect(DB::table('items')->where('id', $item->id)->value('search_text'))->toBe('electricite')
            ->and((int) DB::table('inventories')->where('id', $inventory->id)->value('items_count'))->toBe(1)
            ->and(DB::table('settings')->where('key', InstanceSettings::SEARCH_TEXT_VERSION)->value('value'))->toBe((string) SearchText::VERSION);
    });

    it('leaves a text alone when the version is unchanged, and says nothing when nothing was wrong', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');
        $name = fieldIn($inventory, 'text', 'Name');
        $item = itemIn($inventory, [$name->ulid => 'Électricité']);

        $this->artisan('inventaire:upgrade')->assertExitCode(0);
        DB::table('items')->where('id', $item->id)->update(['search_text' => 'stale']);

        $this->artisan('inventaire:upgrade')
            ->doesntExpectOutputToContain('was built again')
            ->doesntExpectOutputToContain('was wrong')
            ->expectsOutputToContain('The instance is up to date.')
            ->assertExitCode(0);

        expect(DB::table('items')->where('id', $item->id)->value('search_text'))->toBe('stale');

        // A new version of the form of the text: every text is built again.
        app(InstanceSettings::class)->recordSearchTextVersion(SearchText::VERSION - 1);

        $this->artisan('inventaire:upgrade')->expectsOutputToContain('The text the items are searched by was built again.')->assertExitCode(0);

        expect(DB::table('items')->where('id', $item->id)->value('search_text'))->toBe('electricite');
    });

    /**
     * Makes the write of the search text of one item fail, the way a full disk or a deadlock that goes on would.
     */
    function failSearchTextOf(int $itemId): void
    {
        DB::listen(function ($query) use ($itemId): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'search_text') && end($query->bindings) == $itemId) {
                throw new RuntimeException('The disk is full.');
            }
        });
    }

    it('warns of the inventories whose search text could not be built, logs them, and still succeeds', function (): void {
        $team = inventoryTeam();
        $tools = inventoryIn($team['workspace'], 'Tools');
        $colors = inventoryIn($team['workspace'], 'Colors');
        $name = fieldIn($tools, 'text', 'Name');
        $shade = fieldIn($colors, 'text', 'Shade');
        $tool = itemIn($tools, [$name->ulid => 'Électricité']);
        $color = itemIn($colors, [$shade->ulid => 'Rouge']);
        DB::table('items')->update(['search_text' => 'stale']);
        failSearchTextOf($tool->id);
        Log::spy();

        $this->artisan('inventaire:upgrade')
            ->expectsOutputToContain('The search text of the items of 1 inventory could not be built (see the log)')
            ->doesntExpectOutputToContain('was built again')
            ->expectsOutputToContain('The instance is up to date.')
            ->assertExitCode(0);

        // The other inventory was done; the version is not recorded, so the next upgrade builds everything again.
        expect(DB::table('items')->where('id', $color->id)->value('search_text'))->toBe('rouge')
            ->and(DB::table('items')->where('id', $tool->id)->value('search_text'))->toBe('stale')
            ->and(DB::table('settings')->where('key', InstanceSettings::SEARCH_TEXT_VERSION)->count())->toBe(0);

        Log::shouldHaveReceived('error')->once();
    });

    it('does not fail the upgrade when the derived data cannot be brought right at all: it warns, logs, and goes on', function (): void {
        inventoryIn(inventoryTeam()['workspace']);
        // The inventories cannot even be listed. The message of the exception names a value that must not reach the console.
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'select') && preg_match('/from [`"]inventories[`"]/', strtolower($query->sql)) === 1) {
                throw new RuntimeException('Lost the connection while reading jane@example.test.');
            }
        });
        Log::spy();

        $this->artisan('inventaire:upgrade')
            ->expectsOutputToContain('could not be brought right (RuntimeException, see the log)')
            ->doesntExpectOutputToContain('jane@example.test')
            ->expectsOutputToContain('The caches were cleared.')
            ->expectsOutputToContain('The instance is up to date.')
            ->assertExitCode(0);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'derived data')
            && $context['exception'] instanceof RuntimeException);
    });

    it('warns in French of what could not be brought right', function (): void {
        $this->useLanguage('fr');
        $tools = inventoryIn(inventoryTeam()['workspace']);
        $tool = itemIn($tools);
        DB::table('items')->update(['search_text' => 'stale']);
        failSearchTextOf($tool->id);
        Log::spy();

        $this->artisan('inventaire:upgrade')
            ->expectsOutputToContain("Le texte de recherche des articles de 1 inventaire n'a pas pu être construit (voir le journal)")
            ->assertExitCode(0);
    });

    it('speaks French', function (): void {
        $this->useLanguage('fr');
        $inventory = inventoryIn(inventoryTeam()['workspace']);
        DB::table('inventories')->where('id', $inventory->id)->update(['items_count' => 4]);

        $this->artisan('inventaire:upgrade')
            ->expectsOutputToContain('Le texte qui sert à rechercher les articles a été reconstruit.')
            ->expectsOutputToContain("Le compteur d'articles de 1 inventaire était faux et a été corrigé (voir le journal).")
            ->assertExitCode(0);
    });
});

describe('--optimize', function (): void {
    /**
     * A runner that records the commands it is given, and fails the ones listed (command => output).
     *
     * @param  array<string, string>  $failing
     */
    function recordingRunner(ArrayObject $commands, array $failing = []): ArtisanRunner
    {
        return new class($commands, $failing) implements ArtisanRunner
        {
            /** @param array<string, string> $failing */
            public function __construct(private readonly ArrayObject $commands, private readonly array $failing) {}

            public function run(string $command): array
            {
                $this->commands[] = $command;

                return isset($this->failing[$command]) ? [1, $this->failing[$command]] : [0, ''];
            }
        };
    }

    /** The commands `optimize` runs, in its order, plus the one the Blade icons package adds. */
    $commands = ['config:cache', 'event:cache', 'route:cache', 'view:cache', 'icons:cache'];

    it('rebuilds the caches, each in a process of its own, only when asked', function () use ($commands): void {
        $ran = new ArrayObject;
        app()->instance(ArtisanRunner::class, recordingRunner($ran));

        $this->artisan('inventaire:upgrade')->assertExitCode(0);

        expect($ran->getArrayCopy())->toBe([]);

        $this->artisan('inventaire:upgrade', ['--optimize' => true])->assertExitCode(0);

        expect($ran->getArrayCopy())->toBe($commands);
    });

    it('still rebuilds the caches when the derived data could not be brought right', function () use ($commands): void {
        $ran = new ArrayObject;
        app()->instance(ArtisanRunner::class, recordingRunner($ran));
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'select') && preg_match('/from [`"]inventories[`"]/', strtolower($query->sql)) === 1) {
                throw new RuntimeException('Lost the connection.');
            }
        });
        Log::spy();

        $this->artisan('inventaire:upgrade', ['--optimize' => true])
            ->expectsOutputToContain('could not be brought right')
            ->expectsOutputToContain('The instance is up to date.')
            ->assertExitCode(0);

        expect($ran->getArrayCopy())->toBe($commands);
    });

    it('reports a cache that cannot be rebuilt with its own error, goes on with the others and still succeeds', function () use ($commands): void {
        $ran = new ArrayObject;
        app()->instance(ArtisanRunner::class, recordingRunner($ran, ['route:cache' => 'Unable to prepare route [home] for serialization. Uses Closure.', 'view:cache' => 'The options for the "tabler" set don\'t have a prefix defined.']));

        $this->artisan('inventaire:upgrade', ['--optimize' => true])
            ->expectsOutputToContain('Unable to prepare route [home] for serialization')
            ->expectsOutputToContain('set don\'t have a prefix defined')
            ->expectsOutputToContain('The command route:cache failed')
            ->expectsOutputToContain('The command view:cache failed')
            ->expectsOutputToContain('The instance is up to date.')
            ->assertExitCode(0);

        expect($ran->getArrayCopy())->toBe($commands);
    });
});

describe('the process runner', function (): void {
    it('runs an artisan command in a child process and reports its output and exit code', function (): void {
        [$status, $output] = (new ProcessArtisanRunner)->run('inspire');

        expect($status)->toBe(0)->and($output)->not->toBe('');

        [$status, $output] = (new ProcessArtisanRunner)->run('no:such-command');

        expect($status)->not->toBe(0)->and($output)->toContain('no:such-command');
    });

    /**
     * A runner whose child is a shell script instead of artisan.
     *
     * @param  string  $script  The body of the script.
     * @param  int  $timeout  Seconds the child may take.
     */
    function runnerOfScript(string $directory, string $script, int $timeout = 30): ProcessArtisanRunner
    {
        $path = $directory.'/child.sh';
        file_put_contents($path, "#!/bin/sh\n".$script."\n");
        chmod($path, 0700);

        return new ProcessArtisanRunner(fn (string $command): Process => new Process([$path, $command], $directory, timeout: $timeout));
    }

    it('reports a child killed by the system as a failed run, with what it printed before', function (): void {
        [$status, $output] = runnerOfScript($this->sandbox->root, 'echo "compiling..."; kill -9 $$')->run('view:cache');

        expect($status)->toBe(1)
            ->and($output)->toContain('Stopped by signal 9')
            ->and($output)->toContain('ran out of memory')
            ->and($output)->toContain('compiling...');
    });

    it('reports a child that takes too long as a failed run', function (): void {
        [$status, $output] = runnerOfScript($this->sandbox->root, 'exec sleep 30', timeout: 1)->run('view:cache');

        expect($status)->toBe(1)->and($output)->toBe('Timed out after 1 seconds.');
    });

    it('reports a child that cannot be set up as a failed run', function (): void {
        $factory = fn (string $command): Process => new Process([PHP_BINARY, '-v'], $this->sandbox->root.'/missing-directory');

        [$status, $output] = (new ProcessArtisanRunner($factory))->run('view:cache');

        expect($status)->toBe(1)->and($output)->toContain('Could not start PHP:')->and($output)->toContain('missing-directory');
    });
});

it('speaks French', function (): void {
    $this->useLanguage('fr');

    $this->artisan('inventaire:upgrade')->expectsOutputToContain("L'instance est à jour.")->assertExitCode(0);
});
