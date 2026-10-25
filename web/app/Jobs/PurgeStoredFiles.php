<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Inventories\Attachments\AttachmentStorage;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Deletes files from a disk: some by their path, and whole folders by their path (every file below it).
 *
 * It is queued **after** the commit of the transaction that made the files useless (see
 * {@see AttachmentStorage::purgeAfterCommit()}), so a change that was rolled back, or retried after a deadlock, never
 * purges anything. It is tried three times, with a pause growing at each time; a path that is already gone counts as
 * deleted, so running it again is harmless. What could not be deleted after the last try is written in the log, with
 * the disk and the paths, so that somebody can remove it by hand.
 *
 * A folder must be below `workspaces/` and deep enough to belong to one workspace at least: an empty or a short path
 * would empty a whole disk.
 */
final class PurgeStoredFiles implements ShouldQueue
{
    use Queueable;

    /** How many times the job is tried. */
    public int $tries = 3;

    /**
     * Remembers what to delete.
     *
     * @param  string  $disk  The name of the disk the files are on.
     * @param  list<string>  $paths  Files to delete.
     * @param  list<string>  $directories  Folders to delete with everything below them.
     *
     * @throws InvalidArgumentException When a folder is not one of a workspace, an inventory, an item, an export, an import, a photo analysis or an official version.
     */
    public function __construct(
        public readonly string $disk,
        public readonly array $paths = [],
        public readonly array $directories = [],
    ) {
        foreach ($directories as $directory) {
            if (preg_match('#^workspaces/[0-9a-z]+(/inventories/[0-9a-z]+(/items/[0-9a-z]+|/exports/[0-9a-z]+|/imports/[0-9a-z]+|/analyses/[0-9a-z]+|/versions/[0-9a-z]+)?)?$#', $directory) !== 1) {
                throw new InvalidArgumentException("Refusing to purge [{$directory}]: it is not the folder of a workspace, an inventory, an item, an export, an import, a photo analysis or an official version.");
            }
        }
    }

    /**
     * The seconds to wait before each new try.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 300];
    }

    /**
     * Deletes everything it was given, going on after a failure so that one path that resists does not keep the others,
     * then fails (so that the queue tries again) when some could not be deleted.
     *
     * @throws RuntimeException When some files or folders could not be deleted.
     */
    public function handle(Filesystems $filesystems): void
    {
        $disk = $filesystems->disk($this->disk);
        $failed = [];

        foreach ($this->paths as $path) {
            if (! $this->succeeded(fn (): bool => $disk->delete($path), $path)) {
                $failed[] = $path;
            }
        }

        foreach ($this->directories as $directory) {
            if (! $this->succeeded(fn (): bool => $disk->deleteDirectory($directory), $directory)) {
                $failed[] = $directory.'/';
            }
        }

        if ($failed !== []) {
            throw new RuntimeException('Could not delete '.count($failed).' stored file(s) or folder(s) on the disk ['.$this->disk.'].');
        }
    }

    /**
     * Writes in the log what could not be deleted once the last try failed.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Stored files could not be deleted: they stay on the disk and must be removed by hand.', [
            'disk' => $this->disk,
            'paths' => $this->paths,
            'directories' => $this->directories,
            'exception' => $exception,
        ]);
    }

    /**
     * Whether a deletion worked: it answered true, and did not throw. One that did not is written in the log with what the disk
     * said, if it said anything (a disk that does not throw answers false).
     *
     * @param  callable(): bool  $delete
     */
    private function succeeded(callable $delete, string $what): bool
    {
        try {
            if ($delete()) {
                return true;
            }

            $exception = null;
        } catch (Throwable $exception) {
            // Logged below, with what it said.
        }

        Log::warning('A stored file could not be deleted: it will be tried again.', ['disk' => $this->disk, 'path' => $what, 'exception' => $exception]);

        return false;
    }
}
