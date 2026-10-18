<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * A request for signatures was to be sent again, but it did not fail (or its version did not complete): there is nothing to send
 * again. The API answers it with a 409.
 */
final class SignatureNotFailedException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'signature_not_failed';

    /**
     * Says what cannot be done.
     */
    public function __construct()
    {
        parent::__construct('The signature request did not fail: it cannot be sent again.');
    }
}
