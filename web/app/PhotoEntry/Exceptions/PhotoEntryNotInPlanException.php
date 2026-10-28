<?php

declare(strict_types=1);

namespace App\PhotoEntry\Exceptions;

use App\PhotoEntry\PhotoEntry;
use RuntimeException;

/**
 * Photos were sent for an inventory of a workspace whose plan does not include the photo entry (billing is on and the workspace is Free,
 * see {@see PhotoEntry::refusedByPlan()}), on an instance that does offer it. Told apart from {@see PhotoEntryDisabledException} because
 * it is the refusal a person can mend, by subscribing to Pro. The API answers it with a 409.
 */
final class PhotoEntryNotInPlanException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'photo_entry_not_in_plan';

    /**
     * Builds the refusal.
     */
    public function __construct()
    {
        parent::__construct('The plan of this workspace does not include the photo entry.');
    }
}
