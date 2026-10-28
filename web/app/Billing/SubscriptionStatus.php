<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * The words Stripe uses for the status of a subscription, grouped by what the application does with them.
 *
 * Which statuses give the Pro plan is {@see WorkspacePlan::PRO_STATUSES}; these two groups are about which subscription is the one a
 * workspace has ({@see BillingSync}) and whether anything is left to cancel at Stripe when the workspace is deleted or changes hands
 * ({@see SubscriptionLifecycle}).
 */
final class SubscriptionStatus
{
    /**
     * The statuses of a subscription that is still in play: it bills, or may bill again once a payment, a pause or the first
     * confirmation is settled. A customer has one of these at a time, unless it went through the checkout twice.
     */
    public const CURRENT = ['active', 'trialing', 'past_due', 'unpaid', 'paused', 'incomplete'];

    /** The statuses of a subscription that is over for good: nothing is left to cancel. */
    public const ENDED = ['canceled', 'incomplete_expired'];
}
