<?php

declare(strict_types=1);

namespace App\Billing;

use App\Database\DeadlockRetries;
use App\Jobs\CancelStripeSubscription;
use App\Models\BillingAccount;
use App\Models\BillingWithdrawal;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Locks;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The right of withdrawal as the terms of sale promise it (article 7): fourteen days from the start of the subscription, a declaration made
 * from the subscription page, an acknowledgement on a durable medium, the subscription ended and the administrators told what to refund.
 *
 * The right is extended by contract to every client, so any owner who pays has it while a live subscription is inside its window. The
 * window counts from `started_at`, the `start_date` Stripe reports for the subscription (a new subscription starts a new one), up to and
 * including `started_at` + 14 days.
 *
 * **The order of a withdrawal** ({@see WorkspaceBillingController::withdraw()}): the request is judged on the stored account ({@see self::refusal()});
 * the latest paid invoice is read at Stripe, **outside any transaction** (a failure to read it never blocks the withdrawal: the amount is
 * left empty and the administrators are told to check it); then {@see self::record()} stores the declaration in one transaction, under the
 * locks of the account and of the workspace (in the order of {@see Locks}), judging again: the window, the subscription and the one
 * withdrawal each subscription may have. Only once that has committed does {@see self::settle()} act: it queues the cancellation of the
 * subscription (now, not at the end of the period), the acknowledgement to the person, and the mail to the administrators with what to refund.
 * Each of those can fail without undoing the others or the declaration, which is stored: a failure is written in the log and told to the
 * administrators.
 *
 * **What is to be refunded** is not refunded by the application (no automatic refund): it is what was paid, less the share of the service
 * provided until the declaration, which is the number of days used (rounded up) over the days of the period ({@see self::refundCents()}),
 * and the administrators are told the amount and the deadline (fourteen days from the declaration).
 */
final class Withdrawals
{
    /** The days the right of withdrawal lasts. */
    public const WINDOW_DAYS = 14;

    /** The days the seller has to refund, from the day it learns the decision. */
    public const REFUND_DAYS = 14;

    /**
     * Gets what a withdrawal is made of.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly StripeClient $stripe,
        private readonly ContractNotices $notices,
        private readonly BillingAlerts $alerts,
    ) {}

    /**
     * The last moment the right of withdrawal can be exercised for a subscription that started at a moment.
     */
    public static function windowEnd(CarbonInterface $startedAt): CarbonImmutable
    {
        return CarbonImmutable::instance($startedAt)->addDays(self::WINDOW_DAYS);
    }

    /**
     * Whether the subscription of an account can be withdrawn from at a moment: it is live (gives Pro: active, trialing or past due), it
     * started at a known moment, and that moment is at most fourteen days before.
     */
    public static function isOpen(BillingAccount $account, CarbonInterface $now): bool
    {
        return $account->started_at !== null
            && $account->stripe_subscription_id !== null
            && in_array($account->status, WorkspacePlan::PRO_STATUSES, true)
            && $now->lessThanOrEqualTo(self::windowEnd($account->started_at));
    }

    /**
     * What is to be refunded when somebody withdraws: what was paid, less the share of the period already provided. The days used are the
     * days from the start of the period to the declaration, rounded up and at most the days of the period; the share kept is the amount
     * paid times the days used over the days of the period, rounded down to the cent.
     *
     * @param  int  $paid  What was paid, in the smallest unit of the currency.
     * @param  CarbonInterface  $periodStart  The start of the period that was paid for.
     * @param  CarbonInterface  $periodEnd  The end of that period.
     * @param  CarbonInterface  $declaredAt  When the declaration was received.
     */
    public static function refundCents(int $paid, CarbonInterface $periodStart, CarbonInterface $periodEnd, CarbonInterface $declaredAt): int
    {
        $total = max(1, (int) round(($periodEnd->getTimestamp() - $periodStart->getTimestamp()) / 86400));
        $used = min($total, (int) ceil(max(0, $declaredAt->getTimestamp() - $periodStart->getTimestamp()) / 86400));

        return $paid - intdiv($paid * $used, $total);
    }

    /**
     * The days used and the days of the period, as {@see self::refundCents()} counts them, for the messages that explain the amount.
     *
     * @return array{used: int, total: int}
     */
    public static function days(CarbonInterface $periodStart, CarbonInterface $periodEnd, CarbonInterface $declaredAt): array
    {
        $total = max(1, (int) round(($periodEnd->getTimestamp() - $periodStart->getTimestamp()) / 86400));

        return ['used' => min($total, (int) ceil(max(0, $declaredAt->getTimestamp() - $periodStart->getTimestamp()) / 86400)), 'total' => $total];
    }

    /**
     * Why an account cannot be withdrawn from right now, judged on the stored row; null when it can.
     *
     * @return string|null `no_live_subscription`, `withdrawal_period_over` or `already_withdrawn`.
     */
    public function refusal(BillingAccount $account): ?string
    {
        if ($account->stripe_subscription_id === null || ! in_array($account->status, WorkspacePlan::PRO_STATUSES, true) || $account->started_at === null) {
            return 'no_live_subscription';
        }

        if ($this->withdrawn($account->stripe_subscription_id)) {
            return 'already_withdrawn';
        }

        return now()->lessThanOrEqualTo(self::windowEnd($account->started_at)) ? null : 'withdrawal_period_over';
    }

    /**
     * Whether a withdrawal was recorded for a subscription.
     */
    public function withdrawn(string $subscriptionId): bool
    {
        return BillingWithdrawal::query()->where('stripe_subscription_id', $subscriptionId)->exists();
    }

    /**
     * The latest paid invoice of the subscription, read at Stripe. Never throws: a failure is written in the log and the answer is null (the
     * amount is then "to be checked" for the administrators), because the right of withdrawal does not wait for Stripe.
     *
     * @return array{id: string, amount_paid: int, currency: string}|null
     */
    public function paidInvoice(BillingAccount $account): ?array
    {
        if ($account->stripe_subscription_id === null) {
            return null;
        }

        try {
            return $this->stripe->latestPaidInvoice($account->stripe_subscription_id);
        } catch (Throwable $exception) {
            Log::warning('The invoice of a withdrawal could not be read at Stripe: the amount to refund is to be checked in the dashboard.', [
                'billing_account' => $account->getKey(),
                'subscription' => $account->stripe_subscription_id,
                'exception' => $exception,
            ]);

            return null;
        }
    }

    /**
     * Stores the declaration, in one transaction run again when the database picks it as the loser of a deadlock: the account of the person
     * first, then the workspace (the order of {@see Locks}), judging again under the locks, from the account as it is stored now.
     *
     * @param  string  $subscriptionId  The subscription the request was judged on: a different one is a new contract, not this declaration's.
     * @param  array{id: string, amount_paid: int, currency: string}|null  $invoice  The latest paid invoice, null when it was not read.
     *
     * @throws WithdrawalRefusedException When the right cannot be exercised any more.
     * @throws ModelNotFoundException When the workspace or the person is gone.
     */
    public function record(Workspace $workspace, User $user, string $name, string $email, string $subscriptionId, ?array $invoice): BillingWithdrawal
    {
        return DeadlockRetries::run(fn (): BillingWithdrawal => $this->db->transaction(function () use ($workspace, $user, $name, $email, $subscriptionId, $invoice): BillingWithdrawal {
            // The account of the person first (the withdrawal points to it), then the workspace: the order of the locks.
            if ($this->locks->users([$user->getKey()])->isEmpty()) {
                throw (new ModelNotFoundException)->setModel(User::class, [$user->getKey()]);
            }

            if ($this->locks->workspaces([$workspace->getKey()])->isEmpty()) {
                throw (new ModelNotFoundException)->setModel(Workspace::class, [$workspace->getKey()]);
            }

            $account = BillingAccount::query()->where('workspace_id', $workspace->getKey())->first();
            $declaredAt = CarbonImmutable::now();

            // The subscription may have been replaced since the request was judged (a new contract: not the one this declaration was about).
            if ($account === null || $account->stripe_subscription_id !== $subscriptionId || ! in_array($account->status, WorkspacePlan::PRO_STATUSES, true) || $account->started_at === null) {
                throw new WithdrawalRefusedException('no_live_subscription');
            }

            if ($this->withdrawn($subscriptionId)) {
                throw new WithdrawalRefusedException('already_withdrawn');
            }

            if ($declaredAt->greaterThan(self::windowEnd($account->started_at))) {
                throw new WithdrawalRefusedException('withdrawal_period_over');
            }

            $refund = $invoice !== null && $account->current_period_end !== null
                ? self::refundCents($invoice['amount_paid'], $account->started_at, $account->current_period_end, $declaredAt)
                : null;

            try {
                return BillingWithdrawal::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'user_id' => $user->getKey(),
                    'stripe_customer_id' => $account->stripe_customer_id,
                    'stripe_subscription_id' => $subscriptionId,
                    'name' => $name,
                    'email' => $email,
                    'amount_paid_cents' => $invoice['amount_paid'] ?? null,
                    'refund_cents' => $refund,
                    'currency' => $invoice['currency'] ?? null,
                    'submitted_at' => $declaredAt,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another request recorded it between the check and the insert (the lock of the workspace makes this unreachable).
                throw new WithdrawalRefusedException('already_withdrawn');
            }
        }));
    }

    /**
     * What follows a recorded declaration, once its transaction has committed: the subscription is cancelled at Stripe (now), the person is
     * sent the acknowledgement, and the administrators are told what to refund. Each is attempted whatever the others do; a failure is logged
     * and, where nobody else would learn of it, told to the administrators.
     */
    public function settle(BillingWithdrawal $withdrawal, BillingAccount $account, Workspace $workspace): void
    {
        try {
            CancelStripeSubscription::dispatch($withdrawal->stripe_customer_id, false, (int) $account->getKey(), $workspace->ulid);
        } catch (Throwable $exception) {
            // The job is the only thing that cancels the subscription: told to the administrators like a cancellation that failed.
            Log::error('The cancellation of a subscription that was withdrawn from could not be queued: cancel it in the dashboard of Stripe.', [
                'customer' => $withdrawal->stripe_customer_id,
                'subscription' => $withdrawal->stripe_subscription_id,
                'exception' => $exception,
            ]);
            $this->alerts->reportProblem('cancellation_failed', $withdrawal->stripe_customer_id, $workspace->ulid);
        }

        $acknowledged = $this->notices->acknowledgeWithdrawal($withdrawal, $account, $workspace);

        $this->alerts->tellWithdrawal($withdrawal, $workspace->ulid, $acknowledged);
    }
}
