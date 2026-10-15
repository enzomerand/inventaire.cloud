<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\EntersWorkspace;
use App\Models\Inventory;
use App\Models\Workspace;
use App\Policies\InventoryPolicy;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `/app/w/{workspace}/inventories/{inventory}`: the items of an inventory.
 *
 * The inventory is looked up inside the workspace of the path (a scoped binding), so one that belongs to another
 * workspace is a 404, and so is any inventory of a workspace the person does not belong to. The table and the forms are
 * driven by the scripts through the API; the page hands them the abilities of the person. Opening the page makes the
 * workspace the current one of the account ({@see EntersWorkspace}).
 */
final class InventoryPageController extends Controller
{
    use EntersWorkspace;

    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * Shows the page of the inventory.
     */
    public function __invoke(Request $request, Workspace $workspace, Inventory $inventory): View
    {
        Gate::authorize('view', $inventory);

        $role = $this->enter($this->user($request), $workspace);

        return view('app.inventory', [
            'workspace' => $workspace,
            'inventory' => $inventory,
            'abilities' => InventoryPolicy::abilities($role),
            'activeWorkspace' => $workspace->ulid,
        ]);
    }
}
