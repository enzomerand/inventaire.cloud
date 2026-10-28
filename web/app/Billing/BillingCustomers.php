<?php

declare(strict_types=1);

namespace App\Billing;

use App\Database\DeadlockRetries;
use App\Models\BillingAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * The Stripe customer of a workspace: the one the owner pays as, created the first time somebody goes to Checkout and kept in the
 * {@see BillingAccount} of the workspace, together with the owner who started that checkout (the **payer**).
 *
 * Stripe is never called inside a transaction (the locks would be held for as long as it takes to answer): the customer is created
 * first, and stored afterwards under the lock of the workspace. A request that finds another one stored meanwhile (two owners clicking
 * at once) keeps the stored one; the idempotency key of the creation is the same for two identical requests, so they get the same
 * customer from Stripe and nothing is left behind.
 *
 * **The customer follows the payer, not the workspace.** It holds the email, the name, the invoices and the cards of the person who pays.
 * When the workspace changes hands the new owner is not the payer, and is never given that customer (their receipts would go to the old
 * owner, and they would read the old owner's invoices in the portal): while the subscription of the old owner is in play they are refused
 * ({@see PreviousOwnerSubscriptionException}); once it is over, a customer of their own replaces the old one and they become the payer
 * ({@see self::startFresh()}). The email of the customer is brought up to date for the payer only.
 *
 * A customer that Stripe does not know any more (the keys of the instance were switched from the test mode to the live one, or to another
 * Stripe account) is replaced by a new one in the same account, and the subscription the account remembered is forgotten with it, but
 * only when the account has no subscription in play: a workspace that is Pro would otherwise silently lose the record of what it pays
 * for, so the administrators are told and nothing is changed.
 */
final class BillingCustomers
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
     * The account of a workspace if it has one (it started a checkout once), else null.
     */
    public function find(Workspace $workspace): ?BillingAccount
    {
        return BillingAccount::query()->where('workspace_id', $workspace->getKey())->first();
    }

    /**
     * Whether the person is the owner who pays for the customer of the account (who started the checkout it was made for).
     */
    public function isPayer(BillingAccount $account, User $user): bool
    {
        return $account->payer_id !== null && (int) $account->payer_id === (int) $user->getKey();
    }

    /**
     * The account of the workspace, with a customer at Stripe that the owner may pay as: the customer is created when the workspace has
     * none (the owner becomes the payer); the payer is given back the customer they have, with its email brought up to date; an owner who
     * is not the payer (the workspace changed hands) gets a customer of their own once the subscription of the payer is over
     * ({@see self::startFresh()}).
     *
     * @param  string  $locale  The language of the owner, for the emails and invoices Stripe sends.
     *
     * @throws StripeException When Stripe cannot be reached or refuses (also when the customer is not at Stripe and cannot be replaced,
     *                         see {@see self::replaceLost()}).
     * @throws PreviousOwnerSubscriptionException When the owner is not the payer and the subscription of the payer is still in play.
     * @throws ModelNotFoundException When the workspace was deleted meanwhile.
     */
    public function ensure(Workspace $workspace, User $owner, string $locale): BillingAccount
    {
        $account = $this->find($workspace);

        if ($account === null) {
            $customerId = $this->createCustomer($workspace, $owner, $locale, null);

            return $this->store($workspace, $owner, $customerId);
        }

        if (! $this->isPayer($account, $owner)) {
            return $this->startFresh($workspace, $account, $owner, $locale);
        }

        try {
            $this->follow($account, $owner);
        } catch (StripeException $exception) {
            if ($exception->code() !== 'resource_missing') {
                throw $exception;
            }

            // The customer the account holds is not at Stripe: the keys of the instance were changed for those of another mode
            // (test to live) or another Stripe account. It is replaced, rather than leaving the workspace unable to ever pay.
            return $this->replaceLost($workspace, $account, $owner, $locale, $exception);
        }

        return $account;
    }

    /**
     * Gives the customer of an account the email of the owner who pays. Only for the payer: the customer of another person is left as it
     * is.
     *
     * @throws StripeException
     */
    public function follow(BillingAccount $account, User $owner): void
    {
        $this->client->updateCustomer($account->stripe_customer_id, $owner->email);
    }

    /**
     * Gives the account a customer of the new owner, who becomes the payer, in place of the one of the owner before: a new customer is
     * created (outside any transaction), then written in the account under the lock of the workspace, and what the account said of the
     * subscription of the old one is cleared. Only when the old payer has no subscription in play: the old customer, its invoices and its
     * subscription stay at Stripe, untouched.
     *
     * @throws PreviousOwnerSubscriptionException When a subscription of the old payer is in play (also when it turns out so under the lock).
     * @throws StripeException
     * @throws ModelNotFoundException When the workspace is gone.
     */
    public function startFresh(Workspace $workspace, BillingAccount $account, User $owner, string $locale): BillingAccount
    {
        if (in_array($account->status, SubscriptionStatus::CURRENT, true)) {
            throw new PreviousOwnerSubscriptionException($account);
        }

        $swapped = $this->swap($workspace, $account, $owner, $locale, 'new_owner');

        if ($swapped === null) {
            throw new PreviousOwnerSubscriptionException($this->find($workspace) ?? $account);
        }

        return $swapped;
    }

    /**
     * Creates the customer at Stripe. The idempotency key is the same for two identical requests (two owners clicking at once get the same
     * customer) and, for a customer that replaces another, is made of the workspace and the customer it replaces only (a key is remembered
     * by Stripe for a day: it must not hand back the customer that is gone, and it holds no email, which is the address of a person).
     *
     * @param  string|null  $replaces  The id of the customer that is replaced, if any.
     *
     * @throws StripeException
     */
    private function createCustomer(Workspace $workspace, User $owner, string $locale, ?string $replaces): string
    {
        $fingerprint = $replaces ?? implode("\n", [$owner->email, $workspace->name, $locale]);

        return $this->client->createCustomer(
            $owner->email,
            $workspace->name,
            $workspace->ulid,
            $locale,
            'customer-'.$workspace->ulid.'-'.substr(hash('sha256', $fingerprint), 0, 16),
        );
    }

    /**
     * Replaces the customer of the account, which Stripe does not know any more, by a new one of the owner. Refused while the account
     * has a subscription in play: the workspace is Pro on the word of its row, and forgetting the row would silently drop what it pays for
     * (the keys of the instance are far more likely to be wrong than every customer to be gone). The refusal is written in the log with the
     * customer, the administrators are told, and the error of Stripe goes up (the person is told the service is unavailable).
     *
     * @param  StripeException  $missing  What Stripe answered when the customer was used.
     *
     * @throws StripeException
     * @throws ModelNotFoundException When the workspace is gone.
     */
    private function replaceLost(Workspace $workspace, BillingAccount $account, User $owner, string $locale, StripeException $missing): BillingAccount
    {
        $replaced = in_array($account->status, SubscriptionStatus::CURRENT, true) ? null : $this->swap($workspace, $account, $owner, $locale, 'lost');

        if ($replaced === null) {
            Log::error('The Stripe customer of a workspace that has a subscription is not at Stripe: it is not replaced (were the keys of the instance changed?).', [
                'workspace' => $workspace->ulid,
                'customer' => $account->stripe_customer_id,
                'status' => $account->status,
            ]);
            $this->alerts->reportProblem('customer_lost', $account->stripe_customer_id, $workspace->ulid);

            throw $missing;
        }

        return $replaced;
    }

    /**
     * Gives the account another customer: a new one is created (outside any transaction), then written in the account under the lock of
     * the workspace with the owner as the payer, and what the account said of the subscription of the old one is cleared. A request that
     * finds the customer replaced meanwhile keeps the new one. The customer that is replaced is always written in the log.
     *
     * @param  string  $reason  `lost` (Stripe does not know the customer) or `new_owner` (the workspace changed hands), for the log.
     * @return BillingAccount|null The account as stored; null when it has a subscription in play under the lock (nothing was changed).
     *
     * @throws StripeException
     * @throws ModelNotFoundException When the workspace is gone.
     */
    private function swap(Workspace $workspace, BillingAccount $account, User $owner, string $locale, string $reason): ?BillingAccount
    {
        $gone = $account->stripe_customer_id;
        $customerId = $this->createCustomer($workspace, $owner, $locale, $gone);

        return DeadlockRetries::run(fn (): ?BillingAccount => $this->db->transaction(function () use ($workspace, $account, $owner, $gone, $customerId, $reason): ?BillingAccount {
            if ($this->locks->workspaces([$workspace->getKey()])->isEmpty()) {
                throw (new ModelNotFoundException)->setModel(Workspace::class, [$workspace->getKey()]);
            }

            $stored = BillingAccount::query()->whereKey($account->getKey())->first();

            if ($stored === null) {
                throw (new ModelNotFoundException)->setModel(BillingAccount::class, [$account->getKey()]);
            }

            if ($stored->stripe_customer_id !== $gone) {
                return $stored;
            }

            // A subscription that was written meanwhile (a synchronisation, a webhook) is not forgotten.
            if (in_array($stored->status, SubscriptionStatus::CURRENT, true)) {
                return null;
            }

            $stored->forceFill([
                'stripe_customer_id' => $customerId,
                'payer_id' => $owner->getKey(),
                'stripe_subscription_id' => null,
                'status' => null,
                'price_id' => null,
                'interval' => null,
                'current_period_end' => null,
                'cancel_at_period_end' => false,
                'synced_at' => null,
            ])->save();

            Log::warning('The Stripe customer of a workspace was replaced by a new one.', [
                'workspace' => $workspace->ulid,
                'reason' => $reason,
                'customer' => $gone,
                'replaced_by' => $customerId,
            ]);

            return $stored;
        }));
    }

    /**
     * Stores the customer that was just created, under the lock of the workspace, with the owner who creates it as the payer; keeps the
     * one another request stored first.
     *
     * @throws ModelNotFoundException When the workspace is gone.
     */
    private function store(Workspace $workspace, User $owner, string $customerId): BillingAccount
    {
        return DeadlockRetries::run(fn (): BillingAccount => $this->db->transaction(function () use ($workspace, $owner, $customerId): BillingAccount {
            if ($this->locks->workspaces([$workspace->getKey()])->isEmpty()) {
                throw (new ModelNotFoundException)->setModel(Workspace::class, [$workspace->getKey()]);
            }

            // A plain read after the lock: it sees what a request that held the lock before this one committed.
            return $this->find($workspace) ?? BillingAccount::query()->create([
                'workspace_id' => $workspace->getKey(),
                'stripe_customer_id' => $customerId,
                'payer_id' => $owner->getKey(),
            ]);
        }));
    }
}
