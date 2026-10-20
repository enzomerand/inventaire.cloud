<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * An inventory already has as many share links that still work as `inventories.max_share_links` allows (the ones that expired do not
 * count): one has to be revoked before another can be made. The API answers it with a 409.
 */
final class ShareLinkLimitReachedException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'share_link_limit_reached';

    /**
     * Remembers the limit.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("An inventory cannot have more than {$limit} share links.");
    }
}
