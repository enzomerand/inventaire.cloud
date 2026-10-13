<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Refuses to run the test suite against anything but a dedicated test database.
 *
 * Feature tests use RefreshDatabase, which drops every table with
 * `migrate:fresh`. A stale cached config, a missing phpunit environment value
 * or a stray DB_DATABASE exported in the shell must never be able to point that
 * at the development database, so the check runs before any migration.
 */
final class TestDatabaseGuard
{
    /**
     * Suffix every database used by the test suite must carry.
     */
    public const REQUIRED_SUFFIX = '_test';

    /**
     * Throws unless the default connection targets a database whose name ends
     * with {@see self::REQUIRED_SUFFIX}.
     *
     * A connection URL is rejected as well: it overrides the database name at
     * connection time, so the configured name could no longer be trusted.
     *
     * @throws RuntimeException When the database is not clearly a test database.
     */
    public static function assertSafe(Repository $config): void
    {
        $connection = $config->get('database.default');
        $settings = $config->get("database.connections.{$connection}");

        if (! is_string($connection) || ! is_array($settings)) {
            throw new RuntimeException('Refusing to run tests: the default database connection is not configured.');
        }

        $database = $settings['database'] ?? null;

        if (! empty($settings['url'])) {
            throw new RuntimeException(
                "Refusing to run tests: connection \"{$connection}\" is configured through a URL, which can override the database name."
            );
        }

        if (! is_string($database) || ! str_ends_with($database, self::REQUIRED_SUFFIX)) {
            $shown = is_string($database) ? $database : 'none';

            throw new RuntimeException(
                "Refusing to run tests: database \"{$shown}\" on connection \"{$connection}\" does not end with \""
                .self::REQUIRED_SUFFIX.'". Use composer test (or test:pgsql / test:mysql / test:mariadb) and clear any cached config.'
            );
        }
    }
}
