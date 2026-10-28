<?php

declare(strict_types=1);

use App\Billing\BillingSync;
use App\Billing\Plan;
use App\Billing\PlanMemory;
use App\Billing\WorkspaceLimits;
use App\Billing\WorkspacePlan;
use App\Inventories\Items;
use App\Jobs\SyncBillingAccount;
use App\Models\BillingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/billing.php';
require_once __DIR__.'/../../Support/inventories.php';

/*
 * The plan of a workspace is read once for the length of a request or a job, so that listings and loops do not read it again and again; it
 * is forgotten as soon as a subscription is written in the process, and a worker starts each job with a memory of its own.
 */

uses(RefreshDatabase::class);

/**
 * The statements a callback ran that read the billing accounts.
 *
 * @return list<string>
 */
function planReads(Closure $callback): array
{
    return array_values(array_filter(statementsOf($callback), fn (string $sql): bool => str_contains($sql, 'billing_accounts')));
}

describe('the reads', function (): void {
    beforeEach(fn () => billingOn());

    it('are one for all the questions asked about a workspace', function (): void {
        $workspace = billingWorkspace('active');

        $reads = planReads(function () use ($workspace): void {
            foreach (range(1, 5) as $ignored) {
                WorkspaceLimits::inventories($workspace);
                WorkspaceLimits::itemsPerInventory($workspace);
                WorkspaceLimits::storageBytes($workspace);
                WorkspaceLimits::members($workspace);
                WorkspaceLimits::shareLinksPerInventory($workspace);
                WorkspaceLimits::photoEntry($workspace);
                WorkspacePlan::for($workspace);
            }
        });

        expect($reads)->toHaveCount(1);
    });

    it('are one for each workspace, and the answers stay right for each', function (): void {
        $pro = billingWorkspace('active');
        $free = billingWorkspace();
        $canceled = billingWorkspace('canceled');
        $answers = [];

        $reads = planReads(function () use ($pro, $free, $canceled, &$answers): void {
            foreach (range(1, 3) as $ignored) {
                $answers = [WorkspacePlan::for($pro), WorkspacePlan::for($free), WorkspacePlan::for($canceled)];
            }
        });

        expect($reads)->toHaveCount(3)->and($answers)->toBe([Plan::Pro, Plan::Free, Plan::Free]);
    });

    it('take the id of the workspace as well as the workspace, and share their answer', function (): void {
        $workspace = billingWorkspace('active');

        $reads = planReads(function () use ($workspace): void {
            WorkspaceLimits::inventories($workspace);
            WorkspaceLimits::inventories($workspace->id);
            WorkspacePlan::for($workspace->id);
        });

        expect($reads)->toHaveCount(1)->and(WorkspaceLimits::inventories($workspace->id))->toBe(WorkspaceLimits::inventories($workspace));
    });

    it('remember a workspace without an account as Free, and do not look for it again', function (): void {
        $workspace = billingWorkspace();

        $reads = planReads(function () use ($workspace): void {
            expect(WorkspacePlan::for($workspace))->toBe(Plan::Free)->and(WorkspacePlan::for($workspace))->toBe(Plan::Free);
        });

        expect($reads)->toHaveCount(1);
    });

    it('do not make a loop of creations read the plan for each item', function (): void {
        config(['billing.plans.pro.limits.items' => 50]);
        $team = inventoryTeam();
        BillingAccount::factory()->for($team['workspace'])->subscribed('active')->create();
        $inventory = inventoryIn($team['workspace']);

        $reads = planReads(function () use ($inventory, $team): void {
            foreach (range(1, 6) as $ignored) {
                app(Items::class)->create($inventory, [], $team['owner']);
            }
        });

        expect($reads)->toHaveCount(1)->and($inventory->items()->count())->toBe(6);
    });

    it('are none when billing is off, and nothing is remembered then', function (): void {
        $workspace = billingWorkspace('active');
        billingOff();

        $reads = planReads(fn () => WorkspaceLimits::inventories($workspace));

        expect($reads)->toBe([])->and(app(PlanMemory::class)->recall($workspace->id))->toBeNull();
    });
});

describe('what is remembered', function (): void {
    beforeEach(fn () => billingOn());

    it('is forgotten when the synchronisation writes the account, so that the next check sees the new plan', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $workspace = $account->workspace;

        expect(WorkspacePlan::for($workspace))->toBe(Plan::Free);

        $subscription = $stripe->subscribe($account->stripe_customer_id, 'active');
        app(BillingSync::class)->sync($account);

        expect(WorkspacePlan::for($workspace))->toBe(Plan::Pro)
            ->and(WorkspaceLimits::photoEntry($workspace))->toBeTrue();

        $stripe->setStatus($subscription, 'canceled');
        app(BillingSync::class)->sync($account->refresh());

        expect(WorkspacePlan::for($workspace))->toBe(Plan::Free);
    });

    it('is forgotten when the job of the synchronisation runs in the process', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        expect(WorkspacePlan::for($account->workspace))->toBe(Plan::Free);

        $stripe->subscribe($account->stripe_customer_id, 'trialing');
        (new SyncBillingAccount($account->id))->handle(app(BillingSync::class));

        expect(WorkspacePlan::for($account->workspace))->toBe(Plan::Pro);
    });

    it('is forgotten when an account is made, changed or deleted through its model', function (): void {
        $workspace = billingWorkspace();
        expect(WorkspacePlan::for($workspace))->toBe(Plan::Free);

        $account = BillingAccount::factory()->for($workspace)->subscribed('active')->create();
        expect(WorkspacePlan::for($workspace))->toBe(Plan::Pro);

        $account->update(['status' => 'canceled']);
        expect(WorkspacePlan::for($workspace))->toBe(Plan::Free);

        $account->update(['status' => 'past_due']);
        expect(WorkspacePlan::for($workspace))->toBe(Plan::Pro);

        $account->delete();
        expect(WorkspacePlan::for($workspace))->toBe(Plan::Free);
    });

    it('is the memory of one request or job: the container hands a new one to the next', function (): void {
        $workspace = billingWorkspace('active');
        $first = app(PlanMemory::class);

        expect(WorkspacePlan::for($workspace))->toBe(Plan::Pro)
            ->and(app(PlanMemory::class))->toBe($first)
            ->and($first->recall($workspace->id))->toBe(Plan::Pro);

        // A write that bypasses the models (a statement of the query builder, another process) is not seen until the next request or job.
        DB::table('billing_accounts')->where('workspace_id', $workspace->id)->update(['status' => 'canceled']);

        expect(WorkspacePlan::for($workspace))->toBe(Plan::Pro);

        app()->forgetScopedInstances();

        expect(app(PlanMemory::class))->not->toBe($first)
            ->and(WorkspacePlan::for($workspace))->toBe(Plan::Free);
    });

    it('is not carried from one queued job to the next by a worker', function (): void {
        config(['queue.default' => 'database']);
        $workspace = billingWorkspace('active');
        expect(WorkspacePlan::for($workspace))->toBe(Plan::Pro);
        $before = app(PlanMemory::class);
        // A write nothing in this process knows of, as another process would make.
        DB::table('billing_accounts')->where('workspace_id', $workspace->id)->update(['status' => 'canceled']);
        // Any job will do: the worker forgets the scoped instances of the container at the start of each turn of its loop.
        dispatch(function (): void {});

        $this->artisan('queue:work', ['--stop-when-empty' => true, '--memory' => 8192])->assertSuccessful();

        expect(app(PlanMemory::class))->not->toBe($before)
            ->and(app(PlanMemory::class)->recall($workspace->id))->toBeNull()
            ->and(WorkspacePlan::for($workspace))->toBe(Plan::Free);
    });
});
