<?php

declare(strict_types=1);

use App\Billing\StripeClient;
use App\Billing\StripeException;
use App\Jobs\CancelStripeSubscription;
use App\Jobs\SyncBillingAccount;
use App\Models\BillingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeStripe;

require_once __DIR__.'/../../Support/billing.php';

/*
 * The job that ends a subscription at Stripe, once the workspace is deleted (now) or has a new owner (with the period): what it asks of
 * Stripe, what it takes for done, what it tries again and what it leaves to a person.
 */

uses(RefreshDatabase::class);

/**
 * Runs a job the way a worker does.
 */
function billingCancel(CancelStripeSubscription $job): void
{
    $job->handle(app(StripeClient::class));
}

/**
 * Has a worker take the next job that is due and give it one try.
 */
function billingCancelWorkOnce(object $test): void
{
    // The memory of the worker is the one of the whole test run: the default limit of the command (128 MB) would stop it.
    $test->artisan('queue:work', ['--once' => true, '--memory' => 8192])->assertSuccessful();
}

describe('cancelling now', function (): void {
    it('cancels the subscription at Stripe', function (): void {
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a', 'active');

        billingCancel(new CancelStripeSubscription($id));

        expect($stripe->subscriptions[$id]['status'])->toBe('canceled')
            ->and($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(1)
            ->and($stripe->count('POST /v1/subscriptions/{id}'))->toBe(0);
    });

    it('takes a subscription that Stripe does not have any more for cancelled', function (): void {
        Log::spy();
        $stripe = billingOn();

        billingCancel(new CancelStripeSubscription('sub_unknown'));

        expect($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(1);
        Log::shouldNotHaveReceived('error');
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'not at Stripe any more') && $context['subscription'] === 'sub_unknown')->once();
    });

    it('takes one that is already cancelled for cancelled', function (): void {
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a', 'canceled');

        billingCancel(new CancelStripeSubscription($id));

        expect($stripe->subscriptions[$id]['status'])->toBe('canceled');
    });

    it('names the subscription in the path and nothing in a form', function (): void {
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');

        billingCancel(new CancelStripeSubscription($id));

        $request = $stripe->requestsOf('DELETE /v1/subscriptions/{id}')[0];

        expect($request->url())->toEndWith('/v1/subscriptions/'.$id)->and($request->method())->toBe('DELETE');
    });
});

describe('cancelling with the period', function (): void {
    it('has the subscription end at the end of the period that is paid for, and keeps it running until then', function (): void {
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a', 'active');

        billingCancel(new CancelStripeSubscription($id, true));

        expect($stripe->subscriptions[$id]['cancel_at_period_end'])->toBeTrue()
            ->and($stripe->subscriptions[$id]['status'])->toBe('active')
            ->and($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(0)
            ->and($stripe->count('POST /v1/subscriptions/{id}'))->toBe(1);
    });

    it('takes a subscription that Stripe does not have any more for done', function (): void {
        $stripe = billingOn();

        billingCancel(new CancelStripeSubscription('sub_unknown', true));

        expect($stripe->count('POST /v1/subscriptions/{id}'))->toBe(1);
    });

    it('keeps one idempotency key for all its tries, and another for another job', function (): void {
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');
        $stripe->failNext('POST /v1/subscriptions/{id}', 500);
        $job = new CancelStripeSubscription($id, true);

        expect(fn () => billingCancel($job))->toThrow(StripeException::class);
        billingCancel(unserialize(serialize($job)));
        billingCancel(new CancelStripeSubscription($id, true));

        $keys = array_map(fn ($request): string => $request->header('Idempotency-Key')[0], $stripe->requestsOf('POST /v1/subscriptions/{id}'));

        expect($keys)->toHaveCount(3)->and($keys[0])->toBe($keys[1])->and($keys[2])->not->toBe($keys[0]);
    });
});

describe('reading the account again', function (): void {
    it('queues the synchronisation of the account of a workspace that is still there, once the subscription is ended', function (bool $atPeriodEnd): void {
        Queue::fake();
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');

        billingCancel(new CancelStripeSubscription($id, $atPeriodEnd, 77));

        Queue::assertPushed(SyncBillingAccount::class, fn (SyncBillingAccount $job): bool => $job->billingAccountId === 77);
    })->with([true, false]);

    it('queues it for a subscription Stripe does not have any more as well', function (): void {
        Queue::fake();
        billingOn();

        billingCancel(new CancelStripeSubscription('sub_unknown', true, 77));

        Queue::assertPushed(SyncBillingAccount::class, 1);
    });

    it('queues nothing for a workspace that is gone', function (): void {
        Queue::fake();
        $stripe = billingOn();

        billingCancel(new CancelStripeSubscription($stripe->subscribe('cus_a')));

        Queue::assertNothingPushed();
    });

    it('does not queue it when the subscription could not be ended', function (): void {
        Queue::fake();
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');
        $stripe->failNext('POST /v1/subscriptions/{id}', 400);

        billingCancel(new CancelStripeSubscription($id, true, 77));

        Queue::assertNothingPushed();
    });

    it('brings the page up to date: the account says the subscription ends with the period', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $id = array_key_first($stripe->subscriptions);
        config(['queue.default' => 'sync']);

        billingCancel(new CancelStripeSubscription($id, true, $account->id));

        expect(BillingAccount::query()->findOrFail($account->id)->cancel_at_period_end)->toBeTrue();
    });
});

describe('what goes wrong', function (): void {
    it('throws what is worth another try, for the queue to try again', function (bool $atPeriodEnd, ?int $status): void {
        Queue::fake();
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');
        $stripe->failNext($atPeriodEnd ? 'POST /v1/subscriptions/{id}' : 'DELETE /v1/subscriptions/{id}', $status);

        expect(fn () => billingCancel(new CancelStripeSubscription($id, $atPeriodEnd, 77)))->toThrow(StripeException::class);
        Queue::assertNothingPushed();
    })->with([
        'now, an error of Stripe' => [false, 500],
        'now, too many calls' => [false, 429],
        'now, a lost connection' => [false, null],
        'with the period, an error of Stripe' => [true, 503],
        'with the period, a lost connection' => [true, null],
    ]);

    it('leaves a refusal to a person: it is written in the log, not tried again', function (bool $atPeriodEnd, int $status): void {
        Log::spy();
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');
        $stripe->failNext($atPeriodEnd ? 'POST /v1/subscriptions/{id}' : 'DELETE /v1/subscriptions/{id}', $status);

        billingCancel(new CancelStripeSubscription($id, $atPeriodEnd));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'dashboard of Stripe')
            && $context['subscription'] === $id
            && $context['status'] === $status
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), FakeStripe::SECRET))->once();
    })->with([[false, 400], [false, 401], [false, 403], [true, 400], [true, 403]]);

    it('can do nothing without a Stripe, and says so in the log', function (): void {
        Log::spy();
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');
        billingOff();

        billingCancel(new CancelStripeSubscription($id));

        expect($stripe->calls)->toBe([]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'no Stripe configured') && $context['subscription'] === $id)->once();
    });

    it('writes in the log what is still open once the last try failed', function (): void {
        Log::spy();

        (new CancelStripeSubscription('sub_open', true))->failed(StripeException::unreachable(new RuntimeException('Stripe is down.')));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'dashboard of Stripe') && $context['subscription'] === 'sub_open' && $context['at_period_end'] === true)->once();
    });
});

describe('on a real queue', function (): void {
    beforeEach(function (): void {
        config(['queue.default' => 'database']);
    });

    it('is tried again after a failure worth it, with a growing pause, and is done once Stripe answers', function (): void {
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');
        $stripe->failNext('DELETE /v1/subscriptions/{id}', 503, 2);
        CancelStripeSubscription::dispatch($id);

        billingCancelWorkOnce($this);
        expect(DB::table('jobs')->count())->toBe(1)->and(DB::table('failed_jobs')->count())->toBe(0);

        // Not due before the pause.
        billingCancelWorkOnce($this);
        expect($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(1);

        $this->travel(61)->seconds();
        billingCancelWorkOnce($this);
        expect(DB::table('jobs')->count())->toBe(1)->and($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(2);

        $this->travel(301)->seconds();
        billingCancelWorkOnce($this);

        expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and($stripe->subscriptions[$id]['status'])->toBe('canceled');
    });

    it('is failed after five tries, and says in the log what to do by hand', function (): void {
        Log::spy();
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');
        $stripe->failNext('DELETE /v1/subscriptions/{id}', 500, 5);
        CancelStripeSubscription::dispatch($id);

        foreach (range(1, 5) as $ignored) {
            billingCancelWorkOnce($this);
            $this->travel(31)->minutes();
        }

        expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(1)->and($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(5);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'dashboard of Stripe') && ($context['subscription'] ?? null) === $id && array_key_exists('exception', $context))->once();
    });
});
