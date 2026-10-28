<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Billing\BillingSync;
use App\Billing\StripeException;
use App\Models\BillingAccount;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the subscriptions of the customer of a workspace at Stripe and brings the account up to date ({@see BillingSync::sync()}).
 *
 * Queued by the webhook of Stripe (whatever the event: its data is never believed, the subscriptions are read again), and by whatever
 * wants the plan of a workspace to be current soon (a subscription whose cancellation was just asked for). The schedule does not queue
 * it: `inventaire:sync-billing` synchronises inline.
 *
 * It is unique per account while it waits in the queue (a burst of events for one customer costs one read; the lock is released as soon
 * as the job starts, so that an event that comes while it runs queues another one, which reads the newer state), and two jobs of one
 * account never run at the same time ({@see WithoutOverlapping}: the second is put back for a few seconds). Putting a job back counts as a
 * try for Laravel, so, like {@see SyncSignature}, the job is not bounded by its tries but by time ({@see self::retryUntil()}) and by
 * the real exceptions it throws ({@see self::$maxExceptions}).
 *
 * Only a failure worth another try (Stripe cannot be reached, slows clients down or is failing: {@see StripeException::retryable()})
 * is thrown, to be tried again after a pause. A refusal (the key, a parameter) will be the same each time: it is written in the log
 * and the job is done with, the schedule reads the account again later. An account that is gone is nothing to do.
 */
final class SyncBillingAccount implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** The seconds the job may run: one call that waits for 20 seconds at the longest, and one write, with room. */
    public const TIMEOUT = 90;

    /** The seconds the lock that keeps two jobs of one account apart is held at the longest: more than the job may run. */
    public const LOCK_EXPIRES = 120;

    /** The seconds a job that finds another of the same account running waits before it tries again. */
    public const OVERLAP_RELEASE = 15;

    /** The minutes the job may be kept waiting or tried again, from the moment it is queued. */
    public const RETRY_FOR_MINUTES = 30;

    /** How many times the job is tried: not limited (0), since a release for the overlap is a try; see {@see self::retryUntil()}. */
    public int $tries = 0;

    /** How many real exceptions (not a release for the overlap) the job may throw before it fails. */
    public int $maxExceptions = 3;

    /** The seconds the job may run. */
    public int $timeout = self::TIMEOUT;

    /** The seconds a job waiting in the queue keeps others for the same account away. */
    public int $uniqueFor = 60;

    /**
     * Remembers which account to look at (by its key: it is read again when the job runs).
     */
    public function __construct(public readonly int $billingAccountId) {}

    /**
     * What makes two of these jobs the same: the account.
     */
    public function uniqueId(): string
    {
        return (string) $this->billingAccountId;
    }

    /**
     * What keeps two jobs of the same account from running at once: the second one waits.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->billingAccountId))->releaseAfter(self::OVERLAP_RELEASE)->expireAfter(self::LOCK_EXPIRES)];
    }

    /**
     * When the job stops being tried: it is failed when it comes back after that, whatever released it.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_FOR_MINUTES);
    }

    /**
     * The seconds to wait before each new try after an exception (the last one is kept for the following ones).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Brings the account up to date.
     *
     * @throws StripeException When Stripe could not be reached or is failing: the queue tries again.
     */
    public function handle(BillingSync $sync): void
    {
        $account = BillingAccount::query()->find($this->billingAccountId);

        if ($account === null) {
            return;
        }

        try {
            $sync->sync($account);
        } catch (StripeException $exception) {
            if ($exception->retryable()) {
                throw $exception;
            }

            Log::error('The subscription of a workspace could not be read at Stripe: check the configuration of billing.', [
                'billing_account' => $account->getKey(),
                'status' => $exception->status(),
                'reason' => $exception->code(),
            ]);
        }
    }

    /**
     * Writes in the log that the job gave up; the schedule reads the account again later.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('The subscription of a workspace could not be synchronised with Stripe: the schedule will try again.', [
            'billing_account' => $this->billingAccountId,
            'exception' => $exception,
        ]);
    }
}
