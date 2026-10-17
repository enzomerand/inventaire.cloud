<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * An import that cannot be changed or started any more: it was started already (it is no longer `uploaded`), or its file expired.
 * The API answers a 409 (`import_not_editable`) for the first and a 404 (`import_expired`) for the second.
 */
final class ImportNotEditableException extends RuntimeException
{
    /** The machine-readable code of the 409. */
    public const CODE = 'import_not_editable';

    /** The machine-readable code of the 404. */
    public const EXPIRED = 'import_expired';

    /**
     * Remembers which of the two it is.
     *
     * @param  string  $reason  {@see self::CODE} or {@see self::EXPIRED}.
     */
    public function __construct(public readonly string $reason = self::CODE)
    {
        parent::__construct($reason === self::EXPIRED ? 'The import has expired.' : 'The import has started and cannot be changed.');
    }
}
