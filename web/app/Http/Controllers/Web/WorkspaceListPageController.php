<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Billing\Billing;
use App\Billing\Plan;
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
 * workspace created on the page since included, is shown off and disabled, with the way to the subscription page. The page also says which
 * of those workspaces have a live subscription, so that deleting one warns that it ends at once, without a refund of the period in progress.
 */
final class WorkspaceListPageController extends Controller
{
    /**
     * Shows the workspaces page.
     */
    public function __invoke(Request $request, Workspaces $workspaces, PhotoEntry $photoEntry): View
    {
        // Nothing is read for an instance that offers neither the photo entry (the page has no switch to fill, and the plans of the
        // workspaces only matter to those switches) nor plans (no subscription to warn about when a workspace is deleted).
        if (! $photoEntry->availableOnInstance() && ! Billing::enabled()) {
            return view('app.workspaces', ['photoEntry' => null, 'photoEntryUnlocked' => null, 'liveSubscriptions' => []]);
        }

        $owned = array_values(array_filter(
            $workspaces->memberships($this->user($request)),
            fn (Membership $membership): bool => $membership->role->canManage(),
        ));

        // The plans of all of them in one query: the photo entry switches and the warning about a live subscription both ask.
        WorkspacePlan::preload(array_map(fn (Membership $membership): int => (int) $membership->workspace->getKey(), $owned));

        return view('app.workspaces', [
            'photoEntry' => $photoEntry->availableOnInstance() ? $this->consents($owned) : null,
            'photoEntryUnlocked' => $photoEntry->availableOnInstance() ? $this->unlockedByPlan($owned) : null,
            'liveSubscriptions' => $this->withLiveSubscription($owned),
        ]);
    }

    /**
     * The ULIDs of the workspaces the person owns that have a live subscription (one that gives Pro): deleting one ends it at once, without
     * a refund of the period in progress, which the page says before the deletion is confirmed. Empty while the instance sells no plan. A live
     * subscription is one that gives the Pro plan, read from the plans loaded for all the workspaces together.
     *
     * @param  list<Membership>  $owned  The workspaces the person owns.
     * @return list<string>
     */
    private function withLiveSubscription(array $owned): array
    {
        if (! Billing::enabled()) {
            return [];
        }

        return array_values(array_map(
            fn (Membership $membership): string => $membership->workspace->ulid,
            array_filter($owned, fn (Membership $membership): bool => WorkspacePlan::for($membership->workspace) === Plan::Pro),
        ));
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
