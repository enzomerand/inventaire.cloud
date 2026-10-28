<?php

declare(strict_types=1);

use App\Console\Commands\SyncBillingCommand;
use App\Mail\BillingAlertMail;
use App\Models\BillingAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
 * An account synchronised a number of hours (and five minutes) ago (never when null). A synchronisation writes the row, so `updated_at` is
 * the same moment: it is what the command orders the accounts by, and the row holds the status the synchronisation read, which decides
 * how soon the account is due again (a subscription in play: six hours; none, or one that is over: a day).
 *
 * @param  string|null  $status  The status of the subscription Stripe has for the customer; none when null.
 * @param  string|null  $stored  The status the row remembers of it, when it was synchronised; as Stripe has it when not given.
 */
function billingAccountSyncedAgo(FakeStripe $stripe, ?int $hours, ?string $status = 'active', ?string $stored = null, int $minutes = 5): BillingAccount
{
    $account = billingAccountAt($stripe, $status);

    if ($hours !== null) {
        $moment = now()->subHours($hours)->subMinutes($minutes);
        BillingAccount::query()->whereKey($account->id)->update(['synced_at' => $moment, 'updated_at' => $moment, 'status' => $stored ?? $status]);
    }

    return $account->refresh();
}

describe('which accounts it reads', function (): void {
    it('reads those never read and, when their subscription is in play, those read more than six hours ago, and leaves the others', function (): void {
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
            ->and(BillingAccount::query()->findOrFail($fresh->id)->synced_at?->equalTo($freshSyncedAt))->toBeTrue();
    });

    it('does not read an account whose subscription is in play that was read just under six hours ago', function (): void {
        $stripe = billingOn();
        billingAccountSyncedAgo($stripe, 5, 'active', null, 55);

        $this->artisan('inventaire:sync-billing')->assertSuccessful();

        expect($stripe->calls)->toBe([]);
    });

    it('reads every status in play after six hours: they bill, or may bill again', function (string $status): void {
        $stripe = billingOn();
        $account = billingAccountSyncedAgo($stripe, 6, $status);

        $this->artisan('inventaire:sync-billing')->assertSuccessful();

        expect(billingAsked($stripe))->toBe([$account->stripe_customer_id]);
    })->with(['active', 'trialing', 'past_due', 'unpaid', 'paused', 'incomplete']);

    it('waits a day for an account with no subscription, or with one that is over: nothing there changes by itself', function (?string $stored): void {
        $stripe = billingOn();
        // 7 hours: due for an account whose subscription is in play, not for these. 23 hours 55: not yet. 24 hours 5: due.
        billingAccountSyncedAgo($stripe, 7, null, $stored);
        billingAccountSyncedAgo($stripe, 23, null, $stored, 55);
        $due = billingAccountSyncedAgo($stripe, 24, null, $stored);
        $never = billingAccountSyncedAgo($stripe, null, null);

        $this->artisan('inventaire:sync-billing')->assertSuccessful();

        expect(billingAsked($stripe))->toBe([$never->stripe_customer_id, $due->stripe_customer_id]);
    })->with([
        'no subscription at all' => [null],
        'a subscription that was canceled' => ['canceled'],
        'a subscription that never completed' => ['incomplete_expired'],
    ]);

    it('reads an account again after six hours when its status changed to one in play, and after a day when it left it', function (): void {
        $stripe = billingOn();
        // The row says the subscription is over, but it was read 7 hours ago: it waits. The one that is in play is due.
        $over = billingAccountSyncedAgo($stripe, 7, 'active', 'canceled');
        $live = billingAccountSyncedAgo($stripe, 7, 'canceled', 'past_due');

        $this->artisan('inventaire:sync-billing')->assertSuccessful();

        expect(billingAsked($stripe))->toBe([$live->stripe_customer_id])
            ->and($over->fresh()->status)->toBe('canceled');
    });

    it('reads the accounts never read first, then the others from the longest ago, whatever the order they were made in', function (): void {
        $stripe = billingOn();
        $recent = billingAccountSyncedAgo($stripe, 8);
        $oldest = billingAccountSyncedAgo($stripe, 40, null);
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
            // 150 never read, then 100 read 7 to 106 hours ago: the stale ones from the longest ago go first.
            $syncedAt = $index < 150 ? null : now()->subHours(7 + ($index - 150))->toDateTimeString();
            $rows[] = [
                'workspace_id' => $workspace->id,
                'stripe_customer_id' => $customer,
                'status' => $syncedAt === null ? null : 'active',
                'synced_at' => $syncedAt,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => $syncedAt ?? now()->toDateTimeString(),
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

    it('goes on past an account whose customer Stripe does not know while it has a subscription in play: it is left as it is, counted as failed, and the administrators are told', function (): void {
        Mail::fake();
        User::factory()->admin()->create();
        $stripe = billingOn();
        $lost = billingAccountSyncedAgo($stripe, 7, 'active');
        $other = billingAccountSyncedAgo($stripe, 8, 'active');
        $stripe->loseCustomer($lost->stripe_customer_id);

        $this->artisan('inventaire:sync-billing')->expectsOutputToContain('1 account(s) synchronised, 1 failed.')->assertSuccessful();

        expect(BillingAccount::query()->findOrFail($lost->id)->status)->toBe('active')
            ->and(BillingAccount::query()->findOrFail($lost->id)->synced_at?->lessThan(now()->subHours(6)))->toBeTrue()
            ->and(BillingAccount::query()->findOrFail($other->id)->synced_at?->greaterThan(now()->subMinute()))->toBeTrue();
        Mail::assertQueued(BillingAlertMail::class, 1);
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->eventType === 'customer_lost' && $mail->objectId === $lost->stripe_customer_id);
    });

    it('marks an account that fails as looked at, so that it goes behind the others the next time, whatever its place in the table', function (): void {
        $stripe = billingOn();
        $first = billingAccountAt($stripe, 'active');
        $second = billingAccountAt($stripe, 'active');
        BillingAccount::query()->whereKey($first->id)->update(['created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3)]);
        BillingAccount::query()->whereKey($second->id)->update(['created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)]);
        $stripe->failNext('GET /v1/subscriptions', 400);

        $this->artisan('inventaire:sync-billing')->expectsOutputToContain('1 account(s) synchronised, 1 failed.')->assertSuccessful();

        expect(BillingAccount::query()->findOrFail($first->id)->updated_at?->greaterThan(now()->subMinute()))->toBeTrue()
            ->and(BillingAccount::query()->findOrFail($first->id)->synced_at)->toBeNull();

        // The second is due again; the first, which fails every time, no longer comes before it.
        BillingAccount::query()->whereKey($second->id)->update(['synced_at' => null, 'updated_at' => now()->subHour()]);
        $stripe->calls = [];
        $stripe->requests = [];

        $this->artisan('inventaire:sync-billing')->assertSuccessful();

        expect(billingAsked($stripe))->toBe([$second->stripe_customer_id, $first->stripe_customer_id]);
    });

    it('gives up after three failures in a row of the kind worth trying again, and leaves the rest to the next run', function (): void {
        Log::spy();
        $stripe = billingOn();
        $accounts = [billingAccountAt($stripe, 'active'), billingAccountAt($stripe, 'active'), billingAccountAt($stripe, 'active'), billingAccountAt($stripe, 'active'), billingAccountAt($stripe, 'active')];
        // Made an hour ago: the three that fail are marked as looked at now, behind the two that were not tried.
        BillingAccount::query()->update(['updated_at' => now()->subHour()]);
        $stripe->failNext('GET /v1/subscriptions', 503, 3);

        $this->artisan('inventaire:sync-billing')
            ->expectsOutputToContain('Stopped early (Stripe fails to answer)')
            ->expectsOutputToContain('0 account(s) synchronised, 3 failed.')
            ->assertSuccessful();

        expect($stripe->count('GET /v1/subscriptions'))->toBe(3)
            ->and(BillingAccount::query()->findOrFail($accounts[3]->id)->synced_at)->toBeNull();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'stopped early') && $context['reason'] === 'Stripe fails to answer')->once();

        // The next run, with Stripe back, reads them all: the two that were not tried first, as they come before those that failed.
        $this->artisan('inventaire:sync-billing')->expectsOutputToContain('5 account(s) synchronised, 0 failed.')->assertSuccessful();

        expect(array_slice(billingAsked($stripe), 3, 2))->toBe([$accounts[3]->stripe_customer_id, $accounts[4]->stripe_customer_id]);
    });

    it('does not give up on failures that are not in a row, or that are not worth trying again', function (): void {
        $stripe = billingOn();

        foreach (range(1, 6) as $ignored) {
            billingAccountAt($stripe, 'active');
        }

        // Two failures, a success, two failures, a success: four failures, never three in a row.
        $calls = 0;
        $stripe->failNext('GET /v1/subscriptions', 500, 2);
        $stripe->onCall('GET /v1/subscriptions', function () use (&$calls, $stripe): void {
            if (++$calls === 4 || $calls === 5) {
                $stripe->failNext('GET /v1/subscriptions', 500);
            }
        });

        $this->artisan('inventaire:sync-billing')->expectsOutputToContain('2 account(s) synchronised, 4 failed.')->assertSuccessful();

        expect($stripe->count('GET /v1/subscriptions'))->toBe(6);
    });

    it('does not take a refusal for an outage: it is the account that is wrong, not Stripe', function (): void {
        $stripe = billingOn();

        foreach (range(1, 5) as $ignored) {
            billingAccountAt($stripe, 'active');
        }

        $stripe->failNext('GET /v1/subscriptions', 400, 4);

        $this->artisan('inventaire:sync-billing')->expectsOutputToContain('1 account(s) synchronised, 4 failed.')->assertSuccessful();

        expect($stripe->count('GET /v1/subscriptions'))->toBe(5);
    });

    it('starts no new account once its time budget of 45 seconds is used up', function (): void {
        Log::spy();
        $stripe = billingOn();

        foreach (range(1, 4) as $ignored) {
            billingAccountAt($stripe, 'active');
        }

        $first = true;
        $stripe->onCall('GET /v1/subscriptions', function () use (&$first): void {
            if ($first) {
                $first = false;
                // The first call is slow.
                Carbon::setTestNow(now()->addSeconds(SyncBillingCommand::TIME_BUDGET + 5));
            }
        });

        $this->artisan('inventaire:sync-billing')
            ->expectsOutputToContain('Stopped early (the time budget is used up)')
            ->expectsOutputToContain('1 account(s) synchronised, 0 failed.')
            ->assertSuccessful();

        expect($stripe->count('GET /v1/subscriptions'))->toBe(1);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['reason'] === 'the time budget is used up' && $context['synchronised'] === 1)->once();
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
    it('runs it every hour, one run at a time, in the background, and forgets the lock of a run that was killed before the next hour', function (): void {
        $events = collect(app(Schedule::class)->events())->filter(fn (Event $event): bool => str_contains((string) $event->command, 'inventaire:sync-billing'));

        expect($events)->toHaveCount(1);

        $event = $events->first();

        expect($event->expression)->toBe('0 * * * *')
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBeLessThan(60)
            ->and($event->runInBackground)->toBeTrue()
            ->and(SyncBillingCommand::PER_RUN)->toBe(200)
            ->and(SyncBillingCommand::STALE_AFTER_HOURS)->toBe(6);
    });
});
