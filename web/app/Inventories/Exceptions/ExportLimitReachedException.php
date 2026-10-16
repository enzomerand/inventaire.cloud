<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * A person already has as many exports of an inventory as `inventories.max_active_exports` allows (those that did not expire count,
 * whatever their state): one has to be deleted, or to expire, before another can be asked for. The API answers it with a 409.
 */
final class ExportLimitReachedException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'export_limit_reached';

    /**
     * Remembers the limit.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("A person cannot have more than {$limit} exports of an inventory at once.");
    }
}
