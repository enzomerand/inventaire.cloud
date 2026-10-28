<?php

declare(strict_types=1);

namespace App\Billing;

use App\Database\DeadlockRetries;
use App\Jobs\SyncBillingAccount;
use App\Models\BillingAccount;
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
 * one chosen is the newest, and somebody has to refund the other. A customer that was deleted at Stripe has none (the answer is a
 * `404`): the columns are cleared, and the workspace is Free.
 *
 * The call to Stripe is made outside any transaction. The columns are written under the lock of the workspace ({@see Locks}, the order
 * of its documentation), by the transaction run again when the database picks it as the loser of a deadlock ({@see DeadlockRetries}),
 * and only if no read made after this one was written meanwhile: `synced_at` is the moment a read of Stripe *began*, so that two
 * synchronisations that overlap (a job and the command, say) leave what the newest read saw, whichever is written last.
 */
final class BillingSync
{
    /**
     * Gets the client, the database and the locks.
     */
    public function __construct(
        private readonly StripeClient $client,
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
    ) {}

    /**
     * Reads the subscriptions of the customer of an account and writes the current one in it.
     *
     * Does nothing but return the account as it was when its workspace, or the account itself, is gone, or when a read that began later
     * was written first.
     *
     * @return BillingAccount The account as it is stored now.
     *
     * @throws StripeException When Stripe cannot be reached or refuses (`retryable()` tells whether to try again).
     */
    public function sync(BillingAccount $account): BillingAccount
    {
        $readAt = CarbonImmutable::now()->startOfSecond();

        try {
            $subscriptions = $this->client->subscriptionsOf($account->stripe_customer_id);
        } catch (StripeException $exception) {
            if (! self::customerIsGone($exception)) {
                throw $exception;
            }

            Log::warning('The Stripe customer of a workspace is gone: its subscription is cleared.', ['billing_account' => $account->getKey()]);

            return $this->write($account, self::columns(null), $readAt);
        }

        [$chosen, $current] = self::choose($subscriptions);

        if ($current > 1) {
            Log::warning('A workspace has more than one subscription in play at Stripe (a double checkout): the newest is followed, cancel the others.', [
                'billing_account' => $account->getKey(),
                'subscriptions' => $current,
            ]);
        }

        return $this->write($account, self::columns($chosen), $readAt);
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
        try {
            return DeadlockRetries::run(fn (): BillingAccount => $this->db->transaction(function () use ($account, $columns, $readAt): BillingAccount {
                // The workspace first, as everything that writes to what hangs from it: the account of a workspace that is being deleted is
                // not worth writing, and what it was is returned.
                if ($this->locks->workspaces([$account->workspace_id])->isEmpty()) {
                    return $account;
                }

                $stored = BillingAccount::query()->whereKey($account->getKey())->first();

                if ($stored === null) {
                    return $account;
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
