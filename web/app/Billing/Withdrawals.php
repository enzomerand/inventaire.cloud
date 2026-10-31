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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The right of withdrawal as the terms of sale promise it (article 7): fourteen days from the start of the subscription, a declaration made
 * from the subscription page, an acknowledgement on a durable medium, the subscription ended and the administrators told what to refund.
 *
 * The right is extended by contract to every client, so any owner who pays has it while a live subscription is inside its window. The
 * window counts from `started_at`, the `start_date` Stripe reports for the subscription (a new subscription starts a new one), the way
 * the consumer code counts it ({@see WithdrawalDeadline}): fourteen days from the day after the conclusion, to the last hour of the last
 * day, and on to the next working day when that day is a Saturday, a Sunday or a public holiday.
 *
 * **The order of a withdrawal** ({@see WorkspaceBillingController::withdraw()}): the request is judged on the stored account ({@see self::refusal()});
 * the latest paid invoice is read at Stripe, **outside any transaction** (a failure to read it never blocks the withdrawal: the amount is
 * left empty and the administrators are told to check it); then {@see self::record()} stores the declaration in one transaction, under the
 * locks of the account and of the workspace (in the order of {@see Locks}), judging again: the window, the subscription and the one
 * withdrawal each subscription may have. Only once that has committed does {@see self::settle()} act: it queues the cancellation of the
 * subscription (now, not at the end of the period), the acknowledgement to the person, and the mail to the administrators with what to refund.
 * Each of those can fail without undoing the others or the declaration, which is stored: a failure is written in the log and told to the
 * administrators, and the hourly synchronisation finishes what did not go through ({@see self::resume()}), step by step, since each step
 * remembers that it was done.
 *
 * **What is to be refunded** is not refunded by the application (no automatic refund): it is what was paid, less the share of the service
 * provided until the declaration, which is the number of days used (rounded up) over the days of the period ({@see self::refundCents()}),
 * and the administrators are told the amount and the deadline (fourteen days from the declaration).
 */
final class Withdrawals
{
    /** The days the seller has to refund, from the day it learns the decision. */
    public const REFUND_DAYS = 14;

    /** The minutes a declaration is left to the request that stored it before the schedule finishes what that request did not. */
    public const GRACE_MINUTES = 10;

    /**
     * Gets what a withdrawal is made of.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly StripeClient $stripe,
        private readonly ContractNotices $notices,
        private readonly BillingAlerts $alerts,
        private readonly BillingSync $sync,
    ) {}

    /**
     * Whether the subscription of an account can be withdrawn from at a moment: it is live (gives Pro: active, trialing or past due), it
     * started at a known moment, and the moment asked about is not past the end of its right ({@see WithdrawalDeadline::end()}).
     */
    public static function isOpen(BillingAccount $account, CarbonInterface $now): bool
    {
        return $account->started_at !== null
            && $account->stripe_subscription_id !== null
            && in_array($account->status, WorkspacePlan::PRO_STATUSES, true)
            && $now->lessThanOrEqualTo(WithdrawalDeadline::end($account->started_at));
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
        ['used' => $used, 'total' => $total] = self::days($periodStart, $periodEnd, $declaredAt);

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

        return now()->lessThanOrEqualTo(WithdrawalDeadline::end($account->started_at)) ? null : 'withdrawal_period_over';
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

            if ($declaredAt->greaterThan(WithdrawalDeadline::end($account->started_at))) {
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
     * sent the acknowledgement, and the administrators are told what to refund. Each is attempted whatever the others do; a failure is
     * logged and, where nobody else would learn of it, told to the administrators.
     *
     * Each step is **idempotent**: the acknowledgement is queued only while `acknowledged_at` is empty and the administrators are told only
     * while `admin_told_at` is, so that a process that died half way, or a step that failed, is finished by {@see self::resume()} (which
     * the schedule calls) without anything being sent twice. The cancellation job ends whatever of the customer is not over, and ending
     * what is over is nothing to it.
     *
     * @return bool Whether the acknowledgement was queued (now, or before).
     */
    public function settle(BillingWithdrawal $withdrawal, BillingAccount $account, Workspace $workspace): bool
    {
        $this->cancel($withdrawal, (int) $account->getKey(), $workspace->ulid);

        return $this->finish($withdrawal, $account, $workspace, $workspace->ulid);
    }

    /**
     * The declarations the schedule has to finish: stored in the last {@see self::REFUND_DAYS} days (after that the refund is due and
     * the administrators were told or are in the log), at least {@see self::GRACE_MINUTES} minutes ago (the request that stored it is
     * still at work), and not completely settled: the administrators were not told, the acknowledgement was not queued **and still can be**
     * (the workspace exists and the account still describes the subscription that was withdrawn from; otherwise nothing more can be done
     * about it and it would take a place in every run for nothing), or the subscription is still in play at Stripe according to the
     * account. The oldest first.
     *
     * @return Collection<int, BillingWithdrawal>
     */
    public function unsettled(int $limit): Collection
    {
        // The account of the customer, while it still holds the subscription that was withdrawn from (in whatever state).
        $describes = fn ($account) => $account->from('billing_accounts')
            ->whereColumn('billing_accounts.stripe_customer_id', 'billing_withdrawals.stripe_customer_id')
            ->whereColumn('billing_accounts.stripe_subscription_id', 'billing_withdrawals.stripe_subscription_id');

        return BillingWithdrawal::query()
            ->where('submitted_at', '>=', now()->subDays(self::REFUND_DAYS))
            ->where('submitted_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            ->where(fn (Builder $open) => $open
                ->whereNull('admin_told_at')
                ->orWhere(fn (Builder $ack) => $ack->whereNull('acknowledged_at')->whereNotNull('workspace_id')->whereExists($describes))
                ->orWhereExists(fn ($live) => $describes($live)->whereIn('billing_accounts.status', SubscriptionStatus::CURRENT)))
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Finishes a declaration whose follow-up did not go through (the process died between storing it and acting on it, the queue or the
     * mailer was down): the account is read at Stripe again, the subscription is cancelled if it is still in play, the acknowledgement is
     * queued if it was not, and the administrators are told if they were not. Never touches another subscription of the customer: a new
     * one the person bought since is not the one they withdrew from.
     *
     * The acknowledgement repeats what the account says of the contract, so it is only sent while the account still describes the
     * subscription that was withdrawn from (otherwise the administrators are told to write to the person).
     *
     * @throws Throwable When the account cannot be read for another reason than Stripe, or the database fails.
     */
    public function resume(BillingWithdrawal $withdrawal): void
    {
        $account = BillingAccount::query()->where('stripe_customer_id', $withdrawal->stripe_customer_id)->first();
        $workspace = $withdrawal->workspace_id === null ? null : Workspace::query()->find($withdrawal->workspace_id);

        if ($account !== null) {
            try {
                $account = $this->sync->sync($account);
            } catch (StripeException $exception) {
                Log::warning('The subscription of a withdrawal could not be read at Stripe: the stored one is used.', ['withdrawal' => $withdrawal->getKey(), 'code' => $exception->code(), 'status' => $exception->status()]);
            }

            if ($account->stripe_subscription_id === $withdrawal->stripe_subscription_id && in_array($account->status, SubscriptionStatus::CURRENT, true)) {
                $this->cancel($withdrawal, (int) $account->getKey(), $workspace?->ulid);
            }
        }

        $describes = $account !== null && $workspace !== null && $account->stripe_subscription_id === $withdrawal->stripe_subscription_id;

        $this->finish($withdrawal, $describes ? $account : null, $describes ? $workspace : null, $workspace?->ulid);
    }

    /**
     * Queues the cancellation of the subscription of the customer, now. A job that cannot be queued is the only thing that ends the
     * subscription: told to the administrators like a cancellation that failed.
     */
    private function cancel(BillingWithdrawal $withdrawal, ?int $accountId, ?string $workspaceUlid): void
    {
        try {
            CancelStripeSubscription::dispatch($withdrawal->stripe_customer_id, false, $accountId, $workspaceUlid);
        } catch (Throwable $exception) {
            Log::error('The cancellation of a subscription that was withdrawn from could not be queued: cancel it in the dashboard of Stripe.', [
                'customer' => $withdrawal->stripe_customer_id,
                'subscription' => $withdrawal->stripe_subscription_id,
                'exception' => $exception,
            ]);
            $this->alerts->reportProblem('cancellation_failed', $withdrawal->stripe_customer_id, $workspaceUlid);
        }
    }

    /**
     * The two steps that remember themselves: the acknowledgement (when the account and the workspace still describe the contract), then
     * the mail to the administrators, which says whether the acknowledgement went. Each is done only if its moment is still empty.
     *
     * @return bool Whether the acknowledgement was queued (now, or before).
     */
    private function finish(BillingWithdrawal $withdrawal, ?BillingAccount $account, ?Workspace $workspace, ?string $workspaceUlid): bool
    {
        if ($withdrawal->acknowledged_at === null && $account !== null && $workspace !== null) {
            $this->notices->acknowledgeWithdrawal($withdrawal, $account, $workspace);
        }

        $acknowledged = $withdrawal->acknowledged_at !== null;

        if ($withdrawal->admin_told_at === null && $this->alerts->tellWithdrawal($withdrawal, $workspaceUlid, $acknowledged) > 0) {
            $withdrawal->forceFill(['admin_told_at' => CarbonImmutable::now()])->save();
        }

        return $acknowledged;
    }
}
