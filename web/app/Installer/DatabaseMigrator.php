<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Exceptions\InstallationException;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Brings the database up to date: the migrations, then the
 * {@see PostMigrationHook}s.
 *
 * It is the one place that knows how, shared by the installation
 * ({@see InstallationFinalizer}, on a connection built from the wizard's
 * settings) and by `inventaire:upgrade` (on the application's own
 * connection). The migrations always run isolated: if another process is
 * already migrating, this one stops with an error instead of racing it, which
 * is what lets several containers start at once.
 */
final class DatabaseMigrator
{
    /** Name of the temporary connection {@see self::migrateOn()} registers. */
    private const CONNECTION = 'installer_migrate';

    /**
     * @param  iterable<PostMigrationHook>  $hooks  Registered through the container tag {@see PostMigrationHook::TAG}.
     */
    public function __construct(
        private readonly Kernel $artisan,
        private readonly Repository $config,
        private readonly DatabaseManager $db,
        private readonly iterable $hooks = [],
    ) {}

    /**
     * Applies the pending migrations on the application's default connection.
     *
     * @return string What the migrator printed, for the caller to show.
     *
     * @throws InstallationException When the migrations fail or another migration is already running.
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
     * @throws InstallationException When the migrations fail or another migration is already running.
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
     * Runs the {@see PostMigrationHook}s, in registration order.
     *
     * Hooks must be idempotent: they run again on every upgrade.
     *
     * @return int How many hooks ran.
     *
     * @throws InstallationException When a hook aborts.
     */
    public function runHooks(): int
    {
        $count = 0;

        foreach ($this->hooks as $hook) {
            $hook->run();
            $count++;
        }

        return $count;
    }

    /**
     * Runs `migrate` with the given extra options.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InstallationException
     */
    private function call(array $options): string
    {
        $output = new BufferedOutput;

        try {
            $status = $this->artisan->call('migrate', $options + [
                '--force' => true,
                '--no-interaction' => true,
                // Refuses (exit 1) when another migration is already running instead of silently doing nothing.
                '--isolated' => 1,
            ], $output);
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
