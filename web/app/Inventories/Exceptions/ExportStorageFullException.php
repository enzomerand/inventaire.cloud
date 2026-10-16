<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * The exports a person has already take as much room as `inventories.export_storage_per_user` allows (those that did not expire
 * count, the size an export still being written is expected to have included), or the one asked for would take them past it: some
 * have to be deleted, or to expire, first. The API answers it with a 409.
 */
final class ExportStorageFullException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'export_storage_full';

    /**
     * Remembers the limit.
     *
     * @param  int  $limit  The limit, in bytes.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("The exports of a person cannot weigh more than {$limit} bytes in all.");
    }
}
