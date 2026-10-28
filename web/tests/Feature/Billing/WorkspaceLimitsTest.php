<?php

declare(strict_types=1);

use App\Billing\WorkspaceLimits;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/billing.php';

/*
 * The one place the quotas of a workspace are read from. With billing off, each answer is what the call sites read before billing existed
 * (the settings of the instance); with billing on, it is the limit of the plan the workspace is on.
 */

uses(RefreshDatabase::class);

/**
 * Every limit of a workspace, in one list.
 *
 * @return array<string, int|bool>
 */
function billingLimitsOf(Workspace $workspace): array
{
    return [
        'inventories' => WorkspaceLimits::inventories($workspace),
        'items' => WorkspaceLimits::itemsPerInventory($workspace),
        'storage_bytes' => WorkspaceLimits::storageBytes($workspace),
        'members' => WorkspaceLimits::members($workspace),
        'share_links' => WorkspaceLimits::shareLinksPerInventory($workspace),
        'photo_entry' => WorkspaceLimits::photoEntry($workspace),
    ];
}

describe('with billing off', function (): void {
    beforeEach(fn () => billingOff());

    it('gives exactly what the settings of the instance say, to a workspace on any plan', function (?string $status): void {
        config([
            'inventories.max_per_workspace' => 11,
            'inventories.max_items' => 222,
            'inventories.storage_per_workspace' => 3333,
            'workspaces.max_members' => 44,
            'inventories.max_share_links' => 5,
        ]);

        expect(billingLimitsOf(billingWorkspace($status)))->toBe([
            'inventories' => 11,
            'items' => 222,
            'storage_bytes' => 3333,
            'members' => 44,
            'share_links' => 5,
            'photo_entry' => true,
        ]);
    })->with([null, 'active', 'canceled']);

    it('gives the defaults of today when nothing is set', function (): void {
        expect(billingLimitsOf(billingWorkspace()))->toBe([
            'inventories' => 100,
            'items' => 10000,
            'storage_bytes' => 1073741824,
            'members' => 50,
            'share_links' => 20,
            'photo_entry' => true,
        ]);
    });

    it('ignores the limits of the plans', function (): void {
        config(['billing.plans.free.limits.inventories' => 1, 'billing.plans.pro.limits.inventories' => 2, 'billing.plans.free.features.photo_entry' => false]);

        expect(WorkspaceLimits::inventories(billingWorkspace('active')))->toBe(100)
            ->and(WorkspaceLimits::photoEntry(billingWorkspace()))->toBeTrue();
    });

    it('does not ask the database', function (): void {
        $workspace = billingWorkspace('active');

        expect(statementsOf(fn () => billingLimitsOf($workspace)))->toBe([]);
    });
});

describe('with billing on', function (): void {
    beforeEach(fn () => billingOn());

    it('gives a workspace without a subscription the limits of the Free plan', function (): void {
        expect(billingLimitsOf(billingWorkspace()))->toBe([
            'inventories' => 3,
            'items' => 500,
            'storage_bytes' => 209715200,
            'members' => 3,
            'share_links' => 2,
            'photo_entry' => false,
        ]);
    });

    it('gives a workspace with a subscription the limits of the Pro plan', function (string $status): void {
        expect(billingLimitsOf(billingWorkspace($status)))->toBe([
            'inventories' => 100,
            'items' => 10000,
            'storage_bytes' => 10737418240,
            'members' => 50,
            'share_links' => 20,
            'photo_entry' => true,
        ]);
    })->with(['active', 'trialing', 'past_due']);

    it('gives it back the Free limits when the subscription ends', function (): void {
        $workspace = billingWorkspace('active');
        expect(WorkspaceLimits::inventories($workspace))->toBe(100);

        $workspace->billingAccount()->update(['status' => 'canceled']);

        expect(WorkspaceLimits::inventories($workspace))->toBe(3)
            ->and(WorkspaceLimits::photoEntry($workspace))->toBeFalse();
    });

    it('does not look at the settings of the instance, only at the plan', function (): void {
        config(['inventories.max_per_workspace' => 1, 'workspaces.max_members' => 1, 'billing.plans.pro.limits.inventories' => 250]);

        expect(WorkspaceLimits::inventories(billingWorkspace('active')))->toBe(250)
            ->and(WorkspaceLimits::members(billingWorkspace('active')))->toBe(50);
    });

    it('answers per workspace', function (): void {
        $pro = billingWorkspace('active');
        $free = billingWorkspace();

        expect(WorkspaceLimits::inventories($pro))->toBe(100)
            ->and(WorkspaceLimits::inventories($free))->toBe(3);
    });
});
