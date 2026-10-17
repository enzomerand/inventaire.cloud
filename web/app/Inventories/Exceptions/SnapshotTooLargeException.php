<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * The snapshot of an inventory outgrew `inventories.max_snapshot_bytes` while it was being written: the freeze is aborted (the
 * transaction is rolled back, the scratch file deleted) and the API answers it with a 422 `pdf_too_large`, since a version whose data
 * is that heavy could not be read back to write its PDF.
 */
final class SnapshotTooLargeException extends RuntimeException
{
    /**
     * Remembers the limit.
     *
     * @param  int  $limit  The most bytes a snapshot may weigh.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("The snapshot of an official version cannot weigh more than {$limit} bytes.");
    }
}
