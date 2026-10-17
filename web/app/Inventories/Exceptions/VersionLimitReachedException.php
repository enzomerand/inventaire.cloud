<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * An inventory already holds as many official versions as `inventories.max_versions_per_inventory` allows: one has to be deleted
 * before the inventory can be frozen again. The API answers it with a 409.
 */
final class VersionLimitReachedException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'version_limit_reached';

    /**
     * Remembers the limit.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("An inventory cannot hold more than {$limit} official versions.");
    }
}
