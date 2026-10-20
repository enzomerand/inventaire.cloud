<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * The administrator of the instance turned sharing by link off (`share_links_enabled`): no link can be made, and the ones that exist
 * answer as if they did not. The API answers it with a 409.
 */
final class ShareLinksDisabledException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'share_links_disabled';

    /**
     * Builds the refusal.
     */
    public function __construct()
    {
        parent::__construct('Sharing by link is turned off on this instance.');
    }
}
