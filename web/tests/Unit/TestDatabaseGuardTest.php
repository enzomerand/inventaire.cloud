<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Tests\Support\TestDatabaseGuard;

/**
 * Builds a config repository with a single pgsql connection.
 *
 * @param  array<string, mixed>  $overrides  Connection settings to override.
 */
function databaseConfig(array $overrides = []): Repository
{
    return new Repository([
        'database' => [
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => array_merge(['driver' => 'pgsql', 'database' => 'inventaire_test', 'url' => null], $overrides),
            ],
        ],
    ]);
}

it('accepts a database whose name ends with _test', function (string $database): void {
    TestDatabaseGuard::assertSafe(databaseConfig(['database' => $database]));
})->with(['inventaire_test', 'other_app_test'])->throwsNoExceptions();

it('refuses a database that is not a test database', function (string $database): void {
    TestDatabaseGuard::assertSafe(databaseConfig(['database' => $database]));
})->with(['the dev database' => 'inventaire', 'a test prefix only' => 'test_inventaire', 'in-memory sqlite' => ':memory:'])
    ->throws(RuntimeException::class, 'Refusing to run tests');

it('refuses a connection configured through a URL', function (): void {
    TestDatabaseGuard::assertSafe(databaseConfig(['url' => 'postgres://u:p@host/inventaire']));
})->throws(RuntimeException::class, 'configured through a URL');

it('refuses a missing database name', function (): void {
    TestDatabaseGuard::assertSafe(databaseConfig(['database' => null]));
})->throws(RuntimeException::class, 'does not end with');

it('refuses an unknown default connection', function (): void {
    TestDatabaseGuard::assertSafe(new Repository(['database' => ['default' => 'nope', 'connections' => []]]));
})->throws(RuntimeException::class, 'not configured');

/**
 * A repository whose default connection is the dev database and which also has a test one.
 */
function twoConnections(): Repository
{
    return new Repository([
        'database' => [
            'default' => 'dev',
            'connections' => [
                'dev' => ['driver' => 'pgsql', 'database' => 'inventaire', 'url' => null],
                'testing' => ['driver' => 'pgsql', 'database' => 'inventaire_test', 'url' => null],
            ],
        ],
    ]);
}

it('judges the connection it is asked about instead of the default one', function (): void {
    TestDatabaseGuard::assertSafe(twoConnections(), 'testing');
})->throwsNoExceptions();

it('refuses a named connection that is not a test database, even when the default one is', function (): void {
    TestDatabaseGuard::assertSafe(twoConnections(), 'dev');
})->throws(RuntimeException::class, 'does not end with');

it('refuses a named connection that is not configured', function (): void {
    TestDatabaseGuard::assertSafe(twoConnections(), 'nope');
})->throws(RuntimeException::class, 'not configured');
