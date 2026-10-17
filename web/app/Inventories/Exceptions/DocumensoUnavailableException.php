<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * Documenso could not do what was asked of it right now (it cannot be reached, it is failing, or it refuses the token of the
 * instance): the person may try again later. The API answers it with a 503.
 */
final class DocumensoUnavailableException extends RuntimeException
{
    /** The machine-readable code of the 503. */
    public const CODE = 'documenso_unavailable';

    /**
     * Says what is not available.
     */
    public function __construct()
    {
        parent::__construct('The signature service cannot be used right now.');
    }
}
