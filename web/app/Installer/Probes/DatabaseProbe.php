<?php

declare(strict_types=1);

namespace App\Installer\Probes;

use App\Installer\ApplicationTables;
use App\Installer\Settings\DatabaseSettings;
use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * Checks database settings against the real server, through a temporary
 * connection that never replaces the application's own.
 *
 * This is the one place allowed to know about engine differences, and it only
 * asks the driver and Laravel's schema builder; no SQL is written by hand.
 */
final class DatabaseProbe
{
    /** Name of the throw-away connection. */
    private const CONNECTION = 'installer_probe';

    /** Table created, then dropped, to prove the user may do so. It must not exist beforehand: the probe only ever drops the table it created. */
    private const PROBE_TABLE = 'installer_probe_check';

    /** Seconds to establish the connection. */
    private const CONNECT_TIMEOUT = 5;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Repository $config,
        private readonly Migrator $migrator,
    ) {}

    /**
     * Connects with the given settings and verifies the server is suitable.
     *
     * Checks, in order: the connection, that the engine is the one chosen (a
     * MariaDB server answers to a "mysql" connection and the other way round),
     * the minimum version, that the database holds nothing but this
     * application's tables (and no table named like the probe's own), and that
     * the user may create tables.
     */
    public function check(DatabaseSettings $settings): ProbeResult
    {
        $config = $this->configurationFor($settings);
        $driver = is_array($config) && is_string($config['driver'] ?? null) ? $config['driver'] : null;

        if ($config === null || ! in_array($driver, DatabaseSettings::DRIVERS, true)) {
            return ProbeResult::failure(__('This database engine is not supported.'), 'driver');
        }

        $extension = $driver === 'pgsql' ? 'pdo_pgsql' : 'pdo_mysql';

        if (! extension_loaded($extension)) {
            return ProbeResult::failure(__('The PHP extension :name is not installed.', ['name' => $extension]), 'driver');
        }

        try {
            $connection = $this->db->connectUsing(self::CONNECTION, $config, true);

            if (! $connection instanceof Connection) {
                return ProbeResult::failure(__('This database engine is not supported.'), 'driver');
            }

            $connection->getPdo();
        } catch (Throwable $exception) {
            $this->db->purge(self::CONNECTION);

            return $this->connectionFailure($exception, $settings);
        }

        try {
            return $this->inspect($connection, $driver, $config);
        } catch (Throwable $exception) {
            return ProbeResult::failure(__('The database check failed: :reason', ['reason' => $this->reason($exception, $settings)]));
        } finally {
            $this->db->purge(self::CONNECTION);
        }
    }

    /**
     * The connection configuration to test, or null for an engine that is not supported.
     *
     * It is exactly what the application will open: the engine's own entry of
     * config/database.php, with the form's fields or, when one is provided, the
     * connection URL parsed the way Laravel parses it. A connect timeout is
     * added so an unreachable host answers within seconds.
     *
     * @return array<string, mixed>|null
     */
    public function configurationFor(DatabaseSettings $settings): ?array
    {
        $base = $this->config->get('database.connections.'.$settings->driver);

        if (! in_array($settings->driver, DatabaseSettings::DRIVERS, true) || ! is_array($base)) {
            return null;
        }

        $config = (new ConfigurationUrlParser)->parseConfiguration($settings->connectionConfig($base));
        $config['options'] = array_replace(is_array($config['options'] ?? null) ? $config['options'] : [], [PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT]);

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config  The connection configuration, URL already parsed.
     */
    private function inspect(Connection $connection, string $driver, array $config): ProbeResult
    {
        $server = ServerVersion::parse($driver, (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION));

        if ($driver === 'mysql' && $server->flavor === 'mariadb') {
            return ProbeResult::failure(__('This server is MariaDB, not MySQL: choose MariaDB as the database engine.'), 'driver');
        }

        if ($driver === 'mariadb' && $server->flavor !== 'mariadb') {
            return ProbeResult::failure(__('This server is MySQL, not MariaDB: choose MySQL as the database engine.'), 'driver');
        }

        $minimum = $this->config->string('installer.minimum_versions.'.$driver);

        if (version_compare($server->version, $minimum, '<')) {
            return ProbeResult::failure(__(':engine :minimum or newer is required, this server runs :found.', [
                'engine' => $server->engine(),
                'minimum' => $minimum,
                'found' => $server->version,
            ]), 'host');
        }

        $tables = $this->tableNames($connection, $driver, $config);

        // Whoever owns this table (a user, an earlier crashed probe), it is not ours to drop: the probe stays out of it.
        if (in_array(self::PROBE_TABLE, $tables, true)) {
            return ProbeResult::failure(__('The database already contains a table named :table, which this check needs to create and drop. Rename or remove it, then try again.', ['table' => self::PROBE_TABLE]), 'database');
        }

        if (($failure = $this->checkExistingTables($connection, $tables)) !== null) {
            return $failure;
        }

        if (($failure = $this->checkPrivileges($connection)) !== null) {
            return $failure;
        }

        return ProbeResult::success(['engine' => $server->engine(), 'version' => $server->version]);
    }

    /**
     * Names of the tables already in the database.
     *
     * @param  array<string, mixed>  $base
     * @return list<string>
     */
    private function tableNames(Connection $connection, string $driver, array $base): array
    {
        // PostgreSQL lists the tables of every schema unless told which one the application uses.
        $schema = null;

        if ($driver === 'pgsql') {
            $searchPath = $base['search_path'] ?? $base['schema'] ?? 'public';
            $schema = is_array($searchPath) ? (string) ($searchPath[0] ?? 'public') : trim(explode(',', (string) $searchPath)[0], " \"'");
        }

        return array_map(
            static fn (array $table): string => (string) $table['name'],
            $connection->getSchemaBuilder()->getTables($schema),
        );
    }

    /**
     * Accepts an empty database, or one an earlier run of this installer already
     * started: it has a `migrations` table whose entries are all migrations of
     * this application. Anything else is somebody else's data.
     *
     * @param  list<string>  $tables
     */
    private function checkExistingTables(Connection $connection, array $tables): ?ProbeResult
    {
        if ($tables === []) {
            return null;
        }

        $foreign = array_values(array_filter($tables, static fn (string $table): bool => ! ApplicationTables::owns($table)));

        if ($foreign !== []) {
            return ProbeResult::failure(__('This database already contains tables that do not belong to inventaire.cloud (:tables). Use an empty database.', [
                'tables' => implode(', ', array_slice($foreign, 0, 5)).(count($foreign) > 5 ? ', …' : ''),
            ]), 'database');
        }

        if (! in_array('migrations', $tables, true)) {
            return ProbeResult::failure(__('This database is not empty and was not created by inventaire.cloud (it has no migrations table). Use an empty database.'), 'database');
        }

        $recorded = $connection->table('migrations')->pluck('migration')->all();
        $ours = array_keys($this->migrator->getMigrationFiles(database_path('migrations')));

        if (array_diff($recorded, $ours) !== []) {
            return ProbeResult::failure(__('This database holds migrations of another application. Use an empty database.'), 'database');
        }

        return null;
    }

    /**
     * Creates and drops a table to prove the user may do so; a read-only user
     * (or PostgreSQL 15+, which no longer lets every user create in "public")
     * would otherwise only fail at the very end, in the migrations.
     *
     * The caller has checked that the table does not exist yet. Nothing is
     * dropped unless the creation succeeded, so a table that appeared in the
     * meantime (the creation then fails) is never touched.
     */
    private function checkPrivileges(Connection $connection): ?ProbeResult
    {
        $schema = $connection->getSchemaBuilder();

        try {
            $schema->create(self::PROBE_TABLE, static function (Blueprint $blueprint): void {
                $blueprint->id();
            });
        } catch (Throwable) {
            return ProbeResult::failure(__('This user cannot create tables in the database. Grant it the right to create and drop tables.'), 'username');
        }

        try {
            $schema->drop(self::PROBE_TABLE);
        } catch (Throwable) {
            return ProbeResult::failure(__('This user can create tables but not drop them. Grant it the right to drop tables.'), 'username');
        }

        return null;
    }

    /**
     * Turns a connection error into a failure pointing at the likely input.
     */
    private function connectionFailure(Throwable $exception, DatabaseSettings $settings): ProbeResult
    {
        $message = $exception->getMessage();
        $field = match (true) {
            // MySQL and MariaDB answer "access denied ... to database" for a database the user cannot use, existing or not.
            preg_match('/unknown database|database "[^"]*" does not exist|access denied for user [^ ]+ to database/i', $message) === 1 => 'database',
            preg_match('/access denied|password authentication failed|authentication failed|no password supplied|role "[^"]*" does not exist/i', $message) === 1 => 'username',
            default => 'host',
        };

        return ProbeResult::failure(__('Cannot connect to the database: :reason', ['reason' => $this->reason($exception, $settings)]), $field);
    }

    /**
     * A short, single-line version of an error that never contains the password.
     */
    private function reason(Throwable $exception, DatabaseSettings $settings): string
    {
        $message = (string) preg_replace('/\s+/', ' ', $exception->getMessage());

        foreach ([$settings->password, $settings->url, parse_url((string) $settings->url, PHP_URL_PASS) ?: null] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        return Str::limit(trim($message), 240);
    }
}
