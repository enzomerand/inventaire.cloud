<?php

declare(strict_types=1);

use App\Console\Commands\SyncBillingCommand;
use App\Models\BillingAccount;
use App\Models\Workspace;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeStripe;

require_once __DIR__.'/../../Support/billing.php';

/*
 * `inventaire:sync-billing`, the safety net of the webhook: it reads again the subscriptions nobody looked at for a while, the oldest
 * first, a bounded number per run, and an instance that sells nothing has nothing to do.
 */

uses(RefreshDatabase::class);

/**
 * The customers Stripe was asked about, in order.
 *
 * @return list<string>
 */
function billingAsked(FakeStripe $stripe): array
{
    return array_map(function ($request): string {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return (string) $query['customer'];
    }, $stripe->requestsOf('GET /v1/subscriptions'));
}

/**
 * An account synchronised a number of hours ago (never when null).
 */
function billingAccountSyncedAgo(FakeStripe $stripe, ?int $hours, ?string $status = 'active'): BillingAccount
{
    $account = billingAccountAt($stripe, $status);

    if ($hours !== null) {
        $account->forceFill(['synced_at' => now()->subHours($hours)->subMinutes(5)])->save();
    }

    return $account;
}

describe('which accounts it reads', function (): void {
    it('reads those never read and those read more than six hours ago, and leaves the others', function (): void {
        $stripe = billingOn();
        $never = billingAccountSyncedAgo($stripe, null);
        $stale = billingAccountSyncedAgo($stripe, 7);
        $old = billingAccountSyncedAgo($stripe, 30);
        $fresh = billingAccountSyncedAgo($stripe, 2);
        $freshSyncedAt = $fresh->fresh()->synced_at;

        $this->artisan('inventaire:sync-billing')->expectsOutputToContain('3 account(s) synchronised, 0 failed.')->assertSuccessful();

        expect(billingAsked($stripe))->toEqualCanonicalizing([$never->stripe_customer_id, $stale->stripe_customer_id, $old->stripe_customer_id])
            ->and(BillingAccount::query()->findOrFail($never->id)->status)->toBe('active')
            ->and(BillingAccount::query()->findOrFail($stale->id)->synced_at?->greaterThan(now()->subMinute()))->toBeTrue()
            ->and(BillingAccount::query()->findOrFail($fresh->id)->synced_at?->equalTo($freshSyncedAt))->toBeTrue()
            ->and(BillingAccount::query()->findOrFail($fresh->id)->status)->toBeNull();
    });

    it('does not read an account that was read just under six hours ago', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $account->forceFill(['synced_at' => now()->subHours(6)->addMinutes(5)])->save();

        $this->artisan('inventaire:sync-billing')->assertSuccessful();

        expect($stripe->calls)->toBe([]);
    });

    it('reads the accounts never read first, then the others from the longest ago, whatever the order they were made in', function (): void {
        $stripe = billingOn();
        $recent = billingAccountSyncedAgo($stripe, 8);
        $oldest = billingAccountSyncedAgo($stripe, 40);
        $never = billingAccountSyncedAgo($stripe, null);
        $middle = billingAccountSyncedAgo($stripe, 20);

        $this->artisan('inventaire:sync-billing')->assertSuccessful();

        expect(billingAsked($stripe))->toBe([$never->stripe_customer_id, $oldest->stripe_customer_id, $middle->stripe_customer_id, $recent->stripe_customer_id]);
    });

    it('reads at most 200 a run, those never read before the stale ones, and the rest the next time', function (): void {
        $stripe = billingOn();
        $workspaces = Workspace::factory()->count(250)->create();
        $rows = [];

        foreach ($workspaces as $index => $workspace) {
            $customer = 'cus_bulk'.$index;
            $stripe->addCustomer($customer);
            $rows[] = [
                'workspace_id' => $workspace->id,
                'stripe_customer_id' => $customer,
                // 150 never read, then 100 read 7 to 106 hours ago: the stale ones from the longest ago go first.
                'synced_at' => $index < 150 ? null : now()->subHours(7 + ($index - 150))->toDateTimeString(),
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ];
        }

        BillingAccount::query()->insert($rows);

        $this->artisan('inventaire:sync-billing')->expectsOutputToContain('200 account(s) synchronised, 0 failed.')->assertSuccessful();

        $asked = billingAsked($stripe);
        // The 50 stale accounts of 56 to 106 hours ago have been read, the 50 read 7 to 56 hours ago wait.
        $expected = array_merge(array_map(fn (int $i): string => 'cus_bulk'.$i, range(0, 149)), array_map(fn (int $i): string => 'cus_bulk'.$i, range(249, 200)));

        expect($asked)->toHaveCount(200)->and($asked)->toBe($expected);

        $this->artisan('inventaire:sync-billing')->expectsOutputToContain('50 account(s) synchronised, 0 failed.')->assertSuccessful();

        expect($stripe->count('GET /v1/subscriptions'))->toBe(250);
    });
});

describe('what it does with them', function (): void {
    it('synchronises each, inline: the plan follows Stripe', function (): void {
        $stripe = billingOn();
        $pro = billingAccountAt($stripe, 'active');
        $free = billingAccountAt($stripe, 'canceled');
        $none = billingAccountAt($stripe);

        $this->artisan('inventaire:sync-billing')->assertSuccessful();

        expect(BillingAccount::query()->findOrFail($pro->id)->status)->toBe('active')
            ->and(BillingAccount::query()->findOrFail($free->id)->status)->toBe('canceled')
            ->and(BillingAccount::query()->findOrFail($none->id)->status)->toBeNull()
            ->and(BillingAccount::query()->findOrFail($none->id)->synced_at)->not->toBeNull();
    });

    it('goes on after an account that fails, and writes the failure in the log', function (): void {
        Log::spy();
        $stripe = billingOn();
        $first = billingAccountAt($stripe, 'active');
        $second = billingAccountAt($stripe, 'active');
        $third = billingAccountAt($stripe, 'active');
        $stripe->failNext('GET /v1/subscriptions', 500);

        $this->artisan('inventaire:sync-billing')->expectsOutputToContain('2 account(s) synchronised, 1 failed.')->assertSuccessful();

        expect(BillingAccount::query()->findOrFail($first->id)->status)->toBeNull()
            ->and(BillingAccount::query()->findOrFail($second->id)->status)->toBe('active')
            ->and(BillingAccount::query()->findOrFail($third->id)->status)->toBe('active');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['billing_account'] === $first->id && $context['status'] === 500)->once();
    });

    it('goes on after a failure that is not Stripe\'s', function (): void {
        $stripe = billingOn();
        $first = billingAccountAt($stripe, 'active');
        $second = billingAccountAt($stripe, 'active');
        // A connection that gives something the client cannot make sense of.
        $stripe->onCall('GET /v1/subscriptions', function () use ($stripe): void {
            $stripe->onCall('GET /v1/subscriptions', fn () => null);

            throw new RuntimeException('Something broke.');
        });

        $this->artisan('inventaire:sync-billing')->expectsOutputToContain('1 account(s) synchronised, 1 failed.')->assertSuccessful();

        expect(BillingAccount::query()->findOrFail($second->id)->status)->toBe('active')->and(BillingAccount::query()->findOrFail($first->id)->status)->toBeNull();
    });

    it('retries nothing: a failed account is read again by the next run', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $stripe->failNext('GET /v1/subscriptions', 503);

        $this->artisan('inventaire:sync-billing')->assertSuccessful();
        expect($stripe->count('GET /v1/subscriptions'))->toBe(1)->and(BillingAccount::query()->findOrFail($account->id)->synced_at)->toBeNull();

        $this->artisan('inventaire:sync-billing')->assertSuccessful();
        expect($stripe->count('GET /v1/subscriptions'))->toBe(2)->and(BillingAccount::query()->findOrFail($account->id)->status)->toBe('active');
    });

    it('fails, and stops, when Stripe refuses the key: the same answer would come for every account', function (): void {
        Log::spy();
        $stripe = billingOn();
        billingAccountAt($stripe, 'active');
        billingAccountAt($stripe, 'active');
        config(['services.stripe.secret' => 'sk_test_another_key']);

        $this->artisan('inventaire:sync-billing')->expectsOutputToContain('refused the secret key')->assertFailed();

        expect($stripe->count('GET /v1/subscriptions'))->toBe(1);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'secret key') && $context['status'] === 401 && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'sk_test'))->once();
    });
});

describe('on an instance that sells no plan', function (): void {
    it('says so and does nothing, successfully, whatever the accounts', function (): void {
        $stripe = billingOn();
        billingAccountAt($stripe, 'active');
        billingOff();
        Http::preventStrayRequests();

        $this->artisan('inventaire:sync-billing')->expectsOutputToContain('Billing is not enabled on this instance')->assertSuccessful();

        expect($stripe->calls)->toBe([]);
    });

    it('does not need a signing secret: the instances without a webhook are the ones that rely on it', function (): void {
        $stripe = billingOn(['services.stripe.webhook_secret' => null]);
        $account = billingAccountAt($stripe, 'active');

        $this->artisan('inventaire:sync-billing')->assertSuccessful();

        expect(BillingAccount::query()->findOrFail($account->id)->status)->toBe('active');
    });
});

describe('the schedule', function (): void {
    it('runs it every hour, one run at a time, and forgets the lock of a run that was killed before the next hour', function (): void {
        $events = collect(app(Schedule::class)->events())->filter(fn (Event $event): bool => str_contains((string) $event->command, 'inventaire:sync-billing'));

        expect($events)->toHaveCount(1);

        $event = $events->first();

        expect($event->expression)->toBe('0 * * * *')
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBeLessThan(60)
            ->and(SyncBillingCommand::PER_RUN)->toBe(200)
            ->and(SyncBillingCommand::STALE_AFTER_HOURS)->toBe(6);
    });
});
