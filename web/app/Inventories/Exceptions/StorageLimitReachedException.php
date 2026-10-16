<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * Raised when the files of a workspace already weigh as much as the configuration allows
 * (`inventories.storage_per_workspace`), so that another file cannot be stored.
 */
final class StorageLimitReachedException extends RuntimeException
{
    /** The machine-readable reason the API gives (`code`). */
    public const CODE = 'storage_limit_reached';

    /**
     * Remembers the limit that was reached.
     *
     * @param  int  $limit  The limit, in bytes.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("The files of a workspace cannot weigh more than {$limit} bytes in all.");
    }
}
