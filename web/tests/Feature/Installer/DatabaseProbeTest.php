<?php

declare(strict_types=1);

use App\Installer\Probes\DatabaseProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * These tests talk to the real database of the engine the suite runs on
 * (composer test runs them on PostgreSQL, MySQL and MariaDB in turn).
 */

it('accepts a reachable database that only holds our tables', function (): void {
    $result = app(DatabaseProbe::class)->check(testDatabaseSettings());

    expect($result->ok)->toBeTrue()
        ->and($result->meta['version'])->toMatch('/^\d+/')
        ->and($result->meta['engine'])->toBeIn(['PostgreSQL', 'MySQL', 'MariaDB']);
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
