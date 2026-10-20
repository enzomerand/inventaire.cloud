<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\EntersWorkspace;
use App\Models\Workspace;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `/app/w/{workspace}/members`: who belongs to a workspace, and what each of them is.
 *
 * Every member of the workspace sees the page; somebody who does not belong to it is answered as if the workspace did not exist
 * (404). The owner is also offered what only the owner may do (change a role, remove a member, hand the ownership over, invite and
 * withdraw invitations); the other members get the list and the way to leave. The page only frames them: the list and the actions go
 * through the API, which judges the person again, and the page hands the scripts the addresses and whether the person is the owner.
 * Opening the page makes the workspace the current one of the account ({@see EntersWorkspace}).
 */
final class WorkspaceMembersPageController extends Controller
{
    use EntersWorkspace;

    /**
     * Gets the workspaces service, which knows the role of the person in the workspace.
     */
    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * Shows the page of the members of the workspace.
     */
    public function __invoke(Request $request, Workspace $workspace): View
    {
        Gate::authorize('view', $workspace);

        $role = $this->enter($this->user($request), $workspace);

        return view('app.workspace-members', [
            'workspace' => $workspace,
            'isOwner' => $role->canManage(),
            'activeWorkspace' => $workspace->ulid,
        ]);
    }
}
