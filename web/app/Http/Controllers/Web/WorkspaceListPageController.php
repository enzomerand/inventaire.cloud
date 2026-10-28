<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Billing\WorkspaceLimits;
use App\Http\Controllers\Controller;
use App\PhotoEntry\PhotoEntry;
use App\Workspaces\Membership;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * `/app/workspaces`: the workspaces of the signed-in user, to create, rename, delete and choose between.
 *
 * The page also hands the markup the consent of the workspaces the person owns to the photo entry, as `{ulid: bool}`, because the list
 * endpoint (`GET /workspaces`) is a token route that does not carry it: only when the instance offers the feature (else `null`, and the
 * page shows no switch), and only for the workspaces the person owns, since the owner alone chooses. Read each time, so that a change of
 * the administrator shows on the next visit. On an instance that sells plans it also says which of those workspaces are on a plan that
 * does not include the photo entry (Free): their switch is shown off and disabled, with the way to the subscription page.
 */
final class WorkspaceListPageController extends Controller
{
    /**
     * Shows the workspaces page.
     */
    public function __invoke(Request $request, Workspaces $workspaces, PhotoEntry $photoEntry): View
    {
        $owned = array_values(array_filter(
            $workspaces->memberships($this->user($request)),
            fn (Membership $membership): bool => $membership->role->canManage(),
        ));
        $consents = $this->consents($owned, $photoEntry);

        return view('app.workspaces', [
            'photoEntry' => $consents,
            'photoEntryLocked' => $consents === null ? [] : $this->lockedByPlan($owned),
        ]);
    }

    /**
     * The ULIDs of the workspaces the person owns whose plan does not include the photo entry ({@see WorkspaceLimits::photoEntry()}: none
     * while the instance sells no plan).
     *
     * @param  list<Membership>  $owned  The workspaces the person owns.
     * @return list<string>
     */
    private function lockedByPlan(array $owned): array
    {
        $locked = [];

        foreach ($owned as $membership) {
            if (! WorkspaceLimits::photoEntry($membership->workspace)) {
                $locked[] = $membership->workspace->ulid;
            }
        }

        return $locked;
    }

    /**
     * The consent of each workspace the user owns (by ULID), or null when the instance does not offer the photo entry.
     *
     * @param  list<Membership>  $owned  The workspaces the person owns.
     * @return array<string, bool>|null
     */
    private function consents(array $owned, PhotoEntry $photoEntry): ?array
    {
        if (! $photoEntry->availableOnInstance()) {
            return null;
        }

        $consents = [];

        foreach ($owned as $membership) {
            $consents[$membership->workspace->ulid] = $membership->workspace->photo_entry_enabled;
        }

        return $consents;
    }
}
