<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;

/**
 * What the pages of a workspace (`/app/w/{workspace}`) do once the policy let the person in: they make the workspace the
 * one the account works in, so that the header, `/app` and the list of workspaces all agree with the page that is open.
 *
 * Opening a page is not a neutral act here, by decision: a person who opens the page of a workspace is working in it.
 * Only a change is written (an account already working there is left alone), and only for a workspace the person
 * belongs to, which the policy checked before this runs.
 */
trait EntersWorkspace
{
    /**
     * Records the workspace as the current one of the account when it is not, and gives back the role of the person in it.
     *
     * @param  Workspaces  $workspaces  The service that knows the memberships of the account.
     * @param  User  $user  The signed-in user, already allowed to see the workspace.
     */
    private function enter(Workspaces $workspaces, User $user, Workspace $workspace): WorkspaceRole
    {
        $membership = $workspaces->membership($user, $workspace);

        if ($membership !== null && ! $membership->current) {
            $workspaces->makeCurrent($user, $workspace);
        }

        return $membership->role ?? WorkspaceRole::Viewer;
    }
}
