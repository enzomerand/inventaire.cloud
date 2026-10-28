<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Billing\Billing;
use App\Billing\WorkspaceLimits;
use App\Billing\WorkspacePlan;
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
 * page shows no switch, and no workspace is read for it), and only for the workspaces the person owns, since the owner alone chooses.
 * Read each time, so that a change of the administrator shows on the next visit. On an instance that sells plans it also says which of
 * those workspaces are on a plan that includes the photo entry (Pro; `null` when the instance sells no plan): the switch of any other, a
 * workspace created on the page since included, is shown off and disabled, with the way to the subscription page.
 */
final class WorkspaceListPageController extends Controller
{
    /**
     * Shows the workspaces page.
     */
    public function __invoke(Request $request, Workspaces $workspaces, PhotoEntry $photoEntry): View
    {
        // Nothing is read for an instance that does not offer the photo entry: the page has no switch to fill, and the plans of the
        // workspaces (billing) only matter to those switches.
        if (! $photoEntry->availableOnInstance()) {
            return view('app.workspaces', ['photoEntry' => null, 'photoEntryUnlocked' => null]);
        }

        $owned = array_values(array_filter(
            $workspaces->memberships($this->user($request)),
            fn (Membership $membership): bool => $membership->role->canManage(),
        ));

        return view('app.workspaces', [
            'photoEntry' => $this->consents($owned),
            'photoEntryUnlocked' => $this->unlockedByPlan($owned),
        ]);
    }

    /**
     * The ULIDs of the workspaces the person owns whose plan includes the photo entry ({@see WorkspaceLimits::photoEntry()}), or null while
     * the instance sells no plan (every workspace has it). A list of those that have it, and not of those that do not: a workspace created
     * on the page afterwards is not in it, and is on Free like every new workspace. The plans are read in one query.
     *
     * @param  list<Membership>  $owned  The workspaces the person owns.
     * @return list<string>|null
     */
    private function unlockedByPlan(array $owned): ?array
    {
        if (! Billing::enabled()) {
            return null;
        }

        WorkspacePlan::preload(array_map(fn (Membership $membership): int => (int) $membership->workspace->getKey(), $owned));
        $unlocked = [];

        foreach ($owned as $membership) {
            if (WorkspaceLimits::photoEntry($membership->workspace)) {
                $unlocked[] = $membership->workspace->ulid;
            }
        }

        return $unlocked;
    }

    /**
     * The consent of each workspace the user owns (by ULID).
     *
     * @param  list<Membership>  $owned  The workspaces the person owns.
     * @return array<string, bool>
     */
    private function consents(array $owned): array
    {
        $consents = [];

        foreach ($owned as $membership) {
            $consents[$membership->workspace->ulid] = $membership->workspace->photo_entry_enabled;
        }

        return $consents;
    }
}
