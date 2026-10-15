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
 * personal workspace on the way ({@see Workspaces::current()}).
 */
final class CurrentWorkspaceRedirectController extends Controller
{
    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * Redirects to the current workspace, or to the sign-in page when the account no longer exists.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $workspace = $this->workspaces->current($this->user($request));

        return $workspace === null
            ? redirect()->route('login')
            : redirect()->route('app.workspace', ['workspace' => $workspace->ulid]);
    }
}
