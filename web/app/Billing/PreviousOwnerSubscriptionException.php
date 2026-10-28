<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\BillingAccount;
use RuntimeException;

/**
 * The workspace changed hands and the subscription of the owner it had is still in play: the new owner is not handed the Stripe customer
 * of the old one (its email, its invoices, its cards), nor sold a second subscription beside it. They wait for it to end
 * ({@see SubscriptionLifecycle::ownerChanged()} has it end with the period that is paid for).
 */
final class PreviousOwnerSubscriptionException extends RuntimeException
{
    /**
     * Remembers the account whose subscription stands in the way.
     */
    public function __construct(public readonly BillingAccount $account)
    {
        parent::__construct('The subscription of the previous owner of the workspace is still in play.');
    }
}
