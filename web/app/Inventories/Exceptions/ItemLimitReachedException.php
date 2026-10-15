<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * Raised when an inventory already holds as many items as the configuration allows
 * (`inventories.max_items`), so that another item cannot be added.
 */
final class ItemLimitReachedException extends RuntimeException
{
    /** The machine-readable reason the API gives (`code`). */
    public const CODE = 'item_limit_reached';

    /**
     * @param  int  $limit  The number of items an inventory can hold.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("An inventory cannot hold more than {$limit} items.");
    }
}
