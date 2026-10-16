<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * A person already has as many unfinished imports of an inventory as `inventories.max_active_imports` allows (sent, waiting or
 * running): one has to end, or to be deleted, before another file can be sent. The API answers it with a 409.
 */
final class ImportLimitReachedException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'import_limit_reached';

    /**
     * Remembers the limit.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("A person cannot have more than {$limit} unfinished imports of an inventory at once.");
    }
}
