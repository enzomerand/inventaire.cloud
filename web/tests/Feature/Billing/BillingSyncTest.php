<?php

declare(strict_types=1);

use App\Billing\BillingSync;
use App\Billing\Plan;
use App\Billing\StripeException;
use App\Billing\WorkspacePlan;
use App\Mail\BillingAlertMail;
use App\Models\BillingAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../../Support/billing.php';

/*
 * Bringing the subscription of a workspace up to date with what Stripe says of its customer: which subscription is the workspace's, what is
 * written of it and how the plan follows. Stripe is the only source; nothing here reads a webhook.
 */

uses(RefreshDatabase::class);

/**
 * Synchronises an account.
 */
function billingSync(BillingAccount $account): BillingAccount
{
    return app(BillingSync::class)->sync($account);
}

/**
 * A subscription object as the API lists it, written by hand for the shapes the fake does not make.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function billingSubscriptionObject(string $id, array $overrides = []): array
{
    return $overrides + [
        'id' => $id,
        'object' => 'subscription',
        'status' => 'active',
        'created' => 1_800_000_000,
        'cancel_at_period_end' => false,
        'items' => ['data' => [[
            'id' => 'si_x',
            'price' => ['id' => 'price_x', 'recurring' => ['interval' => 'month']],
            'current_period_end' => 1_802_592_000,
        ]]],
    ];
}

describe('which plan the subscription gives', function (): void {
    it('turns the status Stripe reports into the plan: Pro while active, trialing or past due, Free otherwise', function (string $status, Plan $plan): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, $status);

        $synced = billingSync($account);

        expect($synced->status)->toBe($status)
            ->and(WorkspacePlan::for($account->workspace))->toBe($plan);
    })->with([
        'active' => ['active', Plan::Pro],
        'trialing' => ['trialing', Plan::Pro],
        'past due' => ['past_due', Plan::Pro],
        'unpaid' => ['unpaid', Plan::Free],
        'paused' => ['paused', Plan::Free],
        'incomplete' => ['incomplete', Plan::Free],
        'canceled' => ['canceled', Plan::Free],
        'incomplete expired' => ['incomplete_expired', Plan::Free],
    ]);

    it('follows the subscription when it changes at Stripe, both ways', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');

        billingSync($account);
        expect(WorkspacePlan::for($account->workspace))->toBe(Plan::Pro);

        $stripe->setStatus(array_key_first($stripe->subscriptions), 'canceled');
        billingSync($account);
        expect(WorkspacePlan::for($account->workspace))->toBe(Plan::Free);

        $stripe->setStatus(array_key_first($stripe->subscriptions), 'active');
        billingSync($account);
        expect(WorkspacePlan::for($account->workspace))->toBe(Plan::Pro);
    });
});

describe('what is written of the subscription', function (): void {
    it('records the subscription, its price, its interval, the end of the period (in UTC) and when it was read', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $periodEnd = 1_802_592_000;
        $id = $stripe->subscribe($account->stripe_customer_id, 'active', 'year', $periodEnd);
        $this->travelTo(now()->startOfSecond());
        $before = now();

        $synced = billingSync($account);
        $stored = BillingAccount::query()->findOrFail($account->id);

        foreach ([$synced, $stored] as $row) {
            expect($row->stripe_subscription_id)->toBe($id)
                ->and($row->status)->toBe('active')
                ->and($row->price_id)->toBe('price_yearly_test')
                ->and($row->interval)->toBe('year')
                ->and($row->current_period_end?->getTimestamp())->toBe($periodEnd)
                ->and($row->current_period_end?->getTimezone()->getName())->toBe('UTC')
                ->and($row->cancel_at_period_end)->toBeFalse()
                ->and($row->synced_at?->equalTo($before))->toBeTrue();
        }

        // The column holds the same moment whatever the zone of the application.
        expect(DB::table('billing_accounts')->where('id', $account->id)->value('current_period_end'))->toStartWith(gmdate('Y-m-d H:i:s', $periodEnd));
    });

    it('records that the subscription ends with the period', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $stripe->subscribe($account->stripe_customer_id, 'active', 'month', null, true);

        expect(billingSync($account)->cancel_at_period_end)->toBeTrue();
    });

    it('takes a cancellation at a date for one that ends the subscription all the same', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $stripe->answerWith(Http::response(['data' => [billingSubscriptionObject('sub_dated', ['cancel_at' => 1_805_000_000])]]));

        $synced = billingSync($account);

        expect($synced->cancel_at_period_end)->toBeTrue()->and($synced->stripe_subscription_id)->toBe('sub_dated');
    });

    it('forgets a cancellation the customer took back', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $id = $stripe->subscribe($account->stripe_customer_id, 'active', 'month', null, true);
        billingSync($account);

        $stripe->change($id, ['cancel_at_period_end' => false]);

        expect(billingSync($account)->cancel_at_period_end)->toBeFalse();
    });

    it('follows a change of interval and of period', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active', 'month');
        $id = array_key_first($stripe->subscriptions);
        billingSync($account);

        $stripe->setInterval($id, 'year')->setPeriodEnd($id, 1_900_000_000);
        $synced = billingSync($account);

        expect($synced->interval)->toBe('year')->and($synced->price_id)->toBe('price_yearly_test')->and($synced->current_period_end?->getTimestamp())->toBe(1_900_000_000);
    });

    it('writes nothing it cannot hold: an interval that is not a month or a year, a missing period, a price that is not a text', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $stripe->answerWith(Http::response(['data' => [[
            'id' => 'sub_odd', 'status' => 'active', 'created' => 1,
            'items' => ['data' => [['price' => ['id' => 12, 'recurring' => ['interval' => 'week']]]]],
        ]]]));

        $synced = billingSync($account);

        expect($synced->stripe_subscription_id)->toBe('sub_odd')
            ->and($synced->interval)->toBeNull()
            ->and($synced->price_id)->toBeNull()
            ->and($synced->current_period_end)->toBeNull();
    });

    it('ignores what is listed without an id or a status', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $stripe->answerWith(Http::response(['data' => [
            billingSubscriptionObject('', ['created' => 1_800_000_900]),
            ['status' => 'active', 'created' => 1_800_000_800],
            billingSubscriptionObject('sub_real', ['created' => 1_800_000_100, 'status' => 'trialing']),
        ]]));

        expect(billingSync($account)->stripe_subscription_id)->toBe('sub_real');
    });
});

describe('which subscription is the workspace\'s', function (): void {
    it('prefers the newest of the subscriptions in play', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $old = $stripe->subscribe($account->stripe_customer_id, 'active');
        $new = $stripe->subscribe($account->stripe_customer_id, 'trialing');

        expect(billingSync($account)->stripe_subscription_id)->toBe($new)->and($old)->not->toBe($new);
    });

    it('prefers one that gives the Pro plan to a newer one that is in play without giving it: an abandoned checkout hides nothing', function (string $older, string $newer, string $chosen): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $first = $stripe->subscribe($account->stripe_customer_id, $older);
        $second = $stripe->subscribe($account->stripe_customer_id, $newer);

        $synced = billingSync($account);

        $status = $chosen === 'older' ? $older : $newer;

        expect($synced->stripe_subscription_id)->toBe($chosen === 'older' ? $first : $second)
            ->and($synced->status)->toBe($status)
            ->and(WorkspacePlan::for($account->workspace))->toBe(in_array($status, WorkspacePlan::PRO_STATUSES, true) ? Plan::Pro : Plan::Free);
    })->with([
        'a newer incomplete next to an older active' => ['active', 'incomplete', 'older'],
        'a newer incomplete next to an older trialing' => ['trialing', 'incomplete', 'older'],
        'a newer incomplete next to an older past due' => ['past_due', 'incomplete', 'older'],
        'a newer unpaid next to an older active' => ['active', 'unpaid', 'older'],
        'a newer paused next to an older past due' => ['past_due', 'paused', 'older'],
        'an older incomplete under a newer active' => ['incomplete', 'active', 'newer'],
        'the newer of two that give Pro' => ['active', 'trialing', 'newer'],
        'the newer of two that do not' => ['unpaid', 'incomplete', 'newer'],
    ]);

    it('prefers one in play to a newer one that is over', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $running = $stripe->subscribe($account->stripe_customer_id, 'past_due');
        $stripe->subscribe($account->stripe_customer_id, 'canceled');
        $stripe->subscribe($account->stripe_customer_id, 'incomplete_expired');

        $synced = billingSync($account);

        expect($synced->stripe_subscription_id)->toBe($running)->and($synced->status)->toBe('past_due');
    });

    it('counts every status in play: unpaid, paused and incomplete are not over', function (string $status): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $stripe->subscribe($account->stripe_customer_id, 'canceled');
        $current = $stripe->subscribe($account->stripe_customer_id, $status);
        $stripe->subscribe($account->stripe_customer_id, 'canceled');

        expect(billingSync($account)->stripe_subscription_id)->toBe($current);
    })->with(['unpaid', 'paused', 'incomplete']);

    it('remembers the newest of the subscriptions that are over when none is in play', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $stripe->subscribe($account->stripe_customer_id, 'incomplete_expired');
        $latest = $stripe->subscribe($account->stripe_customer_id, 'canceled');

        $synced = billingSync($account);

        expect($synced->stripe_subscription_id)->toBe($latest)->and($synced->status)->toBe('canceled');
    });

    it('writes a customer without any subscription as it is: nothing, read just now', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        billingSync($account);
        $stripe->subscriptions = [];

        $synced = billingSync($account);

        expect($synced->stripe_subscription_id)->toBeNull()
            ->and($synced->status)->toBeNull()
            ->and($synced->price_id)->toBeNull()
            ->and($synced->interval)->toBeNull()
            ->and($synced->current_period_end)->toBeNull()
            ->and($synced->cancel_at_period_end)->toBeFalse()
            ->and($synced->synced_at)->not->toBeNull()
            ->and(WorkspacePlan::for($account->workspace))->toBe(Plan::Free);
    });

    it('follows the newest and warns when two subscriptions are in play: a double checkout', function (): void {
        Log::spy();
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $stripe->subscribe($account->stripe_customer_id, 'active');
        $newest = $stripe->subscribe($account->stripe_customer_id, 'active', 'year');

        $synced = billingSync($account);

        expect($synced->stripe_subscription_id)->toBe($newest)->and($synced->interval)->toBe('year');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'more than one subscription') && $context['subscriptions'] === 2)->once();
    });

    it('tells the administrators of a double checkout, with the customer and the workspace, once a day for the customer', function (): void {
        Mail::fake();
        User::factory()->admin()->create(['email' => 'admin@example.test', 'locale' => 'en']);
        User::factory()->create(['email' => 'member@example.test']);
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $stripe->subscribe($account->stripe_customer_id, 'active');
        $stripe->subscribe($account->stripe_customer_id, 'trialing', 'year');

        billingSync($account);
        billingSync($account);

        Mail::assertQueued(BillingAlertMail::class, 1);
        Mail::assertQueued(BillingAlertMail::class, function (BillingAlertMail $mail) use ($account): bool {
            $html = $mail->render();

            return $mail->hasTo('admin@example.test')
                && $mail->eventType === 'double_subscription'
                && $mail->objectId === $account->stripe_customer_id
                && $mail->workspaceUlid === $account->workspace->ulid
                && str_contains($html, $account->stripe_customer_id)
                && str_contains($html, $account->workspace->ulid);
        });

        // The day after, the problem is still there and is told again.
        $this->travel(25)->hours();
        billingSync($account);

        Mail::assertQueued(BillingAlertMail::class, 2);
    });

    it('tells it for each customer, and not for one subscription in play next to some that are over', function (): void {
        Mail::fake();
        User::factory()->admin()->create();
        $stripe = billingOn();
        $double = billingAccountAt($stripe);
        $other = billingAccountAt($stripe);
        $single = billingAccountAt($stripe);
        $stripe->subscribe($double->stripe_customer_id, 'active');
        $stripe->subscribe($double->stripe_customer_id, 'active');
        $stripe->subscribe($other->stripe_customer_id, 'past_due');
        $stripe->subscribe($other->stripe_customer_id, 'unpaid');
        $stripe->subscribe($single->stripe_customer_id, 'canceled');
        $stripe->subscribe($single->stripe_customer_id, 'active');

        billingSync($double);
        billingSync($other);
        billingSync($single);

        Mail::assertQueued(BillingAlertMail::class, 2);
        Mail::assertNotQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->objectId === $single->stripe_customer_id);
    });

    it('is not stopped by a mail that cannot be queued: the subscription is written, the failure is in the log and the next read tells again', function (): void {
        Log::spy();
        User::factory()->admin()->create();
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $stripe->subscribe($account->stripe_customer_id, 'active');
        $newest = $stripe->subscribe($account->stripe_customer_id, 'active');
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('The queue is down.'));

        $synced = billingSync($account);

        expect($synced->status)->toBe('active')->and($synced->stripe_subscription_id)->toBe($newest);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'administrators could not be told') && $context['kind'] === 'double_subscription')->once();
        // Only set once the mail was queued.
        expect(Cache::has('billing-alert:double_subscription:'.$account->stripe_customer_id))->toBeFalse();

        app()->forgetInstance('mail.manager');
        Mail::clearResolvedInstance('mail.manager');
        Mail::fake();
        billingSync($account);

        Mail::assertQueued(BillingAlertMail::class, 1);
    });

    it('does not warn for one subscription in play next to some that are over', function (): void {
        Log::spy();
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $stripe->subscribe($account->stripe_customer_id, 'canceled');
        $stripe->subscribe($account->stripe_customer_id, 'canceled');
        $stripe->subscribe($account->stripe_customer_id, 'active');

        billingSync($account);

        Log::shouldNotHaveReceived('warning');
    });
});

describe('when Stripe does not give a subscription', function (): void {
    it('clears the subscription of a customer that was deleted at Stripe when the row has none in play, and says so in the log', function (string $stored): void {
        Log::spy();
        $stripe = billingOn();
        $account = billingAccountAt($stripe, null, 'month', ['status' => $stored, 'stripe_subscription_id' => 'sub_over']);
        expect(WorkspacePlan::for($account->workspace))->toBe(Plan::Free);

        $stripe->failNext('GET /v1/subscriptions', 404);
        $synced = billingSync($account);

        expect($synced->stripe_subscription_id)->toBeNull()
            ->and($synced->status)->toBeNull()
            ->and($synced->current_period_end)->toBeNull()
            ->and($synced->cancel_at_period_end)->toBeFalse()
            ->and($synced->synced_at)->not->toBeNull()
            ->and($synced->stripe_customer_id)->toBe($account->stripe_customer_id)
            ->and(WorkspacePlan::for($account->workspace))->toBe(Plan::Free);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'customer of a workspace is gone'))->once();
        Log::shouldNotHaveReceived('error');
    })->with(['canceled', 'incomplete_expired']);

    it('clears the account of a customer Stripe says is missing, whatever the status of its answer, when the row has no subscription in play', function (int $status): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, null, 'month', ['status' => 'canceled', 'stripe_subscription_id' => 'sub_over']);
        $stripe->answerWith(Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such customer', 'param' => 'customer']], $status));

        $synced = billingSync($account);

        expect($synced->status)->toBeNull()->and($synced->stripe_subscription_id)->toBeNull()->and(WorkspacePlan::for($account->workspace))->toBe(Plan::Free);
    })->with([400, 404]);

    it('clears the account of a customer that has no status at all, as a checkout that was never paid leaves it', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $stripe->loseCustomer($account->stripe_customer_id);

        $synced = billingSync($account);

        expect($synced->status)->toBeNull()->and($synced->synced_at)->not->toBeNull();
    });

    it('writes nothing for a customer Stripe does not know while the row has a subscription in play: it logs, tells the administrators and refuses', function (string $stored, int $status): void {
        Log::spy();
        Mail::fake();
        $administrator = User::factory()->admin()->create(['email' => 'admin@example.test', 'locale' => 'en']);
        $stripe = billingOn();
        $account = billingAccountAt($stripe, $stored);
        billingSync($account);
        $before = BillingAccount::query()->findOrFail($account->id);
        $ulid = $account->workspace->ulid;
        $stripe->answerWith(Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such customer', 'param' => 'customer']], $status));

        try {
            billingSync($account);
            $thrown = null;
        } catch (StripeException $exception) {
            $thrown = $exception;
        }

        $after = BillingAccount::query()->findOrFail($account->id);
        // Nothing changed, `synced_at` included: the row was not written at all.
        expect($thrown)->not->toBeNull()
            ->and($thrown->code())->toBe('resource_missing')
            ->and($thrown->retryable())->toBeFalse()
            ->and($after->getAttributes())->toEqual($before->getAttributes())
            ->and(WorkspacePlan::for($account->workspace))->toBe(in_array($stored, WorkspacePlan::PRO_STATUSES, true) ? Plan::Pro : Plan::Free);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'possible wrong-mode API key')
            && $context === ['customer' => $account->stripe_customer_id, 'workspace' => $ulid])->once();
        Log::shouldNotHaveReceived('warning');
        Mail::assertQueued(BillingAlertMail::class, 1);
        Mail::assertQueued(BillingAlertMail::class, function (BillingAlertMail $mail) use ($administrator, $account, $ulid): bool {
            $html = $mail->render();

            return $mail->hasTo($administrator->email)
                && $mail->eventType === 'customer_lost'
                && $mail->objectId === $account->stripe_customer_id
                && $mail->workspaceUlid === $ulid
                // The way out is in the mail.
                && str_contains($html, 'inventaire:billing-forget');
        });
    })->with(function (): array {
        $cases = [];

        foreach (['active', 'trialing', 'past_due', 'unpaid', 'paused', 'incomplete'] as $status) {
            $cases["{$status}, 404"] = [$status, 404];
        }

        return $cases + ['active, 400' => ['active', 400]];
    });

    it('tells the administrators once a day, however often the subscription is read', function (): void {
        Mail::fake();
        User::factory()->admin()->create();
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        billingSync($account);
        $stripe->loseCustomer($account->stripe_customer_id);

        foreach (range(1, 3) as $ignored) {
            expect(fn () => billingSync($account))->toThrow(StripeException::class);
        }

        Mail::assertQueued(BillingAlertMail::class, 1);
    });

    it('goes by the row as it is stored under the lock, not by the copy in the hands of the caller', function (): void {
        Mail::fake();
        User::factory()->admin()->create();
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $stripe->loseCustomer($account->stripe_customer_id);
        // The caller holds a copy with no subscription, but a synchronisation wrote one meanwhile.
        $stale = BillingAccount::query()->findOrFail($account->id);
        BillingAccount::query()->whereKey($account->id)->update(['status' => 'active', 'stripe_subscription_id' => 'sub_written_meanwhile']);

        expect(fn () => billingSync($stale))->toThrow(StripeException::class);

        expect(BillingAccount::query()->findOrFail($account->id)->status)->toBe('active');
    });

    it('says the subscription is cleared only when it was: a read that began later was written first', function (): void {
        Log::spy();
        $stripe = billingOn();
        $account = billingAccountAt($stripe, null, 'month', ['status' => 'canceled', 'stripe_subscription_id' => 'sub_over']);
        $stripe->loseCustomer($account->stripe_customer_id);
        $later = now()->addMinutes(5)->startOfSecond();
        $account->forceFill(['synced_at' => $later])->save();

        $returned = billingSync($account);

        expect($returned->status)->toBe('canceled')
            ->and($returned->stripe_subscription_id)->toBe('sub_over')
            ->and(BillingAccount::query()->findOrFail($account->id)->stripe_subscription_id)->toBe('sub_over');
        Log::shouldNotHaveReceived('warning');
    });

    it('writes nothing and tells nobody when the customer was replaced while Stripe was being asked: what it said is about a customer that is gone', function (bool $lost): void {
        Log::spy();
        Mail::fake();
        User::factory()->admin()->create();
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');

        if ($lost) {
            $stripe->loseCustomer($account->stripe_customer_id);
        }

        // Another request replaced the customer (see BillingCustomers); the new one holds a subscription in play, which a read of the old one must not touch.
        $replacement = ['stripe_customer_id' => 'cus_replacement', 'status' => $lost ? 'active' : null, 'stripe_subscription_id' => $lost ? 'sub_replacement' : null, 'synced_at' => null];
        $stripe->onCall('GET /v1/subscriptions', fn () => BillingAccount::query()->whereKey($account->id)->update($replacement));

        $returned = billingSync($account);

        $stored = BillingAccount::query()->findOrFail($account->id);
        expect($returned->stripe_customer_id)->toBe('cus_replacement')
            ->and($stored->only(array_keys($replacement)))->toBe($replacement);
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
        Mail::assertNothingQueued();
    })->with(['the subscriptions are read' => false, 'the customer is not known' => true]);

    it('does not take a 404 that is not the answer of Stripe for a deleted customer: nothing turns the workspace Free', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        billingSync($account);
        // A proxy, a wrong address, a page of an outage: a 404 that does not say `resource_missing`.
        $stripe->answerWith(Http::response('<html>Not Found</html>', 404));

        try {
            billingSync($account);
            $thrown = null;
        } catch (StripeException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown->status())->toBe(404)
            ->and($thrown->retryable())->toBeFalse()
            ->and(BillingAccount::query()->findOrFail($account->id)->status)->toBe('active')
            ->and(WorkspacePlan::for($account->workspace))->toBe(Plan::Pro);
    });

    it('throws what is worth trying again and leaves the account as it was', function (?int $status): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $before = billingSync($account);
        $stripe->setStatus(array_key_first($stripe->subscriptions), 'canceled')->failNext('GET /v1/subscriptions', $status);

        try {
            billingSync($account);
            $thrown = null;
        } catch (StripeException $exception) {
            $thrown = $exception;
        }

        expect($thrown?->retryable())->toBeTrue()
            ->and(BillingAccount::query()->findOrFail($account->id)->status)->toBe('active')
            ->and(BillingAccount::query()->findOrFail($account->id)->synced_at?->equalTo($before->synced_at))->toBeTrue();
    })->with(['an error of Stripe' => [500], 'too many calls' => [429], 'a lost connection' => [null]]);

    it('throws a refusal, which is not worth trying again, and keeps the account', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        billingSync($account);
        $stripe->failNext('GET /v1/subscriptions', 403);

        try {
            billingSync($account);
            $thrown = null;
        } catch (StripeException $exception) {
            $thrown = $exception;
        }

        expect($thrown?->retryable())->toBeFalse()->and(BillingAccount::query()->findOrFail($account->id)->status)->toBe('active');
    });
});

describe('how it is written', function (): void {
    it('asks Stripe for the customer, all the statuses, outside any transaction', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $levels = [];
        $stripe->onCall('GET /v1/subscriptions', function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });
        $base = DB::transactionLevel();

        billingSync($account);

        $query = [];
        parse_str((string) parse_url($stripe->requestsOf('GET /v1/subscriptions')[0]->url(), PHP_URL_QUERY), $query);
        expect($levels)->toBe([$base])
            ->and($query['customer'])->toBe($account->stripe_customer_id)
            ->and($query['status'])->toBe('all');
    });

    it('locks the workspace before it writes', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        billingSync($account);

        $lock = array_keys(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'workspaces') && str_contains($sql, 'for update')));
        $write = array_keys(array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'update') && str_contains($sql, 'billing_accounts')));

        expect($lock)->not->toBe([])->and($write)->not->toBe([])->and($lock[0])->toBeLessThan($write[0]);
    });

    it('does not let a read that began before another one overwrite it', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'canceled');
        // A read that began later was written first: it saw the customer subscribe again.
        $later = now()->addMinutes(5)->startOfSecond();
        $account->forceFill(['stripe_subscription_id' => 'sub_newer', 'status' => 'active', 'synced_at' => $later])->save();

        $returned = billingSync($account);
        $stored = BillingAccount::query()->findOrFail($account->id);

        expect($stored->status)->toBe('active')
            ->and($stored->stripe_subscription_id)->toBe('sub_newer')
            ->and($stored->synced_at?->equalTo($later))->toBeTrue()
            ->and($returned->status)->toBe('active');
    });

    it('orders two reads that began in the same second by their microseconds', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $id = $stripe->subscribe($account->stripe_customer_id, 'active');
        $second = CarbonImmutable::parse('2026-07-01 10:00:00', 'UTC');

        // A read begins 0.7 s into the second and sees the subscription active.
        $this->travelTo($second->addMicroseconds(700_000));
        billingSync($account);
        $stripe->setStatus($id, 'canceled');

        // A read that began earlier in the same second (0.2 s) is written after it: it saw less, and must not overwrite it.
        $this->travelTo($second->addMicroseconds(200_000));
        $returned = billingSync($account);

        $stored = BillingAccount::query()->findOrFail($account->id);
        expect($stored->status)->toBe('active')
            ->and($returned->status)->toBe('active')
            ->and($stored->synced_at?->format('Y-m-d H:i:s.u'))->toBe('2026-07-01 10:00:00.700000');

        // One that began later (0.9 s) is written over it.
        $this->travelTo($second->addMicroseconds(900_000));
        billingSync($account);

        expect(BillingAccount::query()->findOrFail($account->id)->status)->toBe('canceled')
            ->and(BillingAccount::query()->findOrFail($account->id)->synced_at?->format('Y-m-d H:i:s.u'))->toBe('2026-07-01 10:00:00.900000');
    });

    it('keeps the microseconds of the read in the column, in UTC', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $this->travelTo(CarbonImmutable::parse('2026-07-01 10:00:00.123456', 'UTC'));

        billingSync($account);

        expect(storedDate('billing_accounts', 'synced_at'))->toStartWith('2026-07-01 10:00:00.123456');
    });

    it('writes over a read made a moment ago', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'canceled');
        $account->forceFill(['status' => 'active', 'stripe_subscription_id' => 'sub_old', 'synced_at' => now()->subMinutes(5)])->save();

        expect(billingSync($account)->status)->toBe('canceled');
    });

    it('does nothing when the workspace is deleted while Stripe is being asked, and does not fail', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $stripe->onCall('GET /v1/subscriptions', fn () => $account->workspace->delete());

        $returned = billingSync($account);

        expect(BillingAccount::query()->count())->toBe(0)->and($returned->stripe_customer_id)->toBe($account->stripe_customer_id);
    });

    it('does nothing when the account is deleted while Stripe is being asked', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $stripe->onCall('GET /v1/subscriptions', fn () => DB::table('billing_accounts')->delete());

        billingSync($account);

        expect(BillingAccount::query()->count())->toBe(0);
    });

    it('only touches the account it is given', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $other = billingAccountAt($stripe, 'canceled');

        billingSync($account);

        expect(BillingAccount::query()->findOrFail($other->id)->synced_at)->toBeNull()
            ->and(BillingAccount::query()->findOrFail($other->id)->status)->toBeNull();
    });
});

describe('the start of the subscription', function (): void {
    it('is the start date Stripe reports for the subscription the workspace has', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $stripe->change(array_key_first($stripe->subscriptions), ['start_date' => 1_800_000_000]);

        $synced = billingSync($account);

        expect($synced->started_at?->getTimestamp())->toBe(1_800_000_000)
            ->and($account->fresh()->started_at?->toIso8601String())->toBe('2027-01-15T08:00:00+00:00');
    });

    it('starts again with a new subscription, which has a start date of its own', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $first = array_key_first($stripe->subscriptions);
        $stripe->change($first, ['start_date' => 1_800_000_000]);
        billingSync($account);

        $stripe->setStatus($first, 'canceled');
        $second = $stripe->subscribe($account->stripe_customer_id, 'active');
        $stripe->change($second, ['start_date' => 1_810_000_000]);
        $synced = billingSync($account);

        expect($synced->stripe_subscription_id)->toBe($second)->and($synced->started_at?->getTimestamp())->toBe(1_810_000_000);
    });

    it('is unknown when Stripe gives none, and when the customer has no subscription any more', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $stripe->change(array_key_first($stripe->subscriptions), ['start_date' => null]);

        expect(billingSync($account)->started_at)->toBeNull();

        $stripe->subscriptions = [];
        $account->forceFill(['started_at' => now()])->save();

        expect(billingSync($account)->started_at)->toBeNull();
    });

    it('forgets the receipt of a cancellation once the subscription stops ending, and keeps the mails already sent otherwise', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $id = array_key_first($stripe->subscriptions);
        $account->forceFill(['confirmation_sent_for' => $id, 'cancel_notice_sent_for' => $id, 'renewal_notice_sent_for' => now()->addDays(60)])->save();
        $stripe->change($id, ['cancel_at_period_end' => true]);

        $ending = billingSync($account);

        expect($ending->cancel_notice_sent_for)->toBe($id)->and($ending->confirmation_sent_for)->toBe($id)->and($ending->renewal_notice_sent_for)->not->toBeNull();

        $stripe->change($id, ['cancel_at_period_end' => false]);
        $kept = billingSync($account);

        expect($kept->cancel_notice_sent_for)->toBeNull()->and($kept->confirmation_sent_for)->toBe($id)->and($kept->renewal_notice_sent_for)->not->toBeNull();
    });
});
