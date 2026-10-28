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
 */
final class WorkspacePlan
{
    /** The statuses of a subscription that give the Pro plan. */
    public const PRO_STATUSES = ['active', 'trialing', 'past_due'];

    /**
     * The plan of a workspace, as the database says now (one indexed read, none when billing is off).
     */
    public static function for(Workspace $workspace): Plan
    {
        if (! Billing::enabled()) {
            return Plan::Free;
        }

        $status = BillingAccount::query()->where('workspace_id', $workspace->getKey())->value('status');

        return is_string($status) && in_array($status, self::PRO_STATUSES, true) ? Plan::Pro : Plan::Free;
    }
}
