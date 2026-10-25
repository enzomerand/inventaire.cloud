<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\PhotoEntry\PhotoEntry;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * `/app/workspaces`: the workspaces of the signed-in user, to create, rename, delete and choose between.
 *
 * The page also hands the markup the consent of the workspaces the person owns to the photo entry, as `{ulid: bool}`, because the list
 * endpoint (`GET /workspaces`) is a token route that does not carry it: only when the instance offers the feature (else `null`, and the
 * page shows no switch), and only for the workspaces the person owns, since the owner alone chooses. Read each time, so that a change of
 * the administrator shows on the next visit.
 */
final class WorkspaceListPageController extends Controller
{
    /**
     * Shows the workspaces page.
     */
    public function __invoke(Request $request, Workspaces $workspaces, PhotoEntry $photoEntry): View
    {
        return view('app.workspaces', ['photoEntry' => $this->consents($this->user($request), $workspaces, $photoEntry)]);
    }

    /**
     * The consent of each workspace the user owns (by ULID), or null when the instance does not offer the photo entry.
     *
     * @return array<string, bool>|null
     */
    private function consents(User $user, Workspaces $workspaces, PhotoEntry $photoEntry): ?array
    {
        if (! $photoEntry->availableOnInstance()) {
            return null;
        }

        $consents = [];

        foreach ($workspaces->memberships($user) as $membership) {
            if ($membership->role->canManage()) {
                $consents[$membership->workspace->ulid] = $membership->workspace->photo_entry_enabled;
            }
        }

        return $consents;
    }
}
