<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

use App\Jobs\PurgeStoredFiles;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Where the files of the items are kept: which disk, which paths, and how a file is written and, later, purged.
 *
 * The disk is `inventories.attachments_disk` (the default disk of the application when it is empty): the private local
 * disk or an S3-compatible bucket. The `public` disk is refused, since its files are served by the web server without any
 * check of who asks: the private local disk is used instead, and the refusal is written in the log.
 *
 * A file lives at `workspaces/{workspace}/inventories/{inventory}/items/{item}/{attachment}` (all ULIDs), its thumbnail
 * next to it with `.thumb.jpg` added. The name the person gave the file, and its extension, are never part of the path:
 * nothing a person types can move a file elsewhere. Because the paths nest, everything that belongs to an item, an
 * inventory or a workspace is deleted by deleting one folder.
 */
final class AttachmentStorage
{
    /** The disk that stands in for a `public` one. */
    public const FALLBACK_DISK = 'local';

    /** Files per queued job when a long list has to be purged, so that no job carries thousands of paths. */
    private const PATHS_PER_JOB = 500;

    /** Whether the refusal of the `public` disk was written in the log by this process already. */
    private static bool $refusalLogged = false;

    /**
     * Gets the filesystems and the database (the purge waits for its transaction).
     */
    public function __construct(
        private readonly Filesystems $filesystems,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The name of the disk new files are written to.
     */
    public function diskName(): string
    {
        $configured = config('inventories.attachments_disk');
        $name = is_string($configured) && $configured !== '' ? $configured : config()->string('filesystems.default');

        if ($name !== 'public') {
            return $name;
        }

        if (! self::$refusalLogged) {
            self::$refusalLogged = true;

            Log::warning('The public disk cannot hold attachments, since anybody could read them: the private local disk is used instead. Set ATTACHMENTS_DISK to a private disk.');
        }

        return self::FALLBACK_DISK;
    }

    /**
     * A disk by its name.
     */
    public function disk(string $name): Filesystem
    {
        return $this->filesystems->disk($name);
    }

    /**
     * The folder of a workspace.
     */
    public static function workspaceDirectory(string $workspace): string
    {
        return "workspaces/{$workspace}";
    }

    /**
     * The folder of an inventory.
     */
    public static function inventoryDirectory(string $workspace, string $inventory): string
    {
        return self::workspaceDirectory($workspace)."/inventories/{$inventory}";
    }

    /**
     * The folder of an item.
     */
    public static function itemDirectory(string $workspace, string $inventory, string $item): string
    {
        return self::inventoryDirectory($workspace, $inventory)."/items/{$item}";
    }

    /**
     * The path of a thumbnail, from the path of its file.
     */
    public static function thumbnailPath(string $path): string
    {
        return $path.'.thumb.jpg';
    }

    /**
     * Writes some bytes, or a stream to read them from, to a disk.
     *
     * @param  string|resource  $contents  The bytes to write, or an open stream to read them from (it is not closed).
     *
     * @throws RuntimeException When the disk refuses it.
     */
    public function write(string $disk, string $path, mixed $contents): void
    {
        if ($this->disk($disk)->put($path, $contents) === false) {
            throw new RuntimeException("A file could not be written to the disk [{$disk}].");
        }
    }

    /**
     * Writes the file that was uploaded to a disk without reading it all into memory.
     *
     * @param  string  $source  Where the uploaded file is on the server.
     *
     * @throws RuntimeException When the file cannot be read or the disk refuses it.
     */
    public function writeFrom(string $disk, string $path, string $source): void
    {
        $stream = fopen($source, 'rb');

        if ($stream === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        try {
            $this->write($disk, $path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Deletes files at once, for the ones that were written for a transaction that failed. A file that cannot be deleted
     * (the disk throws, or answers that it did not: a disk that does not throw says so by returning false) is written in the
     * log (nothing refers to it: it is just left on the disk) and does not hide the failure that led here.
     *
     * @param  list<string>  $paths
     */
    public function forgetNow(string $disk, array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if ($this->disk($disk)->delete($path) !== false) {
                    continue;
                }

                $exception = null;
            } catch (Throwable $exception) {
                // Logged below, with what it said.
            }

            Log::warning('A file written for a change that failed could not be deleted: it stays on the disk.', ['disk' => $disk, 'path' => $path, 'exception' => $exception]);
        }
    }

    /**
     * Has the files and folders deleted once the transaction in progress is committed (at once when there is none): a
     * transaction that is rolled back, or retried after a deadlock, schedules nothing, so no file goes for a change that
     * did not happen.
     *
     * The deletion itself is a queued job ({@see PurgeStoredFiles}). Queueing it can fail once the change is committed (the
     * queue is down): that is logged with the paths, and does not turn the answer of a change that did happen into an error.
     *
     * @param  list<string>  $paths  Files.
     * @param  list<string>  $directories  Folders, with everything below them.
     */
    public function purgeAfterCommit(string $disk, array $paths = [], array $directories = []): void
    {
        $paths = array_values(array_unique($paths));
        $directories = array_values(array_unique($directories));

        if ($paths === [] && $directories === []) {
            return;
        }

        $this->db->connection()->afterCommit(function () use ($disk, $paths, $directories): void {
            try {
                foreach (array_chunk($paths, self::PATHS_PER_JOB) as $chunk) {
                    PurgeStoredFiles::dispatch($disk, $chunk);
                }

                if ($directories !== []) {
                    PurgeStoredFiles::dispatch($disk, [], $directories);
                }
            } catch (Throwable $exception) {
                Log::error('Stored files could not be queued for deletion: they stay on the disk and must be removed by hand.', ['disk' => $disk, 'paths' => $paths, 'directories' => $directories, 'exception' => $exception]);
            }
        });
    }
}
