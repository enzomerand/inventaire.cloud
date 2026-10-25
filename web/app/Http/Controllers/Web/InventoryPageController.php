<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\EntersWorkspace;
use App\Models\Inventory;
use App\Models\Workspace;
use App\PhotoEntry\PhotoEntry;
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
 * driven by the scripts through the API; the page hands them the abilities of the person, and whether they may add an item from
 * photos ({@see PhotoEntry}: the instance and the workspace turned it on, and the person may add items). Opening the page makes
 * the workspace the current one of the account ({@see EntersWorkspace}).
 */
final class InventoryPageController extends Controller
{
    use EntersWorkspace;

    public function __construct(private readonly Workspaces $workspaces, private readonly PhotoEntry $photoEntry) {}

    /**
     * Shows the page of the inventory.
     */
    public function __invoke(Request $request, Workspace $workspace, Inventory $inventory): View
    {
        Gate::authorize('view', $inventory);

        $role = $this->enter($this->user($request), $workspace);
        $abilities = InventoryPolicy::abilities($role);

        return view('app.inventory', [
            'workspace' => $workspace,
            'inventory' => $inventory,
            'abilities' => $abilities,
            'photoEntry' => $abilities['edit_items'] && $this->photoEntry->enabledFor($workspace),
            'activeWorkspace' => $workspace->ulid,
        ]);
    }
}
