<?php

declare(strict_types=1);

namespace App\Workspaces\Exceptions;

use RuntimeException;

/**
 * Raised when a workspace cannot be deleted because it is the last one its member belongs to: every account keeps
 * at least one workspace.
 */
final class LastWorkspaceException extends RuntimeException
{
    /** The machine-readable reason the API gives (`code`). */
    public const CODE = 'last_workspace';

    public function __construct()
    {
        parent::__construct('A workspace that is the last of its member cannot be deleted.');
    }
}
