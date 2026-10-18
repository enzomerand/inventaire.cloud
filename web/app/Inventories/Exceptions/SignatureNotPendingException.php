<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * A request for signatures was to be cancelled, but it is not waiting for its signers (any more): it was never sent, it is over, or
 * Documenso says it is not pending. The API answers it with a 409.
 */
final class SignatureNotPendingException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'signature_not_pending';

    /**
     * Says what cannot be done.
     */
    public function __construct()
    {
        parent::__construct('The signature request is not waiting for its signers: it cannot be cancelled.');
    }
}
