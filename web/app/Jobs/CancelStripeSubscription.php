<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Billing\Billing;
use App\Billing\StripeClient;
use App\Billing\StripeException;
use App\Billing\SubscriptionLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ends a subscription at Stripe, so that nobody keeps paying for a workspace that is gone or that they no longer own: queued, once the
 * deletion or the change of owner is committed, by {@see SubscriptionLifecycle}.
 *
 * Either now (the workspace is deleted: the customer is not charged again, and gets no refund for the period that began), or at the end of
 * the period that is paid for (`$atPeriodEnd`: the workspace changed hands, the old payer stops paying and the workspace stays Pro
 * until then; the new owner subscribes on their own). A subscription that Stripe does not have any more (`404`) is cancelled, which is
 * the point: nothing to worry about.
 *
 * What is worth another try (Stripe cannot be reached, slows clients down or is failing) makes the job fail and run again, with a growing
 * pause. What Stripe refuses for a reason that will be the same next time (the key, an unexpected `4xx`), and what is still open after the
 * last try, is written in the log for somebody to cancel in the dashboard of Stripe: the customer would otherwise be charged for
 * nothing. The secret key is never written. An instance that has no Stripe configured any more can do nothing, and says so in the log.
 *
 * When the workspace still exists (`$billingAccountId`), the account is read again afterwards, so that the page does not show a plan that
 * has been changed for as long as it takes the webhook to arrive (an instance without one waits for the schedule).
 */
final class CancelStripeSubscription implements ShouldQueue
{
    use Queueable;

    /** How many times the job is tried. */
    public int $tries = 5;

    /** The seconds the job may run: one call that waits for 20 seconds at the longest, with room. */
    public int $timeout = 60;

    /** The key Stripe recognises a retry of the same cancellation by: made once, kept by the job across its tries. */
    public readonly string $idempotencyKey;

    /**
     * Remembers what to cancel, and how.
     *
     * @param  string  $subscriptionId  The subscription at Stripe (`sub_…`).
     * @param  bool  $atPeriodEnd  Whether it ends at the end of the period that is paid for, instead of now.
     * @param  int|null  $billingAccountId  The account to read again afterwards, when the workspace is still there.
     */
    public function __construct(
        public readonly string $subscriptionId,
        public readonly bool $atPeriodEnd = false,
        public readonly ?int $billingAccountId = null,
    ) {
        $this->idempotencyKey = (string) Str::uuid();
    }

    /**
     * The seconds to wait before each new try.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    /**
     * Cancels the subscription.
     *
     * @throws StripeException When Stripe could not be reached or is failing: the queue tries again.
     */
    public function handle(StripeClient $client): void
    {
        if (! Billing::enabled()) {
            Log::warning('A subscription could not be cancelled at Stripe: this instance has no Stripe configured any more. Cancel it in the dashboard of Stripe.', ['subscription' => $this->subscriptionId]);

            return;
        }

        try {
            if ($this->atPeriodEnd) {
                $client->cancelAtPeriodEnd($this->subscriptionId, $this->idempotencyKey);
            } else {
                $client->cancelSubscription($this->subscriptionId);
            }
        } catch (StripeException $exception) {
            if ($exception->retryable()) {
                throw $exception;
            }

            if (! $exception->notFound()) {
                Log::error('A subscription could not be cancelled at Stripe: cancel it in the dashboard of Stripe.', [
                    'subscription' => $this->subscriptionId,
                    'status' => $exception->status(),
                    'reason' => $exception->code(),
                ]);

                return;
            }

            Log::info('A subscription to cancel was not at Stripe any more.', ['subscription' => $this->subscriptionId]);
        }

        if ($this->billingAccountId !== null) {
            SyncBillingAccount::dispatch($this->billingAccountId);
        }
    }

    /**
     * Writes in the log what is still open once the last try failed.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('A subscription could not be cancelled at Stripe: cancel it in the dashboard of Stripe.', [
            'subscription' => $this->subscriptionId,
            'at_period_end' => $this->atPeriodEnd,
            'exception' => $exception,
        ]);
    }
}
