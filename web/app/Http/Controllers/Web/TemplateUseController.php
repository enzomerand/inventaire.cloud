<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Inventories\Templates\TemplateCatalogue;
use App\Workspaces\Membership;
use App\Workspaces\Workspaces;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/app/templates/{slug}`: where the "use this template" button of a public page leads.
 *
 * It sends the person to the page of a workspace where they may add inventories, with `?template=<slug>`, which opens the
 * dialog of a new inventory with the template chosen: the workspace they are working in if they may edit it, else the
 * first one they joined where they may. A person who may edit none (a viewer everywhere) goes to the list of their
 * workspaces, with a notice. A visitor who is not signed in is sent to the login page first (the route requires a session)
 * and comes back here after signing in (see {@see CurrentWorkspaceRedirectController}). An unknown slug is a 404.
 */
final class TemplateUseController extends Controller
{
    /**
     * Gets the catalogue and the service that knows the workspaces of a person.
     */
    public function __construct(
        private readonly TemplateCatalogue $catalogue,
        private readonly Workspaces $workspaces,
    ) {}

    /**
     * Redirects to the workspace where the inventory is to be made.
     */
    public function __invoke(Request $request, string $slug): RedirectResponse
    {
        $template = $this->catalogue->find($slug) ?? abort(404);
        $memberships = array_values(array_filter(
            $this->workspaces->memberships($this->user($request)),
            fn (Membership $membership): bool => $membership->role->canEdit(),
        ));

        if ($memberships === []) {
            return redirect()->route('app.workspaces')->with('template_needs_workspace', true);
        }

        // The memberships come oldest first: the current workspace if it is one of them, else the first.
        $chosen = collect($memberships)->first(fn (Membership $membership): bool => $membership->current) ?? $memberships[0];

        return redirect()->route('app.workspace', ['workspace' => $chosen->workspace->ulid, 'template' => $template->slug]);
    }
}
