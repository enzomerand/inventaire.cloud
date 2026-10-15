<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * Raised when an inventory already has as many fields as the configuration allows
 * (`inventories.max_fields`), so that another field cannot be added.
 */
final class FieldLimitReachedException extends RuntimeException
{
    /** The machine-readable reason the API gives (`code`). */
    public const CODE = 'field_limit_reached';

    /**
     * Remembers the quota that was reached.
     *
     * @param  int  $limit  The number of fields an inventory can hold.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("An inventory cannot hold more than {$limit} fields.");
    }
}
