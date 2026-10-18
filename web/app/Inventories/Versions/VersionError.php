<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

/**
 * Why an official version failed: the code stored with it (`inventory_versions.error`) and what a person is told.
 */
enum VersionError: string
{
    /** The PDF could not be written. The cause is in the log of the server, not in front of the person. */
    case GenerationFailed = 'generation_failed';

    /** A PDF the library could not lay out in memory: too many items, or too much text. */
    case PdfTooLarge = 'pdf_too_large';

    /** The version never ended: its worker stopped, or the queue was not running. */
    case Stale = 'stale';

    /** The frozen data could not be stored on the disk. */
    case StorageFailed = 'storage_failed';

    /**
     * What a person is told, in the language of the request.
     */
    public function message(): string
    {
        return match ($this) {
            self::GenerationFailed => __('The PDF of this version could not be generated. Delete it and freeze the inventory again.'),
            self::PdfTooLarge => __('This version is too large to be written as a PDF. Delete it: a PDF holds fewer items than the inventory has.'),
            self::Stale => __('This version did not finish and was cancelled. Delete it and freeze the inventory again.'),
            self::StorageFailed => __('The data of this version could not be stored. Delete it and freeze the inventory again.'),
        };
    }
}
