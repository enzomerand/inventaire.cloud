<?php

declare(strict_types=1);

namespace App\Billing;

use App\Legal\Terms;
use App\Mail\CancellationConfirmedMail;
use App\Mail\RenewalNoticeMail;
use App\Mail\SubscriptionConfirmedMail;
use App\Mail\WithdrawalAcknowledgedMail;
use App\Models\BillingAccount;
use App\Models\BillingWithdrawal;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\DisplayTime;
use App\Support\PublicUrls;
use App\Workspaces\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The mails the terms of sale promise, and the law asks for, around a subscription: the confirmation of the contract on a durable medium
 * (with the terms and the form of withdrawal), the receipt of a cancellation, the notice of tacit renewal of a yearly subscription, and
 * the acknowledgement of a withdrawal.
 *
 * **Each is sent at most once**, and is remembered by a column of the account ({@see BillingAccount}: `confirmation_sent_for`,
 * `cancel_notice_sent_for`, `renewal_notice_sent_for`). The column is **claimed first, with one atomic conditional write** (it is set only
 * where it does not hold the value yet), and the mail is queued only by whoever won it: two synchronisations that run at the same moment
 * send one mail. The claim comes **before** the queue call, so the column does not prove that the mail was queued, still less delivered:
 * what keeps a mail from being lost is the way back. The claim is **given back when the mail could not be queued** (or, later, could not be
 * delivered after every try), so the next synchronisation, or the next run of the command, tries again. What is not covered is a process
 * that dies between the claim and the queue call, or a worker that loses a mail already queued: the mail is then not sent until the
 * subscription (or the period) changes; the log and the queue are where to look.
 * Nothing here runs inside a transaction (the mails are queued after the account was written), and nothing here lets a failure out of
 * {@see self::afterSync()}: a mail that cannot be queued must not break a synchronisation, a webhook or a page.
 *
 * The person who receives them is the **payer** (the owner who started the checkout the contract was made at), else the owner of the
 * workspace now ({@see self::party()}), in their language. Times of day are written with their zone ({@see DisplayTime::offset()}).
 */
final class ContractNotices
{
    /**
     * The months before the end of a yearly period from which the notice of renewal may be sent (article L215-1: "au plus tôt trois mois").
     */
    public const RENEWAL_EARLIEST_MONTHS = 3;

    /**
     * The months before the end of a yearly period by which the notice of renewal must have been sent ("au plus tard un mois"). Months are
     * calendar months in the zone of the instance, not 30 days: see {@see self::renewalDue()}.
     */
    public const RENEWAL_LATEST_MONTHS = 1;

    /**
     * Gets the way to tell the administrators.
     */
    public function __construct(private readonly BillingAlerts $alerts) {}

    /**
     * Queues what the account, as a synchronisation just wrote it, is owed: the confirmation of a subscription that became active, and the
     * receipt of a cancellation. Never throws.
     */
    public function afterSync(BillingAccount $account): void
    {
        foreach (['confirmation', 'cancellation'] as $notice) {
            try {
                $this->{$notice}($account);
            } catch (Throwable $exception) {
                Log::error("The {$notice} mail of a subscription could not be queued: it is tried again at the next synchronisation.", [
                    'billing_account' => $account->getKey(),
                    'exception' => $exception,
                ]);
            }
        }
    }

    /**
     * Queues the confirmation of the contract for the subscription of the account, once for each subscription, when it is active or in its
     * trial: the plan, the price, the dates, the express request for the immediate start of the service, and the terms as a PDF (attached
     * when the mail is built by the queue: the mail does not go out without it).
     *
     * @return bool Whether it was queued now.
     *
     * @throws Throwable When it could not be queued (the claim is given back).
     */
    public function confirmation(BillingAccount $account): bool
    {
        $subscription = $account->stripe_subscription_id;

        if ($subscription === null || ! in_array($account->status, ['active', 'trialing'], true) || $account->confirmation_sent_for === $subscription) {
            return false;
        }

        $party = $this->party($account);
        $workspace = $account->workspace;

        if ($party === null) {
            return false;
        }

        if (! $this->claim($account, 'confirmation_sent_for', $subscription)) {
            return false;
        }

        try {
            $this->queue($party, new SubscriptionConfirmedMail(
                name: $party->name,
                workspaceName: $workspace->name,
                interval: (string) $account->interval,
                startedAt: $account->started_at,
                periodEnd: $account->current_period_end,
                withdrawalUntil: $account->started_at === null ? null : WithdrawalDeadline::end($account->started_at),
                billingUrl: $this->billingUrl($workspace),
                termsUrl: PublicUrls::terms('fr'),
                termsVersion: Terms::VERSION,
                billingAccountId: (int) $account->getKey(),
                subscriptionId: $subscription,
            ));
        } catch (Throwable $exception) {
            $this->release($account, 'confirmation_sent_for', $subscription);

            throw $exception;
        }

        return true;
    }

    /**
     * Queues the receipt of a cancellation (the subscription is set to end with the period), once for each cancellation: the date the
     * contract ends and the effects. Fired when the synchronisation sees the subscription end by itself, whoever cancelled and wherever
     * (the page, the customer portal, the dashboard of Stripe).
     *
     * @return bool Whether it was queued now.
     *
     * @throws Throwable When it could not be queued (the claim is given back).
     */
    public function cancellation(BillingAccount $account): bool
    {
        $subscription = $account->stripe_subscription_id;

        if ($subscription === null || ! $account->cancel_at_period_end || $account->current_period_end === null
            || ! in_array($account->status, WorkspacePlan::PRO_STATUSES, true) || $account->cancel_notice_sent_for === $subscription) {
            return false;
        }

        $party = $this->party($account);
        $workspace = $account->workspace;

        if ($party === null) {
            return false;
        }

        if (! $this->claim($account, 'cancel_notice_sent_for', $subscription)) {
            return false;
        }

        try {
            $this->queue($party, new CancellationConfirmedMail(
                name: $party->name,
                workspaceName: $workspace->name,
                interval: (string) $account->interval,
                endsAt: $account->current_period_end,
                billingUrl: $this->billingUrl($workspace),
                billingAccountId: (int) $account->getKey(),
                subscriptionId: $subscription,
            ));
        } catch (Throwable $exception) {
            $this->release($account, 'cancel_notice_sent_for', $subscription);

            throw $exception;
        }

        return true;
    }

    /**
     * Whether the notice of tacit renewal is due for the account: a yearly subscription that gives Pro, that is not set to end, that is
     * between three months and one month before the end of its period, and that was not noticed for that period yet.
     *
     * The months are **calendar months, in the zone of the instance**, taken back from the end of the period without overflowing into the
     * next month (the end of a period on 31 May is three months from 28 February): thirty and ninety days are wrong in both directions
     * (31-day months, and 1 February to 1 May is 89 days). The notice is due from the earliest of those moments to the latest, both
     * included.
     */
    public function renewalDue(BillingAccount $account): bool
    {
        $end = $account->current_period_end;

        if ($end === null || $account->interval !== 'year' || $account->cancel_at_period_end || ! in_array($account->status, WorkspacePlan::PRO_STATUSES, true)) {
            return false;
        }

        if ($account->renewal_notice_sent_for?->equalTo($end) === true) {
            return false;
        }

        $zoned = $end->setTimezone(DisplayTime::zone());

        return now()->betweenIncluded($zoned->subMonthsNoOverflow(self::RENEWAL_EARLIEST_MONTHS), $zoned->subMonthsNoOverflow(self::RENEWAL_LATEST_MONTHS));
    }

    /**
     * Queues the notice of tacit renewal of a yearly subscription when it is due, once for each period: the date the contract renews, the
     * last date to refuse the renewal (the end of the period) in a boxed line, and the way to cancel.
     *
     * @return bool Whether it was queued now.
     *
     * @throws Throwable When it could not be queued (the claim is given back).
     */
    public function renewal(BillingAccount $account): bool
    {
        if (! $this->renewalDue($account)) {
            return false;
        }

        $end = $account->current_period_end;
        $party = $this->party($account);
        $workspace = $account->workspace;

        if ($end === null || $party === null) {
            return false;
        }

        $end = $end->utc();

        // Claimed for that very end of period: a renewal that moved it meanwhile is not noticed with the old date.
        $claimed = DB::table('billing_accounts')
            ->where('id', $account->getKey())
            ->where('current_period_end', $end)
            ->where(fn ($unsent) => $unsent->whereNull('renewal_notice_sent_for')->orWhere('renewal_notice_sent_for', '<>', $end))
            ->update(['renewal_notice_sent_for' => $end]);

        if ($claimed !== 1) {
            return false;
        }

        try {
            $this->queue($party, new RenewalNoticeMail(
                name: $party->name,
                workspaceName: $workspace->name,
                renewsAt: $end,
                billingUrl: $this->billingUrl($workspace),
                billingAccountId: (int) $account->getKey(),
            ));
        } catch (Throwable $exception) {
            DB::table('billing_accounts')->where('id', $account->getKey())->where('renewal_notice_sent_for', $end)->update(['renewal_notice_sent_for' => null]);

            throw $exception;
        }

        return true;
    }

    /**
     * Queues the acknowledgement of a withdrawal to the address the person gave, in their language, and marks it (`acknowledged_at`) once
     * it is queued. Never throws: a failure is written in the log, and the administrators are told that nothing was sent.
     *
     * @return bool Whether it was queued.
     */
    public function acknowledgeWithdrawal(BillingWithdrawal $withdrawal, BillingAccount $account, Workspace $workspace): bool
    {
        try {
            $declaredAt = $withdrawal->submitted_at ?? CarbonImmutable::now();
            $user = $withdrawal->user_id === null ? null : User::query()->find($withdrawal->user_id);
            $days = $account->started_at !== null && $account->current_period_end !== null
                ? Withdrawals::days($account->started_at, $account->current_period_end, $declaredAt)
                : null;

            Mail::to($withdrawal->email)
                ->locale($user->locale ?? config()->string('app.default_locale'))
                ->queue(new WithdrawalAcknowledgedMail(
                    name: $withdrawal->name,
                    email: $withdrawal->email,
                    workspaceName: $workspace->name,
                    interval: (string) $account->interval,
                    orderedAt: $account->started_at,
                    declaredAt: $declaredAt,
                    refundBefore: $declaredAt->addDays(Withdrawals::REFUND_DAYS),
                    amountPaid: $withdrawal->amount_paid_cents,
                    refund: $withdrawal->refund_cents,
                    currency: $withdrawal->currency,
                    daysUsed: $days['used'] ?? null,
                    daysTotal: $days['total'] ?? null,
                    withdrawalId: (int) $withdrawal->getKey(),
                ));

            $withdrawal->forceFill(['acknowledged_at' => CarbonImmutable::now()])->save();

            return true;
        } catch (Throwable $exception) {
            Log::error('The acknowledgement of a withdrawal could not be queued: write to the person.', [
                'withdrawal' => $withdrawal->getKey(),
                'customer' => $withdrawal->stripe_customer_id,
                'exception' => $exception,
            ]);

            return false;
        }
    }

    /**
     * What to do when a mail of this class could not be delivered after every try (the queue calls the `failed()` of the mailable): the
     * claim is given back so that it is sent again by the next synchronisation or the next run of the command, and the administrators are
     * told. For the acknowledgement of a withdrawal there is nothing to send again: `acknowledged_at` is emptied and the administrators told
     * to write to the person.
     *
     * @param  string|null  $column  The column of the account that was claimed, null for an acknowledgement.
     * @param  string|CarbonImmutable|null  $claimed  What the column held (the subscription, the end of the period).
     */
    public function deliveryFailed(?int $accountId, ?string $column, string|CarbonImmutable|null $claimed, ?int $withdrawalId, Throwable $exception): void
    {
        Log::error('A mail about a contract could not be delivered.', ['billing_account' => $accountId, 'withdrawal' => $withdrawalId, 'column' => $column, 'exception' => $exception]);

        try {
            if ($withdrawalId !== null) {
                BillingWithdrawal::query()->whereKey($withdrawalId)->update(['acknowledged_at' => null]);
            }

            $account = $accountId === null ? null : BillingAccount::query()->find($accountId);

            if ($account !== null && $column !== null && $claimed !== null) {
                DB::table('billing_accounts')->where('id', $account->getKey())->where($column, $claimed)->update([$column => null]);
            }

            $withdrawal = $withdrawalId === null ? null : BillingWithdrawal::query()->find($withdrawalId);
            $customer = $account->stripe_customer_id ?? $withdrawal->stripe_customer_id ?? null;

            if ($customer !== null) {
                $this->alerts->reportProblem('contract_mail_failed', $customer, $account === null ? null : Workspace::query()->whereKey($account->workspace_id)->value('ulid'));
            }
        } catch (Throwable $inner) {
            Log::error('The failure of a mail about a contract could not be followed up.', ['exception' => $inner]);
        }
    }

    /**
     * The person a contract mail is for: the payer (the owner who started the checkout), else the owner of the workspace now (the lowest
     * id first). Null when there is none, which is written in the log: a paying workspace nobody owns is not a normal state.
     */
    public function party(BillingAccount $account): ?User
    {
        $payer = $account->payer_id === null ? null : User::query()->find($account->payer_id);

        if ($payer !== null) {
            return $payer;
        }

        $owner = User::query()
            ->whereIn('id', WorkspaceMember::query()->where('workspace_id', $account->workspace_id)->where('role', WorkspaceRole::Owner->value)->select('user_id'))
            ->orderBy('id')
            ->first();

        if ($owner === null) {
            Log::info('A contract mail has nobody to go to: the workspace has no payer and no owner.', ['billing_account' => $account->getKey()]);
        }

        return $owner;
    }

    /**
     * Writes the id of the subscription in a column of the account, only where it does not hold it already and the account still describes
     * that subscription: one atomic statement, so that of two callers at the same moment exactly one gets true.
     */
    private function claim(BillingAccount $account, string $column, string $subscription): bool
    {
        return DB::table('billing_accounts')
            ->where('id', $account->getKey())
            ->where('stripe_subscription_id', $subscription)
            ->where(fn ($unsent) => $unsent->whereNull($column)->orWhere($column, '<>', $subscription))
            ->update([$column => $subscription]) === 1;
    }

    /**
     * Gives a claim back, if the column still holds it.
     */
    private function release(BillingAccount $account, string $column, string $subscription): void
    {
        DB::table('billing_accounts')->where('id', $account->getKey())->where($column, $subscription)->update([$column => null]);
    }

    /**
     * Queues a mail for a person, in their language.
     */
    private function queue(User $party, Mailable $mail): void
    {
        Mail::to($party->email)->locale($party->locale ?? config()->string('app.default_locale'))->queue($mail);
    }

    /**
     * The absolute address of the subscription page of a workspace.
     */
    private function billingUrl(Workspace $workspace): string
    {
        return PublicUrls::to(route('app.workspace.billing', ['workspace' => $workspace->ulid], false));
    }
}
