<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Fields\UploadedFilesType;
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
 * photos are compressed already. A file that is on the local disk is added from where it is; one on another disk (S3) is copied
 * to a scratch file first, deleted once the zip is closed. A file that is gone from its disk is skipped and logged: the export of
 * the others goes on.
 */
final class ZipWriter implements ExportWriter
{
    /** The files an export of photos holds. */
    public const PHOTOS = 'photos';

    /** The files an export of everything holds. */
    public const ALL = 'all';

    /**
     * Gets the storage the files are read from.
     */
    public function __construct(private readonly AttachmentStorage $storage) {}

    /**
     * Writes the zip.
     *
     * @throws RuntimeException When the zip cannot be written.
     */
    public function write(ExportContext $context, string $path): int
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The zip of the export could not be created.');
        }

        $photoFields = $this->photoFields($context);
        $everything = $context->export->option('files', self::PHOTOS) === self::ALL;
        $scratch = [];
        $items = 0;
        $entries = 0;

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

            // An archive without an entry is not written at all: leave the empty one a reader accepts (just its end marker).
            if ($entries === 0) {
                $zip->close();
                file_put_contents($path, "PK\x05\x06".str_repeat("\0", 18));
            } elseif (! $zip->close()) {
                throw new RuntimeException('The zip of the export could not be written.');
            }
        } finally {
            foreach ($scratch as $file) {
                @unlink($file);
            }
        }

        return $items;
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

        return ExportNames::entry($label, __('Item')).' - '.$item->ulid;
    }

    /**
     * The photo fields of the inventory, keyed by id: the files of an export of photos are the ones that fill them.
     *
     * @return array<int, true>
     */
    private function photoFields(ExportContext $context): array
    {
        $ids = [];

        foreach ($context->fields as $position => $field) {
            $type = $context->table->typeAt($position);

            if ($type instanceof UploadedFilesType && $type->imagesOnly()) {
                $ids[(int) $field->getKey()] = true;
            }
        }

        return $ids;
    }

    /**
     * A local path to read a file from: where it is when its disk is local, a scratch copy otherwise. Null when the file is not
     * there (it is logged).
     *
     * @param  list<string>  $scratch  The scratch files made so far, which this adds to.
     */
    private function source(Attachment $file, array &$scratch): ?string
    {
        try {
            $disk = $this->storage->disk($file->disk);

            if (config("filesystems.disks.{$file->disk}.driver") === 'local') {
                $local = $disk->path($file->path);

                if (is_file($local)) {
                    return $local;
                }
            }

            $stream = $disk->readStream($file->path);

            if (! is_resource($stream)) {
                throw new RuntimeException('The file is not on its disk.');
            }

            $copy = ExportTemp::file();
            $scratch[] = $copy;
            $target = fopen($copy, 'wb');

            if ($target === false) {
                throw new RuntimeException('A scratch file could not be opened.');
            }

            try {
                stream_copy_to_stream($stream, $target);
            } finally {
                fclose($stream);
                fclose($target);
            }

            return $copy;
        } catch (Throwable $exception) {
            Log::warning('A file could not be read for a zip export: it is left out.', ['attachment' => $file->ulid, 'disk' => $file->disk, 'exception' => $exception]);

            return null;
        }
    }
}
