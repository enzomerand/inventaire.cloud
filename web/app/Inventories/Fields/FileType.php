<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

/**
 * A file or several: an invoice, a datasheet, a manual. The files are uploaded to the field (see {@see UploadedFilesType}),
 * each one of any type and up to the maximum size of an attachment.
 */
final class FileType extends UploadedFilesType
{
    /**
     * The word that identifies the type in the API and in the database: `file`.
     */
    public function key(): string
    {
        return 'file';
    }

    /**
     * What the type is called for a person ("Attached file"), in the language of the request.
     */
    public function label(): string
    {
        return __('Attached file');
    }

    /**
     * Whether the field takes images only.
     */
    public function imagesOnly(): bool
    {
        return false;
    }
}
