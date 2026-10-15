<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;

/**
 * A workspace as one of its members sees it: the role they hold in it, and whether it is the one they are working
 * in right now.
 *
 * What the API describes (see {@see WorkspaceResource}) and the switcher of the header lists;
 * {@see Workspaces::memberships()} builds them for a {@see User}.
 */
final readonly class Membership
{
    /**
     * @param  Workspace  $workspace  The workspace.
     * @param  WorkspaceRole  $role  What the member may do in it.
     * @param  bool  $current  Whether it is the member's current workspace.
     */
    public function __construct(
        public Workspace $workspace,
        public WorkspaceRole $role,
        public bool $current,
    ) {}
}
