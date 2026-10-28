<?php

declare(strict_types=1);

namespace App\Billing;

use App\Jobs\CancelStripeSubscription;
use App\Models\BillingAccount;
use App\Models\Workspace;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeakMap;

/**
 * What becomes of the subscription of a workspace at Stripe when the workspace stops existing or changes hands: whoever pays must not
 * go on paying for a workspace that is gone, or for one that somebody else owns now.
 *
 * - **The workspace is deleted** (the owner deleting it, an account that owned it alone being deleted, an administrator deleting that
 *   account: whichever way the model is deleted). The subscriptions of its customer are cancelled at once, in the background
 *   ({@see CancelStripeSubscription}). Like the files and the open signature requests, the customer is worked out in the `deleting` event
 *   of the model (the row of the account is still there; the database cascades it away without an event) and the job is queued in its
 *   `deleted` event, after the commit of the transaction that deletes it: a deletion that fails, or is retried after a deadlock, cancels
 *   nothing ({@see self::collectForWorkspace()}, {@see self::dispatchCollected()}).
 * - **The workspace changes owner** (the owner handing it over, or the administrator deleting the account of an owner whose workspace
 *   others belong to). The subscriptions of the old payer's customer are set to end at the end of the period that is paid for: the
 *   workspace stays Pro until then, nobody is charged again, and the new owner subscribes on their own if they want to
 *   ({@see self::ownerChanged()}).
 *
 * Both act on **the customer**, whenever the workspace has a billing account, and not on the subscription the account records: the job
 * lists the subscriptions of the customer at Stripe and ends those that are not over. What the account records is what the last
 * synchronisation saw, and a workspace deleted after a Checkout but before that synchronisation has none recorded yet. Nothing here calls
 * Stripe: a call inside a transaction would hold the locks for as long as Stripe takes to answer, and could be made for a transaction
 * that is then rolled back. The jobs are queued once the transaction is committed (at once when there is none). A queue that cannot be
 * reached is written in the log, for somebody to cancel the subscriptions in the dashboard of Stripe.
 */
final class SubscriptionLifecycle
{
    /**
     * The Stripe customer whose subscriptions to cancel, for each workspace that is being deleted, between its `deleting` and its `deleted`
     * event. Dropped with the model if the deletion fails.
     *
     * @var WeakMap<Workspace, string>|null
     */
    private static ?WeakMap $collected = null;

    /**
     * Gets the database.
     */
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Remembers the customer of a workspace that is being deleted, whose subscriptions {@see self::dispatchCollected()} has cancelled once
     * the workspace is really gone. Called by the `deleting` event of {@see Workspace}, inside the transaction of the deletion (which holds the workspace
     * locked, so that the account does not change meanwhile).
     */
    public function collectForWorkspace(Workspace $workspace): void
    {
        $collected = self::collected();
        // Replaces what an earlier try of the deletion collected.
        unset($collected[$workspace]);

        $account = $this->accountOf($workspace);

        if ($account !== null) {
            $collected[$workspace] = $account->stripe_customer_id;
        }
    }

    /**
     * Queues the cancellation of the subscriptions of the customer remembered for a workspace that has just been deleted, once the
     * transaction that deletes it is committed (at once when there is none). Called by the `deleted` event of {@see Workspace}.
     */
    public function dispatchCollected(Workspace $workspace): void
    {
        $collected = self::collected();
        $customer = $collected[$workspace] ?? null;
        unset($collected[$workspace]);

        if ($customer !== null) {
            $this->cancelAfterCommit($customer, false, null);
        }
    }

    /**
     * Has the subscriptions of the customer of a workspace that has a new owner end at the end of the period that is paid for, once the
     * transaction that changes the owner is committed. Does nothing for a workspace that has no billing account (it never started a
     * checkout). Called inside that transaction, with the workspace locked.
     */
    public function ownerChanged(Workspace $workspace): void
    {
        $account = $this->accountOf($workspace);

        if ($account !== null) {
            $this->cancelAfterCommit($account->stripe_customer_id, true, (int) $account->getKey());
        }
    }

    /**
     * The billing account of a workspace, if it has one.
     */
    private function accountOf(Workspace $workspace): ?BillingAccount
    {
        return BillingAccount::query()->where('workspace_id', $workspace->getKey())->first();
    }

    /**
     * Queues the cancellation of the subscriptions of a customer once the transaction in progress is committed (at once when there is
     * none): one that is rolled back, or retried after a deadlock, cancels nothing.
     */
    private function cancelAfterCommit(string $customer, bool $atPeriodEnd, ?int $billingAccountId): void
    {
        $this->db->connection()->afterCommit(function () use ($customer, $atPeriodEnd, $billingAccountId): void {
            try {
                CancelStripeSubscription::dispatch($customer, $atPeriodEnd, $billingAccountId);
            } catch (Throwable $exception) {
                Log::error('The cancellation of the subscriptions of a customer could not be queued: cancel them in the dashboard of Stripe.', ['customer' => $customer, 'at_period_end' => $atPeriodEnd, 'exception' => $exception]);
            }
        });
    }

    /**
     * What was collected, by workspace.
     *
     * @return WeakMap<Workspace, string>
     */
    private static function collected(): WeakMap
    {
        return self::$collected ??= new WeakMap;
    }
}
