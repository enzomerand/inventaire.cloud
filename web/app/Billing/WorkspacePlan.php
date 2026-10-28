<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\BillingAccount;
use App\Models\Workspace;

/**
 * The plan a workspace is on: Pro while the subscription Stripe last reported is `active`, `trialing` or `past_due` (a payment that
 * failed is retried for a while, and the workspace keeps what it paid for meanwhile), Free in every other case: billing off, no
 * account, no subscription, or one that is canceled, unpaid, paused or incomplete.
 *
 * It answers from the row of the workspace ({@see BillingAccount}), which only the synchronisation writes: never from the content of a
 * webhook. A downgrade deletes nothing; it only makes the limits of {@see Plan::Free} apply to what is created from then on.
 *
 * The row is read once per workspace for the length of a request or a job ({@see PlanMemory}), so that code which asks several questions
 * about a workspace, or the same one in a loop, does not read it each time.
 */
final class WorkspacePlan
{
    /** The statuses of a subscription that give the Pro plan. */
    public const PRO_STATUSES = ['active', 'trialing', 'past_due'];

    /**
     * The plan of a workspace, as the database says (one indexed read the first time it is asked in a request or a job, none when billing
     * is off, none for the following questions).
     *
     * @param  Workspace|int  $workspace  The workspace, or its id: a caller that holds a row that belongs to it needs no model.
     */
    public static function for(Workspace|int $workspace): Plan
    {
        if (! Billing::enabled()) {
            return Plan::Free;
        }

        $id = $workspace instanceof Workspace ? (int) $workspace->getKey() : $workspace;
        $memory = self::memory();
        $plan = $memory->recall($id);

        if ($plan === null) {
            $status = BillingAccount::query()->where('workspace_id', $id)->value('status');
            $plan = is_string($status) && in_array($status, self::PRO_STATUSES, true) ? Plan::Pro : Plan::Free;
            $memory->remember($id, $plan);
        }

        return $plan;
    }

    /**
     * Makes the next question about any workspace read the database again. Called when a subscription is written
     * ({@see BillingAccount}, {@see BillingSync}).
     */
    public static function forget(): void
    {
        self::memory()->forget();
    }

    /**
     * What this request or job has read so far.
     */
    private static function memory(): PlanMemory
    {
        return app(PlanMemory::class);
    }
}
