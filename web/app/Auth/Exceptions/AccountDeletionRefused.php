<?php

declare(strict_types=1);

namespace App\Auth\Exceptions;

use App\Models\Workspace;
use RuntimeException;

/**
 * Raised when an account cannot be deleted yet, because deleting it would leave something without anyone in charge.
 *
 * `reason` is the machine-readable `code` the API answers with.
 */
final class AccountDeletionRefused extends RuntimeException
{
    /** The account is the last administrator of the instance. */
    public const LAST_ADMINISTRATOR = 'last_administrator';

    /** The account is the only owner of workspaces that other people belong to: ownership must be handed over first. */
    public const SOLE_OWNER_OF_SHARED_WORKSPACES = 'sole_owner_of_shared_workspaces';

    /**
     * @param  string  $reason  One of the constants above.
     * @param  list<Workspace>  $workspaces  For {@see self::SOLE_OWNER_OF_SHARED_WORKSPACES}: the workspaces to hand over.
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $workspaces = [],
    ) {
        parent::__construct("The account cannot be deleted: {$reason}.");
    }
}
