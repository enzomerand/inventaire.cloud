<?php

declare(strict_types=1);

namespace App\Workspaces\Exceptions;

use RuntimeException;

/**
 * Raised when an account already belongs to as many workspaces as the configuration allows
 * (`workspaces.max_per_user`), so that it cannot create another one.
 */
final class WorkspaceLimitReachedException extends RuntimeException
{
    /** The machine-readable reason the API gives (`code`). */
    public const CODE = 'workspace_limit_reached';

    /**
     * @param  int  $limit  The number of workspaces an account can belong to.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct("An account cannot belong to more than {$limit} workspaces.");
    }
}
