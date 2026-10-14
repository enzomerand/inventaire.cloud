<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Exceptions\InstallationException;
use App\Installer\Exceptions\LockBusyException;
use App\Installer\Support\FileLock;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Applies the pending database migrations, one process at a time.
 *
 * It is the one place that knows how, shared by the installation
 * ({@see InstallationFinalizer}, on a connection built from the wizard's
 * settings) and by `inventaire:upgrade` (on the application's own connection).
 *
 * Concurrency is handled by an exclusive file lock (`migrate.lock` in the
 * installer's state folder), held for the whole migration. A second caller
 * WAITS for it, up to {@see self::LOCK_WAIT} seconds, and then finds nothing
 * left to migrate: this is what lets several containers start together. The
 * lock is a `flock`, which the system drops when the process ends, even when it
 * is killed: no stale lock can block later upgrades.
 */
final class DatabaseMigrator
{
    /** How long a caller waits for another migration to finish, in seconds. The Docker image's HEALTHCHECK start period (Dockerfile) is sized from it: keep it above twice this. */
    public const LOCK_WAIT = 600.0;

    /** Name of the temporary connection {@see self::migrateOn()} registers. */
    private const CONNECTION = 'installer_migrate';

    /** Lock file, in the installer's state folder. */
    private const LOCK = 'migrate.lock';

    /**
     * @param  float  $lockWait  Seconds to wait for a running migration before giving up.
     */
    public function __construct(
        private readonly Kernel $artisan,
        private readonly Repository $config,
        private readonly DatabaseManager $db,
        private readonly InstallationState $state,
        private readonly float $lockWait = self::LOCK_WAIT,
    ) {}

    /**
     * Applies the pending migrations on the application's default connection.
     *
     * @return string What the migrator printed, for the caller to show.
     *
     * @throws InstallationException When the migrations fail, or another migration is still running after the wait.
     */
    public function migrate(): string
    {
        return $this->call([]);
    }

    /**
     * Applies the pending migrations on a connection that exists only for this
     * call, built from the given configuration.
     *
     * The installer needs it because in a web request the default connection
     * was configured before the wizard wrote the database settings. The
     * connection is removed afterwards, whatever the outcome.
     *
     * @param  array<string, mixed>  $connection  A Laravel connection configuration.
     * @return string What the migrator printed.
     *
     * @throws InstallationException When the migrations fail, or another migration is still running after the wait.
     */
    public function migrateOn(array $connection): string
    {
        $this->config->set('database.connections.'.self::CONNECTION, $connection);

        try {
            return $this->call(['--database' => self::CONNECTION]);
        } finally {
            $this->db->purge(self::CONNECTION);
            $this->config->offsetUnset('database.connections.'.self::CONNECTION);
        }
    }

    /**
     * Runs `migrate` with the given extra options, under the lock.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InstallationException
     */
    private function call(array $options): string
    {
        try {
            return FileLock::exclusively($this->state->path(self::LOCK), fn (): string => $this->migrateLocked($options), $this->lockWait);
        } catch (LockBusyException) {
            throw new InstallationException('Another migration is still running (waited :seconds seconds). Try again once it has finished.', ['seconds' => (int) round($this->lockWait)]);
        }
    }

    /**
     * The migration itself; the lock is held.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InstallationException
     */
    private function migrateLocked(array $options): string
    {
        $output = new BufferedOutput;

        try {
            $status = $this->artisan->call('migrate', $options + ['--force' => true, '--no-interaction' => true], $output);
        } catch (Throwable $exception) {
            throw new InstallationException('The database migration failed: :reason', ['reason' => $this->lastLine($exception->getMessage())]);
        }

        if ($status !== 0) {
            throw new InstallationException('The database migration failed: :reason', ['reason' => $this->lastLine($output->fetch())]);
        }

        return $output->fetch();
    }

    /**
     * The last meaningful line of an error or of command output.
     */
    private function lastLine(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));

        return mb_substr($lines === [] ? '' : $lines[array_key_last($lines)], 0, 240);
    }
}
