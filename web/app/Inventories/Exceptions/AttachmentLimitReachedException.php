<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * Raised when an item already holds as many files as the configuration allows
 * (`inventories.max_attachments_per_item`), so that another file cannot be attached.
 */
final class AttachmentLimitReachedException extends RuntimeException
{
    /** The machine-readable reason the API gives (`code`). */
    public const CODE = 'attachment_limit_reached';

    /**
     * Remembers the limit that was reached.
     *
     * @param  int  $limit  The limit, in files.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("An item cannot hold more than {$limit} files.");
    }
}
