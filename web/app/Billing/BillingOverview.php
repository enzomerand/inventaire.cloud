<?php

declare(strict_types=1);

namespace App\Billing;

use App\Landing\PricingPlans;
use App\Models\BillingWithdrawal;
use App\Models\Inventory;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;

/**
 * What the subscription page of a workspace shows: whether the instance sells plans, the plan the workspace is on and the state of its
 * subscription, what a plan costs, the limits that apply to the workspace and how much of them it uses.
 *
 * It answers from the database only (the account row the last synchronisation wrote, {@see BillingSync}): never from a call to Stripe.
 * The limits are the ones {@see WorkspaceLimits} gives every call site, so that the page and the refusals can never disagree; with
 * billing off they are the limits of the instance, and the page says that no plan is offered.
 */
final class BillingOverview
{
    /**
     * The overview of a workspace, as the billing endpoint of the API describes it (`data`).
     *
     * `current_period_end` is an ISO 8601 moment in UTC. The `status`, `interval`, `current_period_end` and `cancel_at_period_end` of a
     * workspace that never started a checkout, or of any workspace while billing is off, are empty (`null`, `false`). `prices.year` is
     * `null` when no yearly Price is set. The usage counts the members and the invitations that are waiting together, as the limit of
     * members does.
     *
     * `selling` is whether Pro can be bought now (billing is on and the identity of the seller is complete, see {@see PricingPlans::selling()}):
     * an instance that has Stripe but an incomplete seller shows no way to buy. `started_at` is when the subscription began, and
     * `withdrawal_until` the last moment of its right of withdrawal while the subscription is live, inside that window and not withdrawn
     * from (null otherwise, {@see WithdrawalDeadline}); `withdrawn` says a withdrawal was recorded for the subscription that is still in
     * play (it ends once the cancellation went through). The three are for those who may manage the subscription: for any other member
     * the keys are there and empty (`null`, `null`, `false`).
     *
     * @param  bool  $canManage  Whether the person who asks may buy and manage the subscription (the owners).
     * @return array{
     *     enabled: bool,
     *     selling: bool,
     *     plan: string,
     *     status: string|null,
     *     interval: string|null,
     *     current_period_end: string|null,
     *     cancel_at_period_end: bool,
     *     started_at: string|null,
     *     withdrawal_until: string|null,
     *     withdrawn: bool,
     *     can_manage: bool,
     *     prices: array{currency: string, month: int, year: int|null},
     *     limits: array{inventories: int, items: int, storage_bytes: int, members: int, share_links: int, photo_entry: bool},
     *     usage: array{inventories: int, storage_bytes: int, members: int}
     * }
     */
    public function payload(Workspace $workspace, bool $canManage): array
    {
        $enabled = Billing::enabled();
        $account = $enabled ? $workspace->billingAccount()->first() : null;
        // The contract is the business of those who may manage it: nothing of it is read for a member, nor for a workspace with no
        // subscription, nor while billing is off.
        $subscription = $canManage ? $account?->stripe_subscription_id : null;
        $recorded = $subscription !== null && BillingWithdrawal::query()->where('stripe_subscription_id', $subscription)->exists();

        return [
            'enabled' => $enabled,
            'selling' => PricingPlans::selling(),
            'plan' => WorkspacePlan::for($workspace)->value,
            'status' => $account?->status,
            'interval' => $account?->interval,
            'current_period_end' => $account?->current_period_end?->utc()->toIso8601String(),
            'cancel_at_period_end' => $account->cancel_at_period_end ?? false,
            'started_at' => $canManage ? $account?->started_at?->utc()->toIso8601String() : null,
            'withdrawal_until' => $canManage && $account !== null && $account->started_at !== null && ! $recorded && Withdrawals::isOpen($account, now())
                ? Withdrawals::windowEnd($account->started_at)->utc()->toIso8601String()
                : null,
            // Only while the subscription is still in play: once it is over the workspace is back on Free and has nothing to say of it.
            'withdrawn' => $recorded && in_array($account->status, SubscriptionStatus::CURRENT, true),
            'can_manage' => $canManage,
            'prices' => Billing::display(),
            'limits' => [
                'inventories' => WorkspaceLimits::inventories($workspace),
                'items' => WorkspaceLimits::itemsPerInventory($workspace),
                'storage_bytes' => WorkspaceLimits::storageBytes($workspace),
                'members' => WorkspaceLimits::members($workspace),
                'share_links' => WorkspaceLimits::shareLinksPerInventory($workspace),
                'photo_entry' => WorkspaceLimits::photoEntry($workspace),
            ],
            'usage' => [
                'inventories' => Inventory::query()->where('workspace_id', $workspace->getKey())->count(),
                'storage_bytes' => max(0, $workspace->storage_used),
                'members' => $workspace->members()->count()
                    + WorkspaceInvitation::query()->where('workspace_id', $workspace->getKey())->pending()->count(),
            ],
        ];
    }
}
