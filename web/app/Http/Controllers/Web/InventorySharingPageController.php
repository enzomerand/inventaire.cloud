<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\EntersWorkspace;
use App\Models\Inventory;
use App\Models\Workspace;
use App\Settings\InstanceSettings;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `/app/w/{workspace}/inventories/{inventory}/sharing`: the share links of an inventory, for the people who may share it (the owners of the
 * workspace: an editor or a viewer, who belong to it, are refused with a 403, somebody who does not belong to it is told the inventory does
 * not exist).
 *
 * The list and the actions go through the API: the page hands the script the address of the links, whether the administrator allows
 * sharing (when not, the page says so and offers nothing but the list and the revocation of the links that exist) and how many links an
 * inventory may have. Opening the page makes the workspace the current one of the account ({@see EntersWorkspace}).
 */
final class InventorySharingPageController extends Controller
{
    use EntersWorkspace;

    /**
     * Gets the workspaces service, which knows the role of the person in the workspace, and the settings of the instance.
     */
    public function __construct(private readonly Workspaces $workspaces, private readonly InstanceSettings $settings) {}

    /**
     * Shows the sharing page of the inventory.
     */
    public function __invoke(Request $request, Workspace $workspace, Inventory $inventory): View
    {
        Gate::authorize('share', $inventory);

        $this->enter($this->user($request), $workspace);

        return view('app.inventory-sharing', [
            'workspace' => $workspace,
            'inventory' => $inventory,
            'enabled' => $this->settings->shareLinksEnabled(),
            'limit' => config()->integer('inventories.max_share_links'),
            'activeWorkspace' => $workspace->ulid,
        ]);
    }
}
