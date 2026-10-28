<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\BillingAccount;
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
     * @param  bool  $canManage  Whether the person who asks may buy and manage the subscription (the owners).
     * @return array{
     *     enabled: bool,
     *     plan: string,
     *     status: string|null,
     *     interval: string|null,
     *     current_period_end: string|null,
     *     cancel_at_period_end: bool,
     *     can_manage: bool,
     *     prices: array{currency: string, month: int, year: int|null},
     *     limits: array{inventories: int, items: int, storage_bytes: int, members: int, share_links: int, photo_entry: bool},
     *     usage: array{inventories: int, storage_bytes: int, members: int}
     * }
     */
    public function payload(Workspace $workspace, bool $canManage): array
    {
        $enabled = Billing::enabled();
        $account = $enabled ? BillingAccount::query()->where('workspace_id', $workspace->getKey())->first() : null;

        return [
            'enabled' => $enabled,
            'plan' => WorkspacePlan::for($workspace)->value,
            'status' => $account?->status,
            'interval' => $account?->interval,
            'current_period_end' => $account?->current_period_end?->utc()->toIso8601String(),
            'cancel_at_period_end' => $account->cancel_at_period_end ?? false,
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
