<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * An export that cannot be made for what the inventory holds: a PDF of more items than a PDF may hold, a zip of files when the
 * items in scope have none. The API answers it with a 422 on the `format` of the request, and the `code` that says which.
 */
final class ExportRefusedException extends RuntimeException
{
    /** A PDF of more items than the library can lay out in memory. */
    public const PDF_TOO_LARGE = 'pdf_too_large';

    /** A zip of the files of items that have none. */
    public const NOTHING_TO_ZIP = 'zip_without_files';

    /**
     * Remembers why, and the limit when the reason is one.
     *
     * @param  string  $reason  {@see self::PDF_TOO_LARGE} or {@see self::NOTHING_TO_ZIP}.
     * @param  int|null  $limit  The most items a PDF of this inventory may hold.
     */
    public function __construct(public readonly string $reason, public readonly ?int $limit = null)
    {
        parent::__construct($reason === self::PDF_TOO_LARGE ? "A PDF of this inventory cannot hold more than {$limit} items." : 'There is no file to put in the zip.');
    }
}
