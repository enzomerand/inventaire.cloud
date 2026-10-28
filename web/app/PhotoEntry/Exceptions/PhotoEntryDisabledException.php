<?php

declare(strict_types=1);

namespace App\PhotoEntry\Exceptions;

use App\PhotoEntry\PhotoEntry;
use RuntimeException;

/**
 * Photos were sent for an inventory of a workspace that may not use the photo entry: the administrator has it off, the instance has no
 * API key, the owner of the workspace did not turn it on, or its plan does not include it (see {@see PhotoEntry::enabledFor()}). The API answers it with a 409.
 */
final class PhotoEntryDisabledException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'photo_entry_disabled';

    /**
     * Builds the refusal.
     */
    public function __construct()
    {
        parent::__construct('The photo entry is not enabled for this workspace.');
    }
}
