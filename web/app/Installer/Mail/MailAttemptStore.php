<?php

declare(strict_types=1);

namespace App\Installer\Mail;

use App\Installer\InstallationState;
use App\Installer\Support\AtomicFile;
use Closure;

/**
 * Counts the wrong verification codes of each pending challenge, on disk.
 *
 * The challenge itself lives in the visitor's session, which every request
 * copies, changes and writes back: two requests guessing at the same time would
 * each count one attempt from the same starting point and the second write
 * would erase the first. Counting here, under the installer's lock, makes the
 * limit hold however many requests arrive together.
 */
final class MailAttemptStore
{
    private const FILE = 'mail-attempts.json';

    /** Challenges remembered; older ones are long expired. */
    private const KEEP = 20;

    public function __construct(private readonly InstallationState $state) {}

    /**
     * Wrong codes recorded so far for a challenge.
     */
    public function count(string $id): int
    {
        return $this->load()[$id] ?? 0;
    }

    /**
     * Records the number of wrong codes of a challenge.
     */
    public function record(string $id, int $attempts): void
    {
        $counts = $this->load();
        unset($counts[$id]);
        $counts[$id] = $attempts;

        AtomicFile::write($this->state->path(self::FILE), json_encode(array_slice($counts, -self::KEEP), JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * Runs a read-then-record sequence under the installer's exclusive lock.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function exclusively(Closure $callback): mixed
    {
        return $this->state->exclusively($callback);
    }

    /**
     * @return array<string, int>
     */
    private function load(): array
    {
        $contents = @file_get_contents($this->state->path(self::FILE));
        $decoded = $contents === false ? null : json_decode($contents, true);
        $counts = [];

        foreach (is_array($decoded) ? $decoded : [] as $id => $attempts) {
            if (is_string($id) && is_int($attempts)) {
                $counts[$id] = $attempts;
            }
        }

        return $counts;
    }
}
