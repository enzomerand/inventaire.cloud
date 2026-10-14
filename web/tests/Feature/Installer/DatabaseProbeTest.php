<?php

declare(strict_types=1);

use App\Installer\Probes\DatabaseProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * These tests talk to the real database of the engine the suite runs on
 * (composer test runs them on PostgreSQL, MySQL and MariaDB in turn).
 */

it('accepts a reachable database that only holds our tables', function (): void {
    $result = app(DatabaseProbe::class)->check(testDatabaseSettings());

    expect($result->ok)->toBeTrue()->and($result->message)->toBeNull()->and($result->warnings)->toBe([]);
});

it('leaves no temporary connection or table behind', function (): void {
    app(DatabaseProbe::class)->check(testDatabaseSettings());

    $leftovers = array_filter(Schema::getTableListing(schemaQualified: false), static fn (string $table): bool => str_starts_with($table, 'installer_probe'));

    expect(array_keys(DB::getConnections()))->not->toContain('installer_probe')->and($leftovers)->toBe([]);
});

it('explains an unreachable server and points at the host', function (): void {
    $result = app(DatabaseProbe::class)->check(testDatabaseSettings(['port' => 1]));

    expect($result->ok)->toBeFalse()->and($result->field)->toBe('host')->and($result->message)->toContain('Cannot connect');
});

it('points at the username when the credentials are refused', function (): void {
    $result = app(DatabaseProbe::class)->check(testDatabaseSettings(['password' => 'definitely-not-the-password']));

    expect($result->ok)->toBeFalse()->and($result->field)->toBe('username')
        ->and($result->message)->not->toContain('definitely-not-the-password');
});

it('points at the database name when it does not exist', function (): void {
    $result = app(DatabaseProbe::class)->check(testDatabaseSettings(['database' => 'inventaire_no_such_database']));

    expect($result->ok)->toBeFalse()->and($result->field)->toBe('database');
});

it('refuses a server older than the minimum version', function (): void {
    $driver = config('database.default');
    config(["installer.minimum_versions.{$driver}" => '99']);

    $result = app(DatabaseProbe::class)->check(testDatabaseSettings());

    expect($result->ok)->toBeFalse()->and($result->field)->toBe('host')->and($result->message)->toContain('99');
});

it('refuses a database that holds tables of another application', function (): void {
    Schema::create('foreign_application_table', static function ($table): void {
        $table->id();
    });

    try {
        $result = app(DatabaseProbe::class)->check(testDatabaseSettings());
    } finally {
        Schema::dropIfExists('foreign_application_table');
    }

    expect($result->ok)->toBeFalse()->and($result->field)->toBe('database')->and($result->message)->toContain('foreign_application_table');
});

it('rejects the MySQL driver for a MariaDB server and the other way round', function (): void {
    $driver = config('database.default');

    if ($driver === 'pgsql') {
        $this->markTestSkipped('Needs a MySQL or MariaDB server.');
    }

    $wrong = $driver === 'mysql' ? 'mariadb' : 'mysql';
    $result = app(DatabaseProbe::class)->check(testDatabaseSettings(['driver' => $wrong]));

    expect($result->ok)->toBeFalse()->and($result->field)->toBe('driver')
        ->and($result->message)->toContain($driver === 'mysql' ? 'MySQL' : 'MariaDB');
});

it('refuses an unsupported engine', function (): void {
    expect(app(DatabaseProbe::class)->check(testDatabaseSettings(['driver' => 'sqlsrv']))->field)->toBe('driver');
});

describe('what the database already holds', function (): void {
    // These tests change the test database outside a transaction: leave it fully migrated, as the rest of the suite expects.
    // (migrate:fresh only wipes a database that has a migrations table, which these tests sometimes remove.)
    afterEach(function (): void {
        Artisan::call('db:wipe', ['--force' => true]);
        Artisan::call('migrate', ['--force' => true]);
    });

    it('accepts an empty database', function (): void {
        Artisan::call('db:wipe', ['--force' => true]);

        expect(app(DatabaseProbe::class)->check(testDatabaseSettings())->ok)->toBeTrue();
    });

    it('accepts a database an earlier run of the installer already migrated, so an installation can be resumed', function (): void {
        Artisan::call('db:wipe', ['--force' => true]);
        Artisan::call('migrate', ['--force' => true]);

        expect(app(DatabaseProbe::class)->check(testDatabaseSettings())->ok)->toBeTrue();
    });

    it('refuses a database with our table names but no migrations table', function (): void {
        Artisan::call('db:wipe', ['--force' => true]);
        Schema::create('users', static function ($table): void {
            $table->id();
        });

        $result = app(DatabaseProbe::class)->check(testDatabaseSettings());

        expect($result->ok)->toBeFalse()->and($result->field)->toBe('database')->and($result->message)->toContain('migrations table');
    });

    it('refuses a migrations table that holds the migrations of another application', function (): void {
        Artisan::call('migrate:fresh', ['--force' => true]);
        DB::table('migrations')->insert(['migration' => '2020_01_01_000000_create_orders_table', 'batch' => 99]);

        $result = app(DatabaseProbe::class)->check(testDatabaseSettings());

        expect($result->ok)->toBeFalse()->and($result->field)->toBe('database')->and($result->message)->toContain('migrations of another application');
    });

    it('never touches a table that already has the name of its own probe table', function (): void {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Schema::create('installer_probe_check', static function ($table): void {
            $table->id();
            $table->string('note');
        });
        DB::table('installer_probe_check')->insert(['note' => 'a user table that must survive']);

        $result = app(DatabaseProbe::class)->check(testDatabaseSettings());

        expect($result->ok)->toBeFalse()->and($result->field)->toBe('database')
            ->and($result->message)->toContain('installer_probe_check')->toContain('Rename or remove it')
            ->and(DB::table('installer_probe_check')->pluck('note')->all())->toBe(['a user table that must survive']);
    });

    it('explains the probe table conflict in French', function (): void {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Schema::create('installer_probe_check', static function ($table): void {
            $table->id();
        });
        $this->useLanguage('fr');

        $result = app(DatabaseProbe::class)->check(testDatabaseSettings());

        expect($result->message)->toContain('contient déjà une table nommée installer_probe_check');
    });

    it('drops the probe table it created itself', function (): void {
        Artisan::call('migrate:fresh', ['--force' => true]);

        expect(app(DatabaseProbe::class)->check(testDatabaseSettings())->ok)->toBeTrue()
            ->and(Schema::hasTable('installer_probe_check'))->toBeFalse();
    });

    it('opens its connection with a timeout so that an unreachable host answers within seconds', function (): void {
        $config = app(DatabaseProbe::class)->configurationFor(testDatabaseSettings());

        expect($config['options'][PDO::ATTR_TIMEOUT])->toBe(5);
    });

    it('has no configuration for an engine it does not support', function (): void {
        expect(app(DatabaseProbe::class)->configurationFor(testDatabaseSettings(['driver' => 'sqlsrv'])))->toBeNull();
    });
});
