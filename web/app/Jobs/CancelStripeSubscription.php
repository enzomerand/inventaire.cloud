<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Billing\Billing;
use App\Billing\StripeClient;
use App\Billing\StripeException;
use App\Billing\SubscriptionLifecycle;
use App\Billing\SubscriptionStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ends the subscriptions of a customer at Stripe, so that nobody keeps paying for a workspace that is gone or that they no longer own:
 * queued, once the deletion or the change of owner is committed, by {@see SubscriptionLifecycle}.
 *
 * It is given the **customer**, not a subscription: the job lists the subscriptions of the customer at Stripe, and ends every one that is
 * not over. A workspace deleted after a Checkout but before the synchronisation wrote the subscription in its account has none recorded,
 * and it would never have been cancelled by the id the account holds. Either now (the workspace is deleted: the customer is not charged
 * again, and gets no refund for the period that began), or at the end of the period that is paid for (`$atPeriodEnd`: the workspace changed
 * hands, the old payer stops paying and the workspace stays Pro until then; the new owner subscribes on their own). A customer or a
 * subscription that Stripe does not have any more, or that is over by the time it is asked, is taken for done: nothing to worry about.
 *
 * What is worth another try (Stripe cannot be reached, slows clients down or is failing) makes the job fail and run again, with a growing
 * pause; the subscriptions that were ended are not asked again (the list is read afresh). What Stripe refuses for a reason that will be the
 * same next time (the key, an unexpected `4xx`), and what is still open after the last try, is written in the log for somebody to cancel in
 * the dashboard of Stripe: the customer would otherwise be charged for nothing. The secret key is never written. An instance that has no
 * Stripe configured any more can do nothing, and says so in the log.
 *
 * When the workspace still exists (`$billingAccountId`), the account is read again afterwards, so that the page does not show a plan that
 * has been changed for as long as it takes the webhook to arrive (an instance without one waits for the schedule).
 */
final class CancelStripeSubscription implements ShouldQueue
{
    use Queueable;

    /** How many times the job is tried. */
    public int $tries = 5;

    /** The seconds the job may run: a few calls that wait for 20 seconds at the longest, with room. */
    public int $timeout = 120;

    /** The key Stripe recognises a retry of the same cancellation by: made once, kept by the job across its tries. */
    public readonly string $idempotencyKey;

    /**
     * Remembers whose subscriptions to end, and how.
     *
     * @param  string  $customerId  The customer at Stripe (`cus_…`) whose subscriptions are ended.
     * @param  bool  $atPeriodEnd  Whether they end at the end of the period that is paid for, instead of now.
     * @param  int|null  $billingAccountId  The account to read again afterwards, when the workspace is still there.
     */
    public function __construct(
        public readonly string $customerId,
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
     * Ends the subscriptions of the customer that are not over.
     *
     * @throws StripeException When Stripe could not be reached or is failing: the queue tries again.
     */
    public function handle(StripeClient $client): void
    {
        if (! Billing::enabled()) {
            Log::warning('The subscriptions of a customer could not be cancelled at Stripe: this instance has no Stripe configured any more. Cancel them in the dashboard of Stripe.', ['customer' => $this->customerId]);

            return;
        }

        try {
            $subscriptions = $client->subscriptionsOf($this->customerId);
        } catch (StripeException $exception) {
            if ($exception->retryable()) {
                throw $exception;
            }

            if (! self::isMissing($exception)) {
                Log::error('The subscriptions of a customer could not be listed at Stripe: cancel them in the dashboard of Stripe.', [
                    'customer' => $this->customerId,
                    'status' => $exception->status(),
                    'reason' => $exception->code(),
                ]);

                return;
            }

            Log::info('A customer whose subscriptions were to be cancelled was not at Stripe any more.', ['customer' => $this->customerId]);
            $this->readAccountAgain();

            return;
        }

        $retry = null;
        $refused = false;

        foreach ($subscriptions as $subscription) {
            $id = $subscription['id'] ?? null;
            $status = $subscription['status'] ?? null;

            if (! is_string($id) || $id === '' || ! is_string($status) || in_array($status, SubscriptionStatus::ENDED, true)) {
                continue;
            }

            // Already ending with the period: nothing more to ask.
            if ($this->atPeriodEnd && ($subscription['cancel_at_period_end'] ?? false) === true) {
                continue;
            }

            try {
                $this->end($client, $id);
            } catch (StripeException $exception) {
                if ($exception->retryable()) {
                    // The other subscriptions are ended all the same; the queue comes back for this one.
                    $retry ??= $exception;

                    continue;
                }

                if (self::isMissing($exception)) {
                    Log::info('A subscription to cancel was not at Stripe any more.', ['customer' => $this->customerId, 'subscription' => $id]);

                    continue;
                }

                if ($this->isOver($client, $id)) {
                    Log::info('A subscription to cancel was over by the time it was asked.', ['customer' => $this->customerId, 'subscription' => $id]);

                    continue;
                }

                $refused = true;
                Log::error('A subscription could not be cancelled at Stripe: cancel it in the dashboard of Stripe.', [
                    'customer' => $this->customerId,
                    'subscription' => $id,
                    'status' => $exception->status(),
                    'reason' => $exception->code(),
                ]);
            }
        }

        if ($retry !== null) {
            throw $retry;
        }

        if (! $refused) {
            $this->readAccountAgain();
        }
    }

    /**
     * Writes in the log what is still open once the last try failed.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('The subscriptions of a customer could not be cancelled at Stripe: cancel them in the dashboard of Stripe.', [
            'customer' => $this->customerId,
            'at_period_end' => $this->atPeriodEnd,
            'exception' => $exception,
        ]);
    }

    /**
     * Ends one subscription, now or with the period.
     *
     * @throws StripeException
     */
    private function end(StripeClient $client, string $subscriptionId): void
    {
        if ($this->atPeriodEnd) {
            // One key for each subscription (a key can be used for one request), the same for every try of the job.
            $client->cancelAtPeriodEnd($subscriptionId, hash('sha256', $this->idempotencyKey.'|'.$subscriptionId));
        } else {
            $client->cancelSubscription($subscriptionId);
        }
    }

    /**
     * Whether a subscription that could not be ended is over now (Stripe refuses to end one that is already canceled, with an answer that
     * does not say so in a word the application reads): it is read again. A failure to read it is a refusal like the first.
     */
    private function isOver(StripeClient $client, string $subscriptionId): bool
    {
        try {
            foreach ($client->subscriptionsOf($this->customerId) as $subscription) {
                if (($subscription['id'] ?? null) === $subscriptionId) {
                    return in_array($subscription['status'] ?? null, SubscriptionStatus::ENDED, true);
                }
            }
        } catch (StripeException) {
            return false;
        }

        return false;
    }

    /**
     * Whether Stripe answered that what was asked for is not there: its error says `resource_missing`. A `404` without it is not an answer
     * of Stripe about the object (a proxy, a wrong address), and proves nothing.
     */
    private static function isMissing(StripeException $exception): bool
    {
        return $exception->code() === 'resource_missing';
    }

    /**
     * Queues the synchronisation of the account of the workspace, when it is still there.
     */
    private function readAccountAgain(): void
    {
        if ($this->billingAccountId !== null) {
            SyncBillingAccount::dispatch($this->billingAccountId);
        }
    }
}
