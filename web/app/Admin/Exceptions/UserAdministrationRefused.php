<?php

declare(strict_types=1);

namespace App\Admin\Exceptions;

use RuntimeException;

/**
 * Raised when an administrator asks for something the rules of the instance do not allow.
 *
 * `reason` is the machine-readable `code` the API answers with.
 */
final class UserAdministrationRefused extends RuntimeException
{
    /** An account already exists for the address. */
    public const ACCOUNT_EXISTS = 'account_exists';

    /** The change would leave the instance without an administrator. */
    public const LAST_ADMINISTRATOR = 'last_administrator';

    /** The administrator who asked is not one any more: they were demoted (or deleted) while the request was handled. */
    public const NOT_ADMINISTRATOR = 'not_administrator';

    /** An administrator asked to delete their own account here: that is done from the account page, with a code. */
    public const SELF_DELETION = 'self_deletion';

    /**
     * @param  string  $reason  One of the constants above.
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The request is refused: {$reason}.");
    }
}
