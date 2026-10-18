<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\EntersWorkspace;
use App\Inventories\Versions\OfficialVersions;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Models\Workspace;
use App\Policies\InventoryVersionPolicy;
use App\Signatures\Documenso;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `/app/w/{workspace}/inventories/{inventory}/versions`: the official versions of an inventory, to read, download, create (with
 * signatures if the instance has a signature service), and for the owners to delete.
 *
 * Every member of the workspace sees the page (a viewer reads and downloads); somebody who does not belong to it is told the inventory
 * does not exist (404). The list and the actions go through the API: the page hands the script the addresses, what the role allows
 * (freezing is for editors and owners; what a version may be asked, cancelled or deleted comes with each version), whether the instance
 * can ask for signatures, and how many signers a version may have. Opening the page makes the workspace the current one of the
 * account ({@see EntersWorkspace}).
 */
final class InventoryVersionsPageController extends Controller
{
    use EntersWorkspace;

    /**
     * Gets the workspaces service, which knows the role of the person in the workspace.
     */
    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * Shows the page of the versions of the inventory.
     */
    public function __invoke(Request $request, Workspace $workspace, Inventory $inventory): View
    {
        Gate::authorize('viewAny', [InventoryVersion::class, $inventory]);

        $role = $this->enter($this->user($request), $workspace);

        return view('app.inventory-versions', [
            'workspace' => $workspace,
            'inventory' => $inventory,
            'abilities' => InventoryVersionPolicy::abilities($role),
            'signaturesAvailable' => Documenso::configured(),
            'maxSigners' => OfficialVersions::maxSigners(),
            'activeWorkspace' => $workspace->ulid,
        ]);
    }
}
