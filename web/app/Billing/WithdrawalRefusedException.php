<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

/**
 * Why a withdrawal from a contract cannot be recorded: the subscription is not live, the fourteen days are over, or it was withdrawn
 * from already. `code` is what the API answers with (`no_live_subscription`, `withdrawal_period_over`, `already_withdrawn`).
 */
final class WithdrawalRefusedException extends RuntimeException
{
    /**
     * @param  string  $reason  One of the codes above.
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The withdrawal was refused: {$reason}.");
    }
}
