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

/**
 * The Stripe customer of a workspace: the one the owner pays as, created the first time somebody goes to Checkout and kept in the
 * {@see BillingAccount} of the workspace.
 *
 * Stripe is never called inside a transaction (the locks would be held for as long as it takes to answer): the customer is created
 * first, and stored afterwards under the lock of the workspace. A request that finds another one stored meanwhile (two owners clicking
 * at once) keeps the stored one; the idempotency key of the creation is the same for two identical requests, so they get the same
 * customer from Stripe and nothing is left behind.
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
            $this->follow($account, $owner);

            return $account;
        }

        $customerId = $this->client->createCustomer(
            $owner->email,
            $workspace->name,
            $workspace->ulid,
            $locale,
            'customer-'.$workspace->ulid.'-'.substr(hash('sha256', implode("\n", [$owner->email, $workspace->name, $locale])), 0, 16),
        );

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
