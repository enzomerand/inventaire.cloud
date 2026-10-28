<?php

declare(strict_types=1);

use App\Admin\UserAdministration;
use App\Jobs\CancelStripeSubscription;
use App\Models\BillingAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Exceptions\MembershipRefused;
use App\Workspaces\Members;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/billing.php';
require_once __DIR__.'/../../Support/inventories.php';

/*
 * What becomes of the subscriptions of a workspace at Stripe when it is deleted (they are cancelled at once) or changes owner (they end with
 * the period that is paid for): in the background, once the change is committed, for the customer of its billing account whatever the
 * account recorded of the subscription (the job lists the subscriptions at Stripe and ends those that are not over).
 */

uses(RefreshDatabase::class);

/**
 * Gives a workspace a billing account with a subscription in a status (the id of its Stripe customer is returned).
 */
function billingSubscribe(Workspace $workspace, string $status = 'active'): string
{
    return BillingAccount::factory()->for($workspace)->subscribed($status)->create()->stripe_customer_id;
}

/**
 * Gives a workspace a billing account that records no subscription (the checkout was paid, and nothing was synchronised yet): the id of
 * its Stripe customer is returned.
 */
function billingCustomerOnly(Workspace $workspace): string
{
    return BillingAccount::factory()->for($workspace)->create()->stripe_customer_id;
}

/**
 * The cancellations the queue holds, as `[customer, at period end, account]`, sorted.
 *
 * @return list<array{0: string, 1: bool, 2: int|null}>
 */
function billingCancellations(): array
{
    $found = Queue::pushed(CancelStripeSubscription::class)
        ->map(fn (CancelStripeSubscription $job): array => [$job->customerId, $job->atPeriodEnd, $job->billingAccountId])
        ->values()
        ->all();
    sort($found);

    return $found;
}

describe('deleting a workspace', function (): void {
    beforeEach(fn () => Queue::fake());

    it('cancels the subscriptions of its customer at once, whichever way it is deleted', function (string $how): void {
        $owner = inventoryPerson();
        $workspace = app(Workspaces::class)->create($owner, 'Shop');
        $customer = billingSubscribe($workspace);

        match ($how) {
            'the model' => $workspace->delete(),
            'the owner' => app(Workspaces::class)->delete($owner->refresh(), $workspace),
            'the account' => $owner->delete(),
            'an administrator' => app(UserAdministration::class)->delete(User::factory()->admin()->withPersonalWorkspace()->create(), $owner),
        };

        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeFalse()
            ->and(billingCancellations())->toBe([[$customer, false, null]]);
    })->with(['the model', 'the owner', 'the account', 'an administrator']);

    it('queues it whatever the account recorded of the subscription: the job asks Stripe which are not over', function (?string $status): void {
        $workspace = app(Workspaces::class)->create(inventoryPerson(), 'Shop');
        $customer = $status === null ? billingCustomerOnly($workspace) : billingSubscribe($workspace, $status);

        $workspace->delete();

        expect(billingCancellations())->toBe([[$customer, false, null]]);
    })->with([
        'a checkout paid and not synchronised yet' => [null],
        'active' => ['active'],
        'trialing' => ['trialing'],
        'past due' => ['past_due'],
        'unpaid' => ['unpaid'],
        'paused' => ['paused'],
        'incomplete' => ['incomplete'],
        'canceled' => ['canceled'],
        'incomplete expired' => ['incomplete_expired'],
    ]);

    it('has nothing to cancel without a billing account: no checkout was ever started', function (): void {
        $bare = app(Workspaces::class)->create(inventoryPerson(), 'Bare');

        $bare->delete();

        expect(billingCancellations())->toBe([]);
    });

    it('cancels the subscriptions of each workspace an account deletes with it, and only those', function (): void {
        $owner = inventoryPerson();
        $first = app(Workspaces::class)->create($owner, 'First');
        $second = app(Workspaces::class)->create($owner, 'Second');
        $kept = app(Workspaces::class)->create(inventoryPerson(), 'Kept');
        $kept->members()->attach($owner->id, ['role' => WorkspaceRole::Editor->value]);
        $one = billingSubscribe($first);
        $two = billingSubscribe($second);
        billingSubscribe($kept);

        $owner->delete();

        $expected = [[$one, false, null], [$two, false, null]];
        sort($expected);

        expect(billingCancellations())->toBe($expected)->and(Workspace::query()->whereKey($kept->id)->exists())->toBeTrue();
    });

    it('queues once the deletion is committed, not before, and not at all when it is rolled back', function (): void {
        $workspace = app(Workspaces::class)->create(inventoryPerson(), 'Shop');
        $customer = billingSubscribe($workspace);

        try {
            DB::transaction(function () use ($workspace): void {
                $workspace->delete();

                Queue::assertNotPushed(CancelStripeSubscription::class);

                throw new RuntimeException('The caller changes its mind.');
            });
        } catch (RuntimeException) {
        }

        Queue::assertNotPushed(CancelStripeSubscription::class);
        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue();

        DB::transaction(function () use ($workspace): void {
            // A model that was deleted in a transaction that was rolled back still believes it is gone.
            Workspace::query()->findOrFail($workspace->id)->delete();

            Queue::assertNotPushed(CancelStripeSubscription::class);
        });

        expect(billingCancellations())->toBe([[$customer, false, null]]);
    });

    it('writes in the log a queue that cannot be reached, for somebody to cancel in Stripe', function (): void {
        Log::spy();
        $workspace = app(Workspaces::class)->create(inventoryPerson(), 'Shop');
        billingSubscribe($workspace, 'active');
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andReturnUsing(function (mixed $job): mixed {
            if ($job instanceof CancelStripeSubscription) {
                throw new RuntimeException('The queue is down.');
            }

            return null;
        }));

        $workspace->delete();

        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeFalse();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'could not be queued') && str_contains($message, 'dashboard of Stripe'))->once();
    });
});

describe('changing the owner of a workspace', function (): void {
    beforeEach(fn () => Queue::fake());

    it('has the subscriptions of the customer end with the period that is paid for, once the change is committed', function (): void {
        $team = inventoryTeam();
        $customer = billingSubscribe($team['workspace']);
        $accountId = BillingAccount::query()->where('workspace_id', $team['workspace']->id)->value('id');

        app(Members::class)->transferOwnership($team['owner'], $team['workspace'], $team['editor']);

        expect(billingCancellations())->toBe([[$customer, true, $accountId]]);
    });

    it('tells the job the workspace, for the mail to the administrators if it cannot cancel', function (): void {
        $team = inventoryTeam();
        $workspace = $team['workspace'];
        $deleted = app(Workspaces::class)->create(inventoryPerson(), 'Gone');
        billingSubscribe($workspace);
        billingSubscribe($deleted);

        app(Members::class)->transferOwnership($team['owner'], $workspace, $team['editor']);
        $deleted->delete();

        $ulids = Queue::pushed(CancelStripeSubscription::class)->mapWithKeys(fn (CancelStripeSubscription $job): array => [$job->atPeriodEnd ? 'handed over' : 'deleted' => $job->workspaceUlid])->all();
        expect($ulids)->toBe(['handed over' => $workspace->ulid, 'deleted' => $deleted->ulid]);
    });

    it('queues it whatever the account recorded of the subscription: the job asks Stripe which are not over', function (?string $status): void {
        $team = inventoryTeam();
        $customer = $status === null ? billingCustomerOnly($team['workspace']) : billingSubscribe($team['workspace'], $status);

        app(Members::class)->transferOwnership($team['owner'], $team['workspace'], $team['viewer']);

        expect(array_column(billingCancellations(), 0))->toBe([$customer]);
    })->with([
        'a checkout paid and not synchronised yet' => [null],
        'active' => ['active'],
        'past due' => ['past_due'],
        'paused' => ['paused'],
        'canceled' => ['canceled'],
        'incomplete expired' => ['incomplete_expired'],
    ]);

    it('has nothing to end for a workspace that never started a checkout', function (): void {
        $bare = inventoryTeam();

        app(Members::class)->transferOwnership($bare['owner'], $bare['workspace'], $bare['editor']);

        Queue::assertNotPushed(CancelStripeSubscription::class);
    });

    it('keeps the account and the workspace: only the payer stops paying', function (): void {
        $team = inventoryTeam();
        billingSubscribe($team['workspace']);

        app(Members::class)->transferOwnership($team['owner'], $team['workspace'], $team['editor']);

        expect(BillingAccount::query()->where('workspace_id', $team['workspace']->id)->value('status'))->toBe('active')
            ->and(Workspace::query()->whereKey($team['workspace']->id)->exists())->toBeTrue();
    });

    it('does nothing when the change is refused', function (): void {
        $team = inventoryTeam();
        billingSubscribe($team['workspace']);

        expect(fn () => app(Members::class)->transferOwnership($team['editor'], $team['workspace'], $team['viewer']))->toThrow(MembershipRefused::class)
            ->and(fn () => app(Members::class)->transferOwnership($team['owner'], $team['workspace'], $team['owner']))->toThrow(MembershipRefused::class);

        Queue::assertNotPushed(CancelStripeSubscription::class);
    });

    it('queues once the change is committed, not before, and not at all when it is rolled back', function (): void {
        $team = inventoryTeam();
        billingSubscribe($team['workspace']);

        try {
            DB::transaction(function () use ($team): void {
                app(Members::class)->transferOwnership($team['owner'], $team['workspace'], $team['editor']);

                Queue::assertNotPushed(CancelStripeSubscription::class);

                throw new RuntimeException('The caller changes its mind.');
            });
        } catch (RuntimeException) {
        }

        Queue::assertNotPushed(CancelStripeSubscription::class);

        DB::transaction(function () use ($team): void {
            app(Members::class)->transferOwnership($team['owner']->refresh(), $team['workspace'], $team['editor']);

            Queue::assertNotPushed(CancelStripeSubscription::class);
        });

        Queue::assertPushed(CancelStripeSubscription::class, 1);
    });

    it('is not what removing a member, changing a role or leaving does', function (): void {
        $team = inventoryTeam();
        billingSubscribe($team['workspace']);

        app(Members::class)->changeRole($team['owner'], $team['workspace'], $team['viewer'], WorkspaceRole::Editor);
        app(Members::class)->remove($team['owner'], $team['workspace'], $team['viewer']);
        app(Members::class)->leave($team['editor'], $team['workspace']);

        Queue::assertNotPushed(CancelStripeSubscription::class);
    });
});

describe('an administrator deleting the account of an owner', function (): void {
    beforeEach(fn () => Queue::fake());

    it('has the subscriptions of a workspace handed over to somebody else end with the period, and cancels those of a workspace that goes', function (): void {
        $admin = User::factory()->admin()->withPersonalWorkspace()->create();
        $team = inventoryTeam();
        $alone = app(Workspaces::class)->create($team['owner'], 'Alone');
        $handed = billingSubscribe($team['workspace']);
        $gone = billingSubscribe($alone);
        $handedAccount = BillingAccount::query()->where('workspace_id', $team['workspace']->id)->value('id');

        app(UserAdministration::class)->delete($admin, $team['owner']);

        $expected = [[$handed, true, $handedAccount], [$gone, false, null]];
        sort($expected);

        expect(billingCancellations())->toBe($expected)
            ->and(DB::table('workspace_user')->where('workspace_id', $team['workspace']->id)->where('user_id', $team['editor']->id)->value('role'))->toBe('owner')
            ->and(Workspace::query()->whereKey($alone->id)->exists())->toBeFalse();
    });

    it('does nothing for a workspace whose owner stays, or that the account does not own', function (): void {
        $admin = User::factory()->admin()->withPersonalWorkspace()->create();
        $team = inventoryTeam();
        $coOwned = app(Workspaces::class)->create($team['owner'], 'Co-owned');
        $coOwned->members()->attach($team['stranger']->id, ['role' => WorkspaceRole::Owner->value]);
        $theirs = app(Workspaces::class)->create($team['stranger'], 'Theirs');
        $theirs->members()->attach($team['owner']->id, ['role' => WorkspaceRole::Editor->value]);
        billingSubscribe($coOwned);
        billingSubscribe($theirs);

        app(UserAdministration::class)->delete($admin, $team['owner']);

        Queue::assertNotPushed(CancelStripeSubscription::class);
        expect(Workspace::query()->whereKey([$coOwned->id, $theirs->id])->count())->toBe(2);
    });

    it('queues nothing when the deletion fails after the hand-over: it goes with the transaction', function (): void {
        $admin = User::factory()->admin()->withPersonalWorkspace()->create();
        $team = inventoryTeam();
        billingSubscribe($team['workspace']);
        User::deleting(function (): void {
            throw new RuntimeException('The deletion fails once the workspaces are handed over.');
        });

        expect(fn () => app(UserAdministration::class)->delete($admin, $team['owner']))->toThrow(RuntimeException::class);

        Queue::assertNotPushed(CancelStripeSubscription::class);
        expect(DB::table('workspace_user')->where('workspace_id', $team['workspace']->id)->where('user_id', $team['owner']->id)->value('role'))->toBe('owner')
            ->and(User::query()->whereKey($team['owner']->id)->exists())->toBeTrue();
    });
});

describe('billing off', function (): void {
    beforeEach(fn () => Queue::fake());

    it('leaves alone an instance that sells nothing: there is no account to find', function (): void {
        billingOff();
        $team = inventoryTeam();

        app(Members::class)->transferOwnership($team['owner'], $team['workspace'], $team['editor']);
        $team['workspace']->delete();

        Queue::assertNotPushed(CancelStripeSubscription::class);
    });

    it('still queues the cancellation for a customer recorded before billing was turned off: the job says what it cannot do', function (): void {
        billingOff();
        $workspace = app(Workspaces::class)->create(inventoryPerson(), 'Shop');
        $customer = billingSubscribe($workspace);

        $workspace->delete();

        expect(billingCancellations())->toBe([[$customer, false, null]]);
    });
});

describe('the calls to Stripe', function (): void {
    it('cancels what was subscribed after the last synchronisation: the workspace deleted between the checkout and the sync', function (): void {
        $stripe = billingOn();
        config(['queue.default' => 'sync']);
        $workspace = app(Workspaces::class)->create(inventoryPerson(), 'Shop');
        $account = BillingAccount::factory()->for($workspace)->create();
        $id = $stripe->subscribe($account->stripe_customer_id, 'active');

        $workspace->delete();

        expect($stripe->subscriptions[$id]['status'])->toBe('canceled')->and(BillingAccount::query()->count())->toBe(0);
    });

    it('ends what was subscribed after the last synchronisation: the owner changes between the checkout and the sync', function (): void {
        $stripe = billingOn();
        config(['queue.default' => 'sync']);
        $team = inventoryTeam();
        $account = BillingAccount::factory()->for($team['workspace'])->create();
        $id = $stripe->subscribe($account->stripe_customer_id, 'active');

        app(Members::class)->transferOwnership($team['owner'], $team['workspace'], $team['editor']);

        expect($stripe->subscriptions[$id]['cancel_at_period_end'])->toBeTrue()
            ->and($stripe->subscriptions[$id]['status'])->toBe('active')
            ->and(BillingAccount::query()->where('workspace_id', $team['workspace']->id)->first()?->stripe_subscription_id)->toBe($id);
    });

    it('are made after the commit and never inside a transaction', function (): void {
        $stripe = billingOn();
        config(['queue.default' => 'sync']);
        $workspace = app(Workspaces::class)->create(inventoryPerson(), 'Shop');
        $subscription = $stripe->subscribe('cus_x');
        BillingAccount::factory()->for($workspace)->create(['stripe_customer_id' => 'cus_x', 'stripe_subscription_id' => $subscription, 'status' => 'active']);
        $levels = [];
        $stripe->onCall('DELETE /v1/subscriptions/{id}', function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });
        $base = DB::transactionLevel();

        $workspace->delete();

        expect($levels)->toBe([$base])->and($stripe->subscriptions[$subscription]['status'])->toBe('canceled');
    });

    it('are made after the commit of a change of owner too, and the account is read again after', function (): void {
        $stripe = billingOn();
        config(['queue.default' => 'sync']);
        $team = inventoryTeam();
        $subscription = $stripe->subscribe('cus_y');
        BillingAccount::factory()->for($team['workspace'])->create(['stripe_customer_id' => 'cus_y', 'stripe_subscription_id' => $subscription, 'status' => 'active']);
        $levels = [];
        $stripe->onCall('POST /v1/subscriptions/{id}', function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });
        $base = DB::transactionLevel();

        app(Members::class)->transferOwnership($team['owner'], $team['workspace'], $team['editor']);

        expect($levels)->toBe([$base])
            ->and($stripe->subscriptions[$subscription]['cancel_at_period_end'])->toBeTrue()
            ->and(BillingAccount::query()->where('workspace_id', $team['workspace']->id)->first()?->cancel_at_period_end)->toBeTrue();
    });
});
