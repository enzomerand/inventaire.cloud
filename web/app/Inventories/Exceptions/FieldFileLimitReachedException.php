<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * Raised when a file or photo field already holds as many files as its `max_files`
 * option allows, so that another file cannot be added to it.
 */
final class FieldFileLimitReachedException extends RuntimeException
{
    /** The machine-readable reason the API gives (`code`). */
    public const CODE = 'field_file_limit_reached';

    /**
     * Remembers the limit that was reached.
     *
     * @param  int  $limit  The limit, in files.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("A field cannot hold more than {$limit} files.");
    }
}
