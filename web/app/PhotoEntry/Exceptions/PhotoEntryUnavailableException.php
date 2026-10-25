<?php

declare(strict_types=1);

namespace App\PhotoEntry\Exceptions;

use App\PhotoEntry\PhotoEntry;
use RuntimeException;

/**
 * The owner of a workspace tried to turn the photo entry on while the instance does not offer it: the administrator has it off, or there
 * is no Anthropic API key (see {@see PhotoEntry::availableOnInstance()}). The API answers it with a 409. Turning it off is always allowed.
 */
final class PhotoEntryUnavailableException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'photo_entry_unavailable';

    /**
     * Builds the refusal.
     */
    public function __construct()
    {
        parent::__construct('The photo entry is not available on this instance.');
    }
}
