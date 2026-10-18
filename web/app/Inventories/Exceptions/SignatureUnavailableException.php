<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * Signatures were asked for, but the instance has no Documenso to send them to (`services.documenso`). The API answers it with a 409.
 */
final class SignatureUnavailableException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'signature_unavailable';

    /**
     * Says what is missing.
     */
    public function __construct()
    {
        parent::__construct('Signatures cannot be asked for: this instance has no Documenso configured.');
    }
}
