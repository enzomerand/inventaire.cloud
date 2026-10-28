<?php

declare(strict_types=1);

use App\Billing\Plan;
use App\Billing\WorkspacePlan;
use App\Models\BillingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/billing.php';

/*
 * The plans and which one a workspace is on: Pro while Stripe says the subscription is active, trialing or past due, Free otherwise
 * (and always Free, without asking the database, when billing is off).
 */

uses(RefreshDatabase::class);

/**
 * The configuration file of billing as a fresh process would read it with some environment variables set.
 *
 * @param  array<string, string>  $environment
 * @return array<string, mixed>
 */
function billingConfigWith(array $environment): array
{
    foreach ($environment as $name => $value) {
        putenv("{$name}={$value}");
    }

    try {
        return require config_path('billing.php');
    } finally {
        foreach (array_keys($environment) as $name) {
            putenv($name);
        }
    }
}

describe('the limits of a plan', function (): void {
    it('are the ones of the specification by default', function (): void {
        expect(Plan::Free->limit('inventories'))->toBe(3)
            ->and(Plan::Free->limit('items'))->toBe(500)
            ->and(Plan::Free->limit('storage_bytes'))->toBe(209715200)
            ->and(Plan::Free->limit('members'))->toBe(3)
            ->and(Plan::Free->limit('share_links'))->toBe(2)
            ->and(Plan::Free->allows('photo_entry'))->toBeFalse()
            ->and(Plan::Pro->limit('inventories'))->toBe(100)
            ->and(Plan::Pro->limit('items'))->toBe(10000)
            ->and(Plan::Pro->limit('storage_bytes'))->toBe(10737418240)
            ->and(Plan::Pro->limit('members'))->toBe(50)
            ->and(Plan::Pro->limit('share_links'))->toBe(20)
            ->and(Plan::Pro->allows('photo_entry'))->toBeTrue();
    });

    it('are read from the configuration each time', function (): void {
        config(['billing.plans.free.limits.inventories' => 7, 'billing.plans.pro.features.photo_entry' => false]);

        expect(Plan::Free->limit('inventories'))->toBe(7)
            ->and(Plan::Pro->allows('photo_entry'))->toBeFalse();
    });

    it('are never negative', function (): void {
        config(['billing.plans.free.limits.items' => -5]);

        expect(Plan::Free->limit('items'))->toBe(0);
    });

    it('are set by environment variables, the storage in megabytes', function (): void {
        $config = billingConfigWith([
            'BILLING_FREE_INVENTORIES' => '9',
            'BILLING_FREE_STORAGE_MB' => '1.5',
            'BILLING_FREE_PHOTO_ENTRY' => 'true',
            'BILLING_PRO_PHOTO_ENTRY' => 'false',
            'BILLING_PRO_MEMBERS' => '8',
            'BILLING_PRO_ITEMS' => '77',
            'BILLING_PRO_SHARE_LINKS' => '4',
            'BILLING_PRO_STORAGE_MB' => '2',
        ]);

        expect($config['plans']['free']['limits']['inventories'])->toBe(9)
            ->and($config['plans']['free']['limits']['storage_bytes'])->toBe(1572864)
            ->and($config['plans']['free']['features']['photo_entry'])->toBeTrue()
            ->and($config['plans']['pro']['features']['photo_entry'])->toBeFalse()
            ->and($config['plans']['pro']['limits'])->toMatchArray(['members' => 8, 'items' => 77, 'share_links' => 4, 'storage_bytes' => 2097152]);
    });

    it('refuse a limit or a feature that does not exist', function (): void {
        expect(fn () => Plan::Free->limit('seats'))->toThrow(InvalidArgumentException::class, 'Unknown plan limit [seats]')
            ->and(fn () => Plan::Pro->allows('sso'))->toThrow(InvalidArgumentException::class, 'Unknown plan feature [sso]');
    });

    it('name the plans as the API does', function (): void {
        expect(Plan::Free->value)->toBe('free')
            ->and(Plan::Pro->value)->toBe('pro')
            ->and(Plan::from('pro'))->toBe(Plan::Pro);
    });
});

describe('the plan of a workspace', function (): void {
    beforeEach(fn () => billingOn());

    it('is Pro while the subscription is active, trialing or past due', function (string $status): void {
        expect(WorkspacePlan::for(billingWorkspace($status)))->toBe(Plan::Pro);
    })->with(['active', 'trialing', 'past_due']);

    it('is Free for every other status', function (string $status): void {
        expect(WorkspacePlan::for(billingWorkspace($status)))->toBe(Plan::Free);
    })->with(['canceled', 'unpaid', 'paused', 'incomplete', 'incomplete_expired', 'something_new']);

    it('is Free for a workspace that never started a checkout', function (): void {
        expect(WorkspacePlan::for(billingWorkspace()))->toBe(Plan::Free);
    });

    it('is Free for a customer that has no subscription yet', function (): void {
        $workspace = billingWorkspace();
        BillingAccount::factory()->for($workspace)->create();

        expect(WorkspacePlan::for($workspace))->toBe(Plan::Free);
    });

    it('is the plan of its own account, not of another workspace', function (): void {
        $pro = billingWorkspace('active');
        $free = billingWorkspace('canceled');

        expect(WorkspacePlan::for($pro))->toBe(Plan::Pro)
            ->and(WorkspacePlan::for($free))->toBe(Plan::Free);
    });

    it('follows the account as it changes, even for a workspace the caller already holds', function (): void {
        $workspace = billingWorkspace('active');
        expect(WorkspacePlan::for($workspace))->toBe(Plan::Pro);

        $workspace->billingAccount()->update(['status' => 'canceled']);

        expect(WorkspacePlan::for($workspace))->toBe(Plan::Free);
    });

    it('is Free, without a query, when billing is off, whatever the account says', function (): void {
        $workspace = billingWorkspace('active');
        billingOff();

        $statements = statementsOf(fn () => expect(WorkspacePlan::for($workspace))->toBe(Plan::Free));

        expect($statements)->toBe([]);
    });
});
