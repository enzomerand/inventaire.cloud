<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * Raised when a workspace already holds as many inventories as the configuration allows
 * (`inventories.max_per_workspace`), so that another inventory cannot be added.
 */
final class InventoryLimitReachedException extends RuntimeException
{
    /** The machine-readable reason the API gives (`code`). */
    public const CODE = 'inventory_limit_reached';

    /**
     * @param  int  $limit  The number of inventories a workspace can hold.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("A workspace cannot hold more than {$limit} inventories.");
    }
}
