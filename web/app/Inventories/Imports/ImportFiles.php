<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Exceptions\ImportFileException;
use App\Inventories\Exports\ExportTemp;
use App\Models\Import;
use App\Models\Inventory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The source file of an import on the disk of the files: where it is kept, a local copy to read it from, and its deletion.
 *
 * It is stored with the other private files, under the folder of its inventory (see {@see AttachmentStorage::importDirectory()}),
 * alone in a folder of its own, named after its format and not after what the person called it. The disk may be remote (an S3 bucket):
 * the readers need a file on the local disk, so the job and the analysis work on a copy in the scratch folder of the imports.
 */
final class ImportFiles
{
    /**
     * Gets the storage of the files.
     */
    public function __construct(private readonly AttachmentStorage $storage) {}

    /**
     * Where the source of an import is stored.
     */
    public function pathFor(Inventory $inventory, string $import, ImportFormat $format): string
    {
        $workspace = $inventory->workspace()->firstOrFail()->ulid;

        return AttachmentStorage::importDirectory($workspace, $inventory->ulid, $import).'/source.'.$format->storedExtension();
    }

    /**
     * Writes the file that was sent to the disk.
     *
     * @throws \RuntimeException When the disk refuses it.
     */
    public function store(string $disk, string $path, string $local): void
    {
        $this->storage->writeFrom($disk, $path, $local);
    }

    /**
     * A copy of the source on the local disk, to read it from. The caller deletes it.
     *
     * @return string The path of the copy.
     *
     * @throws ImportFileException When the source is gone or cannot be read.
     */
    public function localCopy(Import $import): string
    {
        if ($import->path === null) {
            throw new ImportFileException(ImportFileException::UNREADABLE);
        }

        $copy = ExportTemp::file('imports', 'import-');

        try {
            $stream = $this->storage->disk($import->disk)->readStream($import->path);
            $target = fopen($copy, 'wb');

            if (! is_resource($stream) || $target === false) {
                throw new ImportFileException(ImportFileException::UNREADABLE);
            }

            try {
                stream_copy_to_stream($stream, $target);
            } finally {
                fclose($stream);
                fclose($target);
            }

            return $copy;
        } catch (Throwable $failure) {
            @unlink($copy);

            throw $failure instanceof ImportFileException ? $failure : new ImportFileException(ImportFileException::UNREADABLE, $failure);
        }
    }

    /**
     * Deletes the source of an import now, with its folder. A disk that fails is written in the log and the deletion is queued
     * instead, so that a file never stays for an error of the moment.
     */
    public function delete(string $disk, ?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            $this->storage->disk($disk)->deleteDirectory(dirname($path));
        } catch (Throwable $failure) {
            Log::warning('The source file of an import could not be deleted now: its deletion is queued.', ['disk' => $disk, 'path' => $path, 'exception' => $failure]);

            $this->storage->purgeAfterCommit($disk, [], [dirname($path)]);
        }
    }
}
