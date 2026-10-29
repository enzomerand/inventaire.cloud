<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Billing\Billing;
use App\Http\Controllers\Controller;
use App\Workspaces\Workspaces;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/app/billing`: sends the signed-in user to the subscription page of the workspace they are working in (see
 * {@see WorkspaceBillingPageController}), for the links that cannot know which workspace that is. A visitor who is not signed in goes
 * through the login page and comes back here (`/app` sends them on to this address). With no workspace to work in the person is sent to the
 * list of their workspaces, as `/app` does.
 *
 * The pricing page links here with the period the visitor chose (`?interval=month|year`): it is handed on to the page, which selects it
 * (when the instance has such a Price). Anything else in the query is dropped.
 */
final class CurrentWorkspaceBillingRedirectController extends Controller
{
    /**
     * Gets the workspaces service, which knows the workspace the person works in.
     */
    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * Redirects to the subscription page of the current workspace, or to the list of workspaces when there is none.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $workspace = $this->workspaces->current($this->user($request));

        if ($workspace === null) {
            return redirect()->route('app.workspaces');
        }

        $interval = $request->query('interval');

        return redirect()->route('app.workspace.billing', ['workspace' => $workspace->ulid] + (is_string($interval) && in_array($interval, Billing::INTERVALS, true) ? ['interval' => $interval] : []));
    }
}
