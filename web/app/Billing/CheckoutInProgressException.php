<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

/**
 * Another owner of the workspace stored their Stripe customer first, while none was in play: this request made its own for nothing (it
 * is written in the log, to be deleted at Stripe) and is asked to try again, when the customer that was stored is known to everybody
 * ({@see BillingCustomers}). The person is told that a payment page is being opened for the workspace.
 */
final class CheckoutInProgressException extends RuntimeException
{
    /**
     * Says what happened, for the log.
     */
    public function __construct()
    {
        parent::__construct('Another owner stored the Stripe customer of the workspace first.');
    }
}
