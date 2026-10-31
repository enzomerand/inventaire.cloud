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
 * `/app/w/{workspace}/billing`: the plan of a workspace and its subscription.
 *
 * Every member of the workspace sees the page; somebody who does not belong to it is answered as if the workspace did not exist (404).
 * The page only frames what the API tells (the plan, the usage, the limits, and whether the person is an owner, who gets the buttons that
 * buy Pro and manage the subscription): the API judges the person again, and the page hands the script the addresses. It is also where
 * Stripe Checkout brings the person back (`?checkout=done`), whatever the page says: nothing is believed from that address. The page
 * exists while the instance sells no plan and says so. Opening it makes the workspace the current one of the account
 * ({@see EntersWorkspace}). The page hands the script the name and the address of the person, with which the declaration of a withdrawal
 * from the contract is prefilled.
 */
final class WorkspaceBillingPageController extends Controller
{
    use EntersWorkspace;

    /**
     * Gets the workspaces service, which knows the role of the person in the workspace.
     */
    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * Shows the subscription page of the workspace.
     */
    public function __invoke(Request $request, Workspace $workspace): View
    {
        Gate::authorize('view', $workspace);

        $user = $this->user($request);
        $this->enter($user, $workspace);

        // The name and the address the declaration of a withdrawal is prefilled with (the person edits them): the owner's own, from their account.
        return view('app.workspace-billing', [
            'workspace' => $workspace,
            'activeWorkspace' => $workspace->ulid,
            'person' => ['name' => $user->name, 'email' => $user->email],
        ]);
    }
}
