<?php

declare(strict_types=1);

namespace App\Auth\Exceptions;

use RuntimeException;

/**
 * A person already has as many API tokens as `inventories.max_api_tokens` allows (those that expired do not count): one has to be
 * revoked, or to expire, before another can be made. The API answers it with a 409.
 */
final class ApiTokenLimitReachedException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'token_limit_reached';

    /**
     * Remembers the limit.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("A person cannot have more than {$limit} API tokens at once.");
    }
}
