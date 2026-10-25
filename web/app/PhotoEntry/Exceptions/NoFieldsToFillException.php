<?php

declare(strict_types=1);

namespace App\PhotoEntry\Exceptions;

use RuntimeException;

/**
 * Photos were sent for an inventory that has no field a photo can fill: it has none at all, or only file and photo fields. There is
 * nothing to ask Claude, so nothing is sent to it, and the API answers with a 409.
 */
final class NoFieldsToFillException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'no_fields_to_fill';

    /**
     * Builds the refusal.
     */
    public function __construct()
    {
        parent::__construct('The inventory has no field that photos can fill.');
    }
}
