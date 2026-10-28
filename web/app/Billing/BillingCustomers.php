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
 * {@see BillingAccount} of the workspace.
 *
 * Stripe is never called inside a transaction (the locks would be held for as long as it takes to answer): the customer is created
 * first, and stored afterwards under the lock of the workspace. A request that finds another one stored meanwhile (two owners clicking
 * at once) keeps the stored one; the idempotency key of the creation is the same for two identical requests, so they get the same
 * customer from Stripe and nothing is left behind.
 *
 * A customer that Stripe does not know any more (the keys of the instance were switched from the test mode to the live one, or to another
 * Stripe account) is replaced by a new one in the same account, and the subscription the account remembered is forgotten with it.
 *
 * The address of the customer is the one of the owner who goes to Checkout or to the portal now, so that the receipts follow the
 * workspace when it changes hands (the application does not keep the address it gave Stripe, so it gives it each time: Stripe makes no
 * change when it is the same).
 */
final class BillingCustomers
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
     * The account of a workspace if it has one (it started a checkout once), else null.
     */
    public function find(Workspace $workspace): ?BillingAccount
    {
        return BillingAccount::query()->where('workspace_id', $workspace->getKey())->first();
    }

    /**
     * The account of the workspace, with a customer at Stripe whose address is the one of the owner: the customer is created when the
     * workspace has none, and its address is brought up to date when it has one.
     *
     * @param  string  $locale  The language of the owner, for the emails and invoices Stripe sends.
     *
     * @throws StripeException When Stripe cannot be reached or refuses.
     * @throws ModelNotFoundException When the workspace was deleted meanwhile.
     */
    public function ensure(Workspace $workspace, User $owner, string $locale): BillingAccount
    {
        $account = $this->find($workspace);

        if ($account !== null) {
            try {
                $this->follow($account, $owner);
            } catch (StripeException $exception) {
                if ($exception->code() !== 'resource_missing') {
                    throw $exception;
                }

                // The customer the account holds is not at Stripe: the keys of the instance were changed for those of another mode
                // (test to live) or another Stripe account. It is replaced, rather than leaving the workspace unable to ever pay.
                return $this->replace($workspace, $account, $owner, $locale);
            }

            return $account;
        }

        $customerId = $this->createCustomer($workspace, $owner, $locale, null);

        return $this->store($workspace, $customerId);
    }

    /**
     * Gives the customer of an account the address of the owner who acts now.
     *
     * @throws StripeException
     */
    public function follow(BillingAccount $account, User $owner): void
    {
        $this->client->updateCustomer($account->stripe_customer_id, $owner->email);
    }

    /**
     * Creates the customer at Stripe. The idempotency key is the same for two identical requests (two owners clicking at once get the same
     * customer) and, for a customer that replaces another, depends on the one it replaces (a key is remembered by Stripe for a day: it must
     * not hand back the customer that is gone).
     *
     * @param  string|null  $replaces  The id of the customer that is replaced, if any.
     *
     * @throws StripeException
     */
    private function createCustomer(Workspace $workspace, User $owner, string $locale, ?string $replaces): string
    {
        $parts = [$owner->email, $workspace->name, $locale];

        return $this->client->createCustomer(
            $owner->email,
            $workspace->name,
            $workspace->ulid,
            $locale,
            'customer-'.$workspace->ulid.'-'.substr(hash('sha256', implode("\n", $replaces === null ? $parts : [...$parts, $replaces])), 0, 16),
        );
    }

    /**
     * Gives the account another customer, because the one it holds is not at Stripe any more: a new customer is created (outside any
     * transaction), then written in the account under the lock of the workspace, and what the account said of the subscription of the old
     * one is cleared (that subscription is not at Stripe either). A request that finds the customer replaced meanwhile keeps the new one.
     *
     * @throws StripeException
     * @throws ModelNotFoundException When the workspace is gone.
     */
    private function replace(Workspace $workspace, BillingAccount $account, User $owner, string $locale): BillingAccount
    {
        $gone = $account->stripe_customer_id;
        $customerId = $this->createCustomer($workspace, $owner, $locale, $gone);

        return DeadlockRetries::run(fn (): BillingAccount => $this->db->transaction(function () use ($workspace, $account, $gone, $customerId): BillingAccount {
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

            $stored->forceFill([
                'stripe_customer_id' => $customerId,
                'stripe_subscription_id' => null,
                'status' => null,
                'price_id' => null,
                'interval' => null,
                'current_period_end' => null,
                'cancel_at_period_end' => false,
                'synced_at' => null,
            ])->save();

            Log::warning('The Stripe customer of a workspace is not at Stripe any more (the keys of the instance changed?): a new one replaces it.', ['workspace' => $workspace->ulid]);

            return $stored;
        }));
    }

    /**
     * Stores the customer that was just created, under the lock of the workspace; keeps the one another request stored first.
     *
     * @throws ModelNotFoundException When the workspace is gone.
     */
    private function store(Workspace $workspace, string $customerId): BillingAccount
    {
        return DeadlockRetries::run(fn (): BillingAccount => $this->db->transaction(function () use ($workspace, $customerId): BillingAccount {
            if ($this->locks->workspaces([$workspace->getKey()])->isEmpty()) {
                throw (new ModelNotFoundException)->setModel(Workspace::class, [$workspace->getKey()]);
            }

            // A plain read after the lock: it sees what a request that held the lock before this one committed.
            return $this->find($workspace) ?? BillingAccount::query()->create([
                'workspace_id' => $workspace->getKey(),
                'stripe_customer_id' => $customerId,
            ]);
        }));
    }
}
