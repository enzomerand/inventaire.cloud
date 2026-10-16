<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Workspaces\Workspaces;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/app`: sends the signed-in user to the page of the workspace they are working in (see {@see WorkspacePageController}).
 *
 * The workspace is the one they chose, else the first one they joined; an account that somehow has none is given its
 * personal workspace on the way ({@see Workspaces::current()}). If it still has none (the account was deleted while
 * the request ran), the person is sent to the list of their workspaces, which renders without one: never to the login
 * page, which sends a signed-in visitor straight back here. Somebody who comes from the login page, where a page that needs
 * an account had sent them, goes back to that page instead.
 */
final class CurrentWorkspaceRedirectController extends Controller
{
    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * Redirects to the page of the current workspace, or to the list of workspaces when there is none.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $workspace = $this->workspaces->current($this->user($request));

        if ($workspace === null) {
            return redirect()->route('app.workspaces');
        }

        // A visitor who was sent to the login page by a page of /app (the "use this template" button, say) is taken back to
        // it: the framework remembered where they were going. Anybody else lands in their workspace.
        return redirect()->intended(route('app.workspace', ['workspace' => $workspace->ulid]));
    }
}
