<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Policies\InventoryPolicy;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `/app/w/{workspace}`: the inventories of a workspace.
 *
 * The page only frames them: the list is read from the API by the script, which also offers to add one when the role
 * of the person allows it (the abilities are handed over in the markup). Somebody who does not belong to the
 * workspace is answered as if it did not exist (404).
 */
final class WorkspacePageController extends Controller
{
    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * Shows the page of the workspace.
     */
    public function __invoke(Request $request, Workspace $workspace): View
    {
        Gate::authorize('view', $workspace);

        $role = $this->workspaces->roleOf($this->user($request), $workspace) ?? WorkspaceRole::Viewer;

        return view('app.workspace', [
            'workspace' => $workspace,
            'abilities' => InventoryPolicy::abilities($role),
            'activeWorkspace' => $workspace->ulid,
        ]);
    }
}
