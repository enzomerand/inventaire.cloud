<?php

declare(strict_types=1);

namespace App\Installer\Support;

use App\Installer\Exceptions\InstallationException;
use App\Installer\Exceptions\LockBusyException;
use Closure;

/**
 * An exclusive lock on a file, for read-modify-write sequences that several
 * requests (the wizard, the console installer) may run at the same time.
 *
 * Without it two requests reading the progress file, each adding its own step,
 * would write back only one of them. If the lock cannot be taken (unwritable
 * folder, a filesystem without locks, a holder that never lets go) the caller
 * gets an exception: running the sequence unlocked would silently reintroduce
 * the race.
 *
 * The lock is re-entrant within the process, so code holding it may call other
 * code that locks the same file.
 */
final class FileLock
{
    /** Seconds to wait for a busy lock before giving up. */
    public const DEFAULT_WAIT = 10.0;

    /** @var array<string, int> Nesting depth per lock file, for re-entrancy. */
    private static array $depth = [];

    /**
     * Runs a callback while holding the exclusive lock of a file.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  float  $wait  Seconds to wait for the lock; 0 tries once.
     * @return T
     *
     * @throws LockBusyException When somebody else holds the lock for longer than $wait.
     * @throws InstallationException When the lock file cannot be opened, or the filesystem refuses the lock
     *                               for a reason other than somebody else holding it (no lock support, no lock slot left).
     */
    public static function exclusively(string $path, Closure $callback, float $wait = self::DEFAULT_WAIT): mixed
    {
        if ((self::$depth[$path] ?? 0) > 0) {
            self::$depth[$path]++;

            try {
                return $callback();
            } finally {
                self::$depth[$path]--;
            }
        }

        AtomicFile::touch($path);

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new InstallationException('Cannot lock :path.', ['path' => AtomicFile::display($path)]);
        }

        $deadline = microtime(true) + $wait;
        $wouldBlock = 0;

        while (! flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            // Only "somebody else holds it" is worth waiting for. Any other refusal (a filesystem without
            // locks, ENOLCK) would never go away: fail now instead of waiting and blaming a phantom holder.
            if ($wouldBlock !== 1) {
                fclose($handle);

                throw new InstallationException('Cannot lock :path: the filesystem refused the lock.', ['path' => AtomicFile::display($path)]);
            }

            if (microtime(true) >= $deadline) {
                fclose($handle);

                throw new LockBusyException('Another request is working on the installation: try again in a moment.');
            }

            usleep(25_000);
        }

        self::$depth[$path] = 1;

        try {
            return $callback();
        } finally {
            self::$depth[$path] = 0;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
