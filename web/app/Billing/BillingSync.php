<?php

declare(strict_types=1);

namespace App\Billing;

use App\Database\DeadlockRetries;
use App\Jobs\SyncBillingAccount;
use App\Models\BillingAccount;
use App\Models\Workspace;
use App\Workspaces\Locks;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;

/**
 * Brings the subscription of a workspace up to date with what Stripe says of its customer: what the {@see SyncBillingAccount} job does,
 * apart from the queue, and what the schedule (`inventaire:sync-billing`) and the page of the workspace do too.
 *
 * **What Stripe sends to the webhook is never believed.** An event only says that something happened to a customer; the state is always
 * read again from the API, and it is the only thing that writes the subscription columns of the account.
 *
 * The subscription the workspace has is the most recent one (by `created`) among those that give the Pro plan
 * ({@see WorkspacePlan::PRO_STATUSES}), otherwise the most recent among the others that are still in play ({@see SubscriptionStatus::CURRENT}),
 * otherwise the most recent one of any status (a canceled one is worth remembering: the page shows that the plan ended), otherwise
 * none. A newer subscription that is only `incomplete` (a checkout that was abandoned) does not hide an older one that is `active`. A customer that went through the checkout twice has two current subscriptions, which is written in the log as a warning: the
 * one chosen is the newest, and somebody has to refund the other: the administrators are told by mail as well, once a day for each customer
 * ({@see BillingAlerts}).
 *
 * **A customer Stripe does not know** (the answer is `resource_missing`) is a customer that was deleted, or, far more likely, an instance
 * whose keys were switched to another mode or another Stripe account. When the row says the workspace has a subscription in play
 * ({@see SubscriptionStatus::CURRENT}) nothing is written: clearing it would turn a paying workspace Free on the strength of a wrong key.
 * The problem is logged, the administrators are told (`customer_lost`), and a `StripeException` that is not worth trying again goes up
 * (the page answers that the service is unavailable, the job gives up). An administrator who checked in the dashboard that the customer
 * really is gone says so with `inventaire:billing-forget`. A row with no subscription in play is cleared, and the workspace is Free.
 *
 * The call to Stripe is made outside any transaction. The columns are written under the lock of the workspace ({@see Locks}, the order
 * of its documentation), by the transaction run again when the database picks it as the loser of a deadlock ({@see DeadlockRetries}),
 * and only if no read made after this one was written meanwhile: `synced_at` is the moment a read of Stripe *began*, to the microsecond
 * (two reads that begin in the same second are ordered all the same), so that two synchronisations that overlap (a job and the command,
 * say) leave what the newest read saw, whichever is written last.
 */
final class BillingSync
{
    /**
     * Gets the client, the database, the locks and the way to tell the administrators.
     */
    public function __construct(
        private readonly StripeClient $client,
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly BillingAlerts $alerts,
    ) {}

    /**
     * Reads the subscriptions of the customer of an account and writes the current one in it.
     *
     * Does nothing but return the account as it was when its workspace, or the account itself, is gone, or when a read that began later
     * was written first.
     *
     * @return BillingAccount The account as it is stored now.
     *
     * @throws StripeException When Stripe cannot be reached or refuses (`retryable()` tells whether to try again); also, and never worth
     *                         trying again, when Stripe does not know the customer of an account that has a subscription in play (nothing
     *                         was written, the administrators were told: see the class).
     */
    public function sync(BillingAccount $account): BillingAccount
    {
        $readAt = CarbonImmutable::now();

        try {
            $subscriptions = $this->client->subscriptionsOf($account->stripe_customer_id);
        } catch (StripeException $exception) {
            if (! self::customerIsGone($exception)) {
                throw $exception;
            }

            $cleared = $this->clearLost($account, $readAt);

            if ($cleared === null) {
                $this->refuseToForget($account);

                throw $exception;
            }

            Log::warning('The Stripe customer of a workspace is gone: its subscription is cleared.', ['billing_account' => $account->getKey()]);

            return $cleared;
        }

        [$chosen, $current] = self::choose($subscriptions);

        if ($current > 1) {
            Log::warning('A workspace has more than one subscription in play at Stripe (a double checkout): the newest is followed, cancel the others.', [
                'billing_account' => $account->getKey(),
                'subscriptions' => $current,
            ]);
            $this->alerts->reportProblem(
                'double_subscription',
                $account->stripe_customer_id,
                Workspace::query()->whereKey($account->workspace_id)->value('ulid'),
            );
        }

        return $this->write($account, self::columns($chosen), $readAt);
    }

    /**
     * Writes down that a customer Stripe does not know belongs to a workspace that has a subscription in play, which is left as it is: the
     * log (with the customer and the workspace) and the administrators, who are told once a day for the customer.
     */
    private function refuseToForget(BillingAccount $account): void
    {
        $workspace = Workspace::query()->whereKey($account->workspace_id)->value('ulid');

        Log::error('The Stripe customer of a workspace that has a subscription is not at Stripe: nothing is cleared (possible wrong-mode API key, or the customer was deleted: check STRIPE_SECRET, then forget it with inventaire:billing-forget).', [
            'customer' => $account->stripe_customer_id,
            'workspace' => $workspace,
        ]);
        $this->alerts->reportProblem('customer_lost', $account->stripe_customer_id, is_string($workspace) ? $workspace : null);
    }

    /**
     * Whether Stripe answered that the customer is not there (a deleted customer has no subscriptions to list): it says `resource_missing`.
     * A `404` without it is not Stripe talking about the customer (a proxy, a wrong address), and must never turn every workspace Free: it
     * is an error like any other.
     */
    private static function customerIsGone(StripeException $exception): bool
    {
        return $exception->code() === 'resource_missing';
    }

    /**
     * The subscription a workspace has among those of its customer, and how many of them are in play.
     *
     * @param  list<array<string, mixed>>  $subscriptions  As Stripe lists them.
     * @return array{0: array<string, mixed>|null, 1: int}
     */
    private static function choose(array $subscriptions): array
    {
        $usable = array_values(array_filter(
            $subscriptions,
            static fn (array $subscription): bool => self::text($subscription['id'] ?? null) !== null && is_string($subscription['status'] ?? null),
        ));

        // The newest first; the sort is stable, so subscriptions created in the same second stay in the order Stripe lists them in.
        usort($usable, static fn (array $a, array $b): int => [self::rank($a), (int) ($b['created'] ?? 0)] <=> [self::rank($b), (int) ($a['created'] ?? 0)]);

        $current = array_filter(
            $usable,
            static fn (array $subscription): bool => in_array($subscription['status'], SubscriptionStatus::CURRENT, true),
        );

        return [$usable[0] ?? null, count($current)];
    }

    /**
     * How a subscription ranks among those of a customer, the lowest first: one that gives the Pro plan, then another that is still in
     * play, then one that is over. A newer `incomplete` subscription (a checkout that was abandoned) must not hide an older `active` one.
     *
     * @param  array<string, mixed>  $subscription
     */
    private static function rank(array $subscription): int
    {
        return match (true) {
            in_array($subscription['status'], WorkspacePlan::PRO_STATUSES, true) => 0,
            in_array($subscription['status'], SubscriptionStatus::CURRENT, true) => 1,
            default => 2,
        };
    }

    /**
     * The columns of the account for a subscription (all empty for none).
     *
     * @param  array<string, mixed>|null  $subscription
     * @return array{stripe_subscription_id: string|null, status: string|null, price_id: string|null, interval: string|null, current_period_end: CarbonImmutable|null, cancel_at_period_end: bool}
     */
    private static function columns(?array $subscription): array
    {
        if ($subscription === null) {
            return ['stripe_subscription_id' => null, 'status' => null, 'price_id' => null, 'interval' => null, 'current_period_end' => null, 'cancel_at_period_end' => false];
        }

        $interval = data_get($subscription, 'items.data.0.price.recurring.interval');
        $periodEnd = data_get($subscription, 'items.data.0.current_period_end');

        return [
            'stripe_subscription_id' => self::text($subscription['id'] ?? null),
            'status' => self::text($subscription['status'] ?? null, 32),
            'price_id' => self::text(data_get($subscription, 'items.data.0.price.id')),
            'interval' => is_string($interval) && in_array($interval, Billing::INTERVALS, true) ? $interval : null,
            'current_period_end' => is_int($periodEnd) ? CarbonImmutable::createFromTimestampUTC($periodEnd) : null,
            // Ending at a date that is not the end of the period (`cancel_at`) is ending all the same.
            'cancel_at_period_end' => ($subscription['cancel_at_period_end'] ?? false) === true || is_int($subscription['cancel_at'] ?? null),
        ];
    }

    /**
     * A value of Stripe's answer that is a text of a size a column holds; null otherwise.
     */
    private static function text(mixed $value, int $max = 255): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= $max ? $value : null;
    }

    /**
     * Writes the columns, with the workspace locked.
     *
     * @param  array<string, mixed>  $columns
     * @param  CarbonImmutable  $readAt  When the read they come from began.
     */
    private function write(BillingAccount $account, array $columns, CarbonImmutable $readAt): BillingAccount
    {
        // Never null: the stored subscription is only spared when the caller asks for it.
        return $this->store($account, $columns, $readAt, false) ?? $account;
    }

    /**
     * Clears the subscription columns of an account whose customer Stripe does not know, with the workspace locked, unless the stored row
     * says the workspace has a subscription in play: the row is read again under the lock, a synchronisation that wrote one meanwhile (or a
     * stale copy of the account in the caller's hands) must not be believed.
     *
     * @return BillingAccount|null The account as stored; null when it has a subscription in play (nothing was written).
     */
    private function clearLost(BillingAccount $account, CarbonImmutable $readAt): ?BillingAccount
    {
        return $this->store($account, self::columns(null), $readAt, true);
    }

    /**
     * Writes the columns of the account under the lock of its workspace, in a transaction run again when the database picks it as the
     * loser of a deadlock.
     *
     * @param  array<string, mixed>  $columns
     * @param  CarbonImmutable  $readAt  When the read they come from began.
     * @param  bool  $sparePaid  Write nothing when the stored subscription is in play ({@see SubscriptionStatus::CURRENT}).
     * @return BillingAccount|null The account as stored (or as it was when nothing was written); null only when `$sparePaid` and the stored subscription is in play.
     */
    private function store(BillingAccount $account, array $columns, CarbonImmutable $readAt, bool $sparePaid): ?BillingAccount
    {
        try {
            return DeadlockRetries::run(fn (): ?BillingAccount => $this->db->transaction(function () use ($account, $columns, $readAt, $sparePaid): ?BillingAccount {
                // The workspace first, as everything that writes to what hangs from it: the account of a workspace that is being deleted is
                // not worth writing, and what it was is returned.
                if ($this->locks->workspaces([$account->workspace_id])->isEmpty()) {
                    return $account;
                }

                $stored = BillingAccount::query()->whereKey($account->getKey())->first();

                if ($stored === null) {
                    return $account;
                }

                if ($sparePaid && in_array($stored->status, SubscriptionStatus::CURRENT, true)) {
                    return null;
                }

                // A read that began after this one has been written already: it saw what happened since.
                if ($stored->synced_at !== null && $stored->synced_at->greaterThan($readAt)) {
                    return $stored;
                }

                $stored->forceFill($columns + ['synced_at' => $readAt])->save();

                return $stored;
            }));
        } finally {
            // Whatever the plans were read as before, in this process, is out of date now (or was read from a row that did not commit).
            WorkspacePlan::forget();
        }
    }
}
