<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Fields\FieldTypes;
use App\Models\Attachment;
use App\Models\Item;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Writes the files attached to the items as a zip, a folder for each item: `{label} - {ULID}/{name of the file}`.
 *
 * The label is the first text field of the item that has a value, in the order the fields are shown ("Item" when it has none), so
 * that a person finds a photo by what it shows; the ULID keeps two items with the same label apart. With the option `files` set to
 * `photos` (the default), the files are those of the photo fields; with `all`, every file attached to the item, the plain ones too.
 *
 * Every name is cleaned up ({@see ExportNames}) so that nothing can be unzipped outside the folder the person chose, and a name
 * already used in the folder of an item gets ` (2)`, ` (3)` before its extension. The files are stored as they are (`CM_STORE`):
 * photos are compressed already. A file that is on the local disk is added from where it is (the library reads it when the zip is
 * closed); one on another disk (S3) is copied to a scratch file first, deleted once the zip is closed.
 *
 * Only a file that is **missing** from its disk is skipped and logged: the export of the others goes on. Any other problem (a
 * file that exists but cannot be read, a copy that comes out short or cannot be written, a disk that does not answer) fails the
 * whole export, which the person can ask for again: a zip that silently lacks files would be taken for a complete one. So does an
 * export whose files are **all** missing: an empty zip is only for a scope that has no file to begin with. A file that disappears
 * from the local disk between the moment it is added and the moment the zip is closed fails the export too, with a clear error.
 */
final class ZipWriter implements ExportWriter
{
    /** The files an export of photos holds. */
    public const PHOTOS = 'photos';

    /** The files an export of everything holds. */
    public const ALL = 'all';

    /**
     * Gets the storage the files are read from and the registry of field types, which tells the photo fields.
     */
    public function __construct(
        private readonly AttachmentStorage $storage,
        private readonly FieldTypes $types,
    ) {}

    /**
     * Writes the zip.
     *
     * @throws RuntimeException When the zip cannot be written, or when every file of the scope is missing from its disk.
     */
    public function write(ExportContext $context, string $path): int
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The zip of the export could not be created.');
        }

        // Keyed by id: the files of an export of photos are the ones that fill these fields.
        $photoFields = array_flip($this->types->photoFieldIds($context->fields));
        $everything = $context->export->option('files', self::PHOTOS) === self::ALL;
        $scratch = [];
        $items = 0;
        $entries = 0;
        $skipped = 0;
        $closed = false;

        try {
            foreach ($context->items(withAttachments: true) as $item) {
                $files = $item->attachments->filter(fn (Attachment $file): bool => $everything || ($file->field_id !== null && isset($photoFields[$file->field_id])));

                if ($files->isEmpty()) {
                    continue;
                }

                $folder = $this->folder($context, $item);
                $taken = [];

                foreach ($files as $file) {
                    $source = $this->source($file, $scratch);

                    if ($source === null) {
                        $skipped++;

                        continue;
                    }

                    $entry = $folder.'/'.ExportNames::unique(ExportNames::entry($file->name, __('Attached file')), $taken);

                    if (! $zip->addFile($source, $entry)) {
                        throw new RuntimeException('A file could not be added to the zip of the export.');
                    }

                    $zip->setCompressionName($entry, ZipArchive::CM_STORE);
                    $entries++;
                }

                $items++;
            }

            // Files were asked for and none could be read: completing with an empty zip would pass for an export of nothing, while
            // the person has photos. Thrown before the archive is closed, so that the cleanup below discards it.
            if ($entries === 0 && $skipped > 0) {
                throw new RuntimeException("None of the {$skipped} files of the export could be added to the zip: they are all missing from their disk.");
            }

            // The library reads the files, and writes the archive, only now. It is closed either way (a failure discards it).
            $closed = true;

            $this->close($zip);

            // An archive without an entry is not written at all: leave the empty one a reader accepts (just its end marker).
            if ($entries === 0) {
                $this->writeEmpty($path);
            }
        } finally {
            if (! $closed) {
                // Something went wrong half way: drop what was queued (nothing must be read or written for an export that failed),
                // and release the archive, whatever it says about it (the first error is the one that counts).
                try {
                    $zip->unchangeAll();
                    $zip->close();
                } catch (Throwable) {
                    // Nothing more can be done for a file that is going to be deleted.
                }
            }

            foreach ($scratch as $file) {
                @unlink($file);
            }
        }

        return $items;
    }

    /**
     * Closes the archive, which is when the library reads the files and writes the zip.
     *
     * @throws RuntimeException When it cannot be written, most likely because a file that was added is gone since.
     */
    private function close(ZipArchive $zip): void
    {
        try {
            $closed = $zip->close();
            $reason = $zip->getStatusString();
        } catch (Throwable $exception) {
            // The library warns when it cannot read a file or write the archive, and the application turns warnings into exceptions.
            throw new RuntimeException('The zip of the export could not be written: a file may have been deleted while it was being added.', 0, $exception);
        }

        if (! $closed) {
            throw new RuntimeException("The zip of the export could not be written ({$reason}).");
        }
    }

    /**
     * Writes the archive without any entry: only the end marker of the format.
     *
     * @throws RuntimeException When the file cannot be written.
     */
    private function writeEmpty(string $path): void
    {
        $marker = "PK\x05\x06".str_repeat("\0", 18);

        if (file_put_contents($path, $marker) !== strlen($marker)) {
            throw new RuntimeException('The empty zip of the export could not be written.');
        }
    }

    /**
     * The name of the folder of an item: its label and its ULID.
     */
    private function folder(ExportContext $context, Item $item): string
    {
        $presented = $context->table->presented($item);
        $label = '';

        foreach ($context->fields as $field) {
            if ($field->type === 'text' && is_string($presented[$field->ulid] ?? null) && trim($presented[$field->ulid]) !== '') {
                $label = $presented[$field->ulid];

                break;
            }
        }

        return ExportNames::entry($label, __('Item'), ExportNames::FOLDER_LABEL_MAX_BYTES).' - '.$item->ulid;
    }

    /**
     * A local path to read a file from: where it is when its disk is local, a scratch copy otherwise. Null when the file is
     * missing from its disk (it is logged).
     *
     * @param  list<string>  $scratch  The scratch files made so far, which this adds to.
     *
     * @throws RuntimeException When the file is there but cannot be read, or its copy comes out short or cannot be written.
     */
    private function source(Attachment $file, array &$scratch): ?string
    {
        $disk = $this->storage->disk($file->disk);

        // A disk that cannot answer throws here, and that fails the export: only a file that is really not there is left out.
        if (! $disk->exists($file->path)) {
            Log::warning('A file could not be read for a zip export: it is missing from its disk and left out.', ['attachment' => $file->ulid, 'disk' => $file->disk, 'path' => $file->path]);

            return null;
        }

        if (config("filesystems.disks.{$file->disk}.driver") === 'local') {
            $local = $disk->path($file->path);

            if (! is_file($local) || ! is_readable($local)) {
                throw new RuntimeException("The file [{$file->ulid}] cannot be read from its disk.");
            }

            return $local;
        }

        $stream = $disk->readStream($file->path);

        if (! is_resource($stream)) {
            throw new RuntimeException("The file [{$file->ulid}] cannot be read from its disk.");
        }

        $copy = ExportTemp::file();
        $scratch[] = $copy;
        $target = fopen($copy, 'wb');

        if ($target === false) {
            fclose($stream);

            throw new RuntimeException('A scratch file could not be opened.');
        }

        try {
            $copied = stream_copy_to_stream($stream, $target);
            $flushed = fflush($target);
        } finally {
            fclose($stream);
            fclose($target);
        }

        // The count must be the one recorded when the file was uploaded: a short copy (a connection that dropped, a full disk)
        // would put a truncated file in the zip without a word.
        if ($copied === false || $copied !== $file->size || ! $flushed) {
            throw new RuntimeException("The file [{$file->ulid}] was not copied whole (".(int) $copied." of {$file->size} bytes).");
        }

        return $copy;
    }
}
