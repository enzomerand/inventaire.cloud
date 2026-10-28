<?php

declare(strict_types=1);

use App\Billing\Billing;
use App\Billing\StripeClient;
use App\Billing\StripeException;
use App\Jobs\CancelStripeSubscription;
use App\Jobs\SyncBillingAccount;
use App\Mail\BillingAlertMail;
use App\Models\BillingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeStripe;

require_once __DIR__.'/../../Support/billing.php';

/*
 * The job that ends the subscriptions of a customer at Stripe, once the workspace is deleted (now) or has a new owner (with the period):
 * which subscriptions it finds, what it asks of Stripe, what it takes for done, what it tries again and what it leaves to a person.
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
    it('cancels the subscription of the customer at Stripe', function (): void {
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a', 'active');

        billingCancel(new CancelStripeSubscription('cus_a'));

        expect($stripe->subscriptions[$id]['status'])->toBe('canceled')
            ->and($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(1)
            ->and($stripe->count('POST /v1/subscriptions/{id}'))->toBe(0);
    });

    it('cancels every subscription of the customer that is not over, whichever its status, and touches no other customer', function (): void {
        $stripe = billingOn();
        $live = [];

        foreach (['active', 'trialing', 'past_due', 'unpaid', 'paused', 'incomplete'] as $status) {
            $live[] = $stripe->subscribe('cus_a', $status);
        }

        $over = [$stripe->subscribe('cus_a', 'canceled'), $stripe->subscribe('cus_a', 'incomplete_expired')];
        $other = $stripe->subscribe('cus_b', 'active');

        billingCancel(new CancelStripeSubscription('cus_a'));

        expect(array_map(fn (string $id): string => $stripe->subscriptions[$id]['status'], $live))->toBe(array_fill(0, 6, 'canceled'))
            ->and(array_map(fn (string $id): string => $stripe->subscriptions[$id]['status'], $over))->toBe(['canceled', 'incomplete_expired'])
            ->and($stripe->subscriptions[$other]['status'])->toBe('active')
            ->and($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(6);
    });

    it('finds a subscription that its account never recorded: that is the point of asking the customer', function (): void {
        $stripe = billingOn();
        // The checkout was paid, then the workspace was deleted before the synchronisation wrote the subscription in the account.
        $account = billingAccountAt($stripe);
        $id = $stripe->subscribe($account->stripe_customer_id, 'active');

        expect($account->stripe_subscription_id)->toBeNull();

        billingCancel(new CancelStripeSubscription($account->stripe_customer_id));

        expect($stripe->subscriptions[$id]['status'])->toBe('canceled');
    });

    it('lists the subscriptions of the customer, the canceled ones included, and nothing else', function (): void {
        $stripe = billingOn();
        $stripe->subscribe('cus_a');

        billingCancel(new CancelStripeSubscription('cus_a'));

        $request = $stripe->requestsOf('GET /v1/subscriptions')[0];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        expect($stripe->count('GET /v1/subscriptions'))->toBe(1)->and($query['customer'])->toBe('cus_a')->and($query['status'])->toBe('all');
    });

    it('has nothing to do for a customer without a subscription, or whose subscriptions are all over', function (): void {
        $stripe = billingOn();
        $stripe->addCustomer('cus_none');
        $stripe->subscribe('cus_over', 'canceled');

        billingCancel(new CancelStripeSubscription('cus_none'));
        billingCancel(new CancelStripeSubscription('cus_over'));

        expect($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(0)->and($stripe->count('POST /v1/subscriptions/{id}'))->toBe(0);
    });

    it('takes a customer that Stripe does not have any more for done, but writes it in the log as an error: the keys may be those of another mode', function (): void {
        Log::spy();
        Mail::fake();
        User::factory()->admin()->create();
        $stripe = billingOn();
        $stripe->failNext('GET /v1/subscriptions', 404);

        billingCancel(new CancelStripeSubscription('cus_gone'));

        expect($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(0);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'is not at Stripe') && $context['customer'] === 'cus_gone')->once();
        // Done, not failed: nobody is mailed for a customer there is nothing to cancel for.
        Mail::assertNothingQueued();
    });

    it('takes a subscription that is gone by the time it is cancelled for cancelled', function (): void {
        Log::spy();
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');
        $stripe->failNext('DELETE /v1/subscriptions/{id}', 404);

        billingCancel(new CancelStripeSubscription('cus_a'));

        Log::shouldNotHaveReceived('error');
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'not at Stripe any more') && $context['subscription'] === $id)->once();
    });

    it('takes a subscription that Stripe refuses to cancel because it is already canceled for cancelled, and says so only in the log of information', function (bool $atPeriodEnd): void {
        Log::spy();
        Queue::fake();
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');
        // Canceled in the dashboard between the list and the call: Stripe answers 400 and the subscription is over.
        $stripe->onCall($atPeriodEnd ? 'POST /v1/subscriptions/{id}' : 'DELETE /v1/subscriptions/{id}', fn () => $stripe->setStatus($id, 'canceled'));
        $stripe->failNext($atPeriodEnd ? 'POST /v1/subscriptions/{id}' : 'DELETE /v1/subscriptions/{id}', 400);

        billingCancel(new CancelStripeSubscription('cus_a', $atPeriodEnd, 77));

        Log::shouldNotHaveReceived('error');
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'over by the time it was asked') && $context['subscription'] === $id)->once();
        Queue::assertPushed(SyncBillingAccount::class, fn (SyncBillingAccount $job): bool => $job->billingAccountId === 77);
    })->with([[false], [true]]);

    it('names the subscription in the path and nothing in a form', function (): void {
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');

        billingCancel(new CancelStripeSubscription('cus_a'));

        $request = $stripe->requestsOf('DELETE /v1/subscriptions/{id}')[0];

        expect($request->url())->toEndWith('/v1/subscriptions/'.$id)->and($request->method())->toBe('DELETE');
    });
});

describe('cancelling with the period', function (): void {
    it('has every subscription of the customer end at the end of the period that is paid for, and keeps them running until then', function (): void {
        $stripe = billingOn();
        $first = $stripe->subscribe('cus_a', 'active');
        $second = $stripe->subscribe('cus_a', 'past_due');
        $over = $stripe->subscribe('cus_a', 'canceled');
        $other = $stripe->subscribe('cus_b', 'active');

        billingCancel(new CancelStripeSubscription('cus_a', true));

        expect($stripe->subscriptions[$first]['cancel_at_period_end'])->toBeTrue()
            ->and($stripe->subscriptions[$first]['status'])->toBe('active')
            ->and($stripe->subscriptions[$second]['cancel_at_period_end'])->toBeTrue()
            ->and($stripe->subscriptions[$over]['cancel_at_period_end'])->toBeFalse()
            ->and($stripe->subscriptions[$other]['cancel_at_period_end'])->toBeFalse()
            ->and($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(0)
            ->and($stripe->count('POST /v1/subscriptions/{id}'))->toBe(2);
    });

    it('finds a subscription that its account never recorded', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $id = $stripe->subscribe($account->stripe_customer_id, 'active');

        billingCancel(new CancelStripeSubscription($account->stripe_customer_id, true, $account->id));

        expect($stripe->subscriptions[$id]['cancel_at_period_end'])->toBeTrue();
    });

    it('does not ask again for one that is ending already', function (): void {
        $stripe = billingOn();
        $stripe->subscribe('cus_a', 'active', cancelAtPeriodEnd: true);

        billingCancel(new CancelStripeSubscription('cus_a', true));

        expect($stripe->count('POST /v1/subscriptions/{id}'))->toBe(0);
    });

    it('takes a customer that Stripe does not have any more for done', function (): void {
        Log::spy();
        $stripe = billingOn();
        $stripe->failNext('GET /v1/subscriptions', 404);

        billingCancel(new CancelStripeSubscription('cus_gone', true));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'is not at Stripe') && $context['customer'] === 'cus_gone')->once();
        expect($stripe->count('POST /v1/subscriptions/{id}'))->toBe(0);
    });

    it('keeps one idempotency key for each subscription and all the tries of the job, and others for another job', function (): void {
        $stripe = billingOn();
        $first = $stripe->subscribe('cus_a');
        $second = $stripe->subscribe('cus_a');
        $stripe->failNext('POST /v1/subscriptions/{id}', 500);
        $job = new CancelStripeSubscription('cus_a', true);

        // The first subscription fails, the second is done; the second try finds the first only (the second is ending already).
        expect(fn () => billingCancel($job))->toThrow(StripeException::class);
        billingCancel(unserialize(serialize($job)));

        $keys = array_map(fn ($request): string => $request->header('Idempotency-Key')[0], $stripe->requestsOf('POST /v1/subscriptions/{id}'));

        expect($keys)->toHaveCount(3)
            ->and($keys[0])->toBe($keys[2])
            ->and($keys[1])->not->toBe($keys[0])
            ->and($stripe->subscriptions[$first]['cancel_at_period_end'])->toBeTrue()
            ->and($stripe->subscriptions[$second]['cancel_at_period_end'])->toBeTrue();

        $stripe->change($first, ['cancel_at_period_end' => false]);
        billingCancel(new CancelStripeSubscription('cus_a', true));

        $again = array_map(fn ($request): string => $request->header('Idempotency-Key')[0], $stripe->requestsOf('POST /v1/subscriptions/{id}'));

        expect($again[3])->not->toBe($keys[1]);
    });
});

describe('reading the account again', function (): void {
    it('queues the synchronisation of the account of a workspace that is still there, once the subscriptions are ended', function (bool $atPeriodEnd): void {
        Queue::fake();
        $stripe = billingOn();
        $stripe->subscribe('cus_a');

        billingCancel(new CancelStripeSubscription('cus_a', $atPeriodEnd, 77));

        Queue::assertPushed(SyncBillingAccount::class, fn (SyncBillingAccount $job): bool => $job->billingAccountId === 77);
    })->with([true, false]);

    it('queues it for a customer Stripe does not have any more, or without a subscription, as well', function (): void {
        Queue::fake();
        $stripe = billingOn();
        $stripe->failNext('GET /v1/subscriptions', 404);
        $stripe->addCustomer('cus_none');

        billingCancel(new CancelStripeSubscription('cus_gone', true, 77));
        billingCancel(new CancelStripeSubscription('cus_none', true, 78));

        Queue::assertPushed(SyncBillingAccount::class, 2);
    });

    it('queues nothing for a workspace that is gone', function (): void {
        Queue::fake();
        $stripe = billingOn();
        $stripe->subscribe('cus_a');

        billingCancel(new CancelStripeSubscription('cus_a'));

        Queue::assertNothingPushed();
    });

    it('does not queue it when a subscription could not be ended', function (): void {
        Queue::fake();
        $stripe = billingOn();
        $stripe->subscribe('cus_a');
        $stripe->failNext('POST /v1/subscriptions/{id}', 400);

        billingCancel(new CancelStripeSubscription('cus_a', true, 77));

        Queue::assertNothingPushed();
    });

    it('brings the page up to date: the account says the subscription ends with the period', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        config(['queue.default' => 'sync']);

        billingCancel(new CancelStripeSubscription($account->stripe_customer_id, true, $account->id));

        expect(BillingAccount::query()->findOrFail($account->id)->cancel_at_period_end)->toBeTrue();
    });
});

describe('what goes wrong', function (): void {
    it('throws what is worth another try, for the queue to try again', function (bool $atPeriodEnd, ?int $status): void {
        Queue::fake();
        $stripe = billingOn();
        $stripe->subscribe('cus_a');
        $stripe->failNext($atPeriodEnd ? 'POST /v1/subscriptions/{id}' : 'DELETE /v1/subscriptions/{id}', $status);

        expect(fn () => billingCancel(new CancelStripeSubscription('cus_a', $atPeriodEnd, 77)))->toThrow(StripeException::class);
        Queue::assertNothingPushed();
    })->with([
        'now, an error of Stripe' => [false, 500],
        'now, too many calls' => [false, 429],
        'now, a lost connection' => [false, null],
        'with the period, an error of Stripe' => [true, 503],
        'with the period, a lost connection' => [true, null],
    ]);

    it('throws when the list of the subscriptions cannot be read, for the queue to try again', function (?int $status): void {
        Queue::fake();
        $stripe = billingOn();
        $stripe->subscribe('cus_a');
        $stripe->failNext('GET /v1/subscriptions', $status);

        expect(fn () => billingCancel(new CancelStripeSubscription('cus_a', false, 77)))->toThrow(StripeException::class);
        expect($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(0);
        Queue::assertNothingPushed();
    })->with([500, 429, null]);

    it('ends the other subscriptions before it throws for the one that failed', function (): void {
        $stripe = billingOn();
        $first = $stripe->subscribe('cus_a');
        $second = $stripe->subscribe('cus_a');
        $stripe->failNext('DELETE /v1/subscriptions/{id}', 503);

        expect(fn () => billingCancel(new CancelStripeSubscription('cus_a')))->toThrow(StripeException::class);

        // The newest is listed first: it is the one that failed, and the other was ended all the same.
        expect([$stripe->subscriptions[$first]['status'], $stripe->subscriptions[$second]['status']])->toBe(['canceled', 'active']);

        billingCancel(new CancelStripeSubscription('cus_a'));

        expect($stripe->subscriptions[$second]['status'])->toBe('canceled')->and($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(3);
    });

    it('leaves a refusal to a person: it is written in the log, not tried again, and the other subscriptions are ended', function (bool $atPeriodEnd, int $status): void {
        Log::spy();
        $stripe = billingOn();
        $first = $stripe->subscribe('cus_a');
        $second = $stripe->subscribe('cus_a');
        $stripe->failNext($atPeriodEnd ? 'POST /v1/subscriptions/{id}' : 'DELETE /v1/subscriptions/{id}', $status);

        billingCancel(new CancelStripeSubscription('cus_a', $atPeriodEnd));

        // The newest is listed first, and it is the one that was refused.
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'dashboard of Stripe')
            && $context['customer'] === 'cus_a'
            && $context['subscription'] === $second
            && $context['status'] === $status
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), FakeStripe::SECRET))->once();
        expect($atPeriodEnd ? $stripe->subscriptions[$first]['cancel_at_period_end'] : $stripe->subscriptions[$first]['status'] === 'canceled')->toBeTrue();
    })->with([[false, 400], [false, 401], [false, 403], [true, 400], [true, 403]]);

    it('leaves the refusal to read the list to a person too', function (int $status): void {
        Log::spy();
        $stripe = billingOn();
        $stripe->subscribe('cus_a');
        $stripe->failNext('GET /v1/subscriptions', $status);

        billingCancel(new CancelStripeSubscription('cus_a'));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'dashboard of Stripe') && $context['customer'] === 'cus_a' && $context['status'] === $status)->once();
        expect($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(0);
    })->with([400, 401, 403]);

    it('does not take a 404 that is not the answer of Stripe for a customer that is gone', function (): void {
        Log::spy();
        Queue::fake();
        $stripe = billingOn();
        $stripe->answerWith(Http::response('<html>Not Found</html>', 404));

        billingCancel(new CancelStripeSubscription('cus_a', false, 77));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'dashboard of Stripe') && $context['status'] === 404)->once();
        Queue::assertNothingPushed();
        expect($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(0);
    });

    it('can do nothing without a Stripe, and says so in the log', function (): void {
        Log::spy();
        $stripe = billingOn();
        $stripe->subscribe('cus_a');
        billingOff();

        billingCancel(new CancelStripeSubscription('cus_a'));

        expect($stripe->calls)->toBe([]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'no Stripe configured') && $context['customer'] === 'cus_a')->once();
    });

    it('writes in the log what is still open once the last try failed', function (): void {
        Log::spy();

        (new CancelStripeSubscription('cus_open', true))->failed(StripeException::unreachable(new RuntimeException('Stripe is down.')));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'dashboard of Stripe') && $context['customer'] === 'cus_open' && $context['at_period_end'] === true)->once();
    });
});

describe('the time it is given', function (): void {
    it('leaves room to list the subscriptions page by page and to end the live ones, each call taking 20 seconds, and stays under what the queue waits before handing the job to another worker', function (): void {
        $job = new CancelStripeSubscription('cus_a');

        expect($job->timeout)->toBeGreaterThanOrEqual((StripeClient::MAX_PAGES + 2) * StripeClient::TIMEOUT)
            ->and($job->timeout)->toBeLessThan(config('queue.connections.database.retry_after'));
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
        CancelStripeSubscription::dispatch('cus_a');

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
        $stripe->subscribe('cus_a');
        $stripe->failNext('DELETE /v1/subscriptions/{id}', 500, 5);
        CancelStripeSubscription::dispatch('cus_a');

        foreach (range(1, 5) as $ignored) {
            billingCancelWorkOnce($this);
            $this->travel(31)->minutes();
        }

        expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(1)->and($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(5);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'dashboard of Stripe') && ($context['customer'] ?? null) === 'cus_a' && array_key_exists('exception', $context))->once();
    });
});

describe('the Checkout sessions that are still open', function (): void {
    it('are expired before the subscriptions are listed and ended, now or with the period', function (bool $atPeriodEnd): void {
        $stripe = billingOn();
        $stripe->subscribe('cus_a');
        $stripe->addCustomer('cus_other');
        $stripe->sessions['cs_open'] = ['id' => 'cs_open', 'customer' => 'cus_a', 'status' => 'open'];
        $stripe->sessions['cs_other'] = ['id' => 'cs_other', 'customer' => 'cus_other', 'status' => 'open'];
        $stripe->sessions['cs_done'] = ['id' => 'cs_done', 'customer' => 'cus_a', 'status' => 'complete'];

        billingCancel(new CancelStripeSubscription('cus_a', $atPeriodEnd));

        expect($stripe->calls)->toBe([
            'GET /v1/checkout/sessions',
            'POST /v1/checkout/sessions/{id}/expire',
            'GET /v1/subscriptions',
            $atPeriodEnd ? 'POST /v1/subscriptions/{id}' : 'DELETE /v1/subscriptions/{id}',
        ])
            ->and($stripe->sessions['cs_open']['status'])->toBe('expired')
            ->and($stripe->sessions['cs_other']['status'])->toBe('open')
            ->and($stripe->sessions['cs_done']['status'])->toBe('complete');
    })->with([false, true]);

    it('are no failure when one is not open any more: it was paid, and the subscription it started is ended with the others', function (): void {
        Log::spy();
        $stripe = billingOn();
        $stripe->addCustomer('cus_a');
        $stripe->sessions['cs_open'] = ['id' => 'cs_open', 'customer' => 'cus_a', 'status' => 'open'];
        // The customer pays just as the session is expired.
        $stripe->onCall('POST /v1/checkout/sessions/{id}/expire', function () use ($stripe): void {
            $stripe->completeCheckout('cs_open');
            $stripe->subscribe('cus_a', 'active', id: 'sub_started_by_the_session');
        });

        billingCancel(new CancelStripeSubscription('cus_a'));

        expect($stripe->subscriptions['sub_started_by_the_session']['status'])->toBe('canceled');
        Log::shouldNotHaveReceived('error');
    });

    it('go on to the subscriptions when Stripe cannot list or expire them, and come back for it', function (string $call): void {
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');
        $stripe->sessions['cs_open'] = ['id' => 'cs_open', 'customer' => 'cus_a', 'status' => 'open'];
        $stripe->failNext($call, 500);

        expect(fn () => billingCancel(new CancelStripeSubscription('cus_a')))->toThrow(StripeException::class);

        // The subscription is ended all the same; the queue comes back to expire the session.
        expect($stripe->subscriptions[$id]['status'])->toBe('canceled');

        billingCancel(new CancelStripeSubscription('cus_a'));

        expect($stripe->sessions['cs_open']['status'])->toBe('expired');
    })->with(['GET /v1/checkout/sessions', 'POST /v1/checkout/sessions/{id}/expire']);

    it('are left to expire by themselves, with an error in the log and a mail to the administrators, when Stripe refuses for good', function (): void {
        Log::spy();
        Mail::fake();
        User::factory()->admin()->create(['email' => 'admin@example.test', 'locale' => 'en']);
        $stripe = billingOn();
        $id = $stripe->subscribe('cus_a');
        $stripe->sessions['cs_open'] = ['id' => 'cs_open', 'customer' => 'cus_a', 'status' => 'open'];
        $stripe->failNext('POST /v1/checkout/sessions/{id}/expire', 403);

        billingCancel(new CancelStripeSubscription('cus_a', false, null, 'ws_ulid'));

        expect($stripe->subscriptions[$id]['status'])->toBe('canceled');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'could not be expired') && $context['session'] === 'cs_open' && $context['status'] === 403)->once();
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->eventType === 'cancellation_failed' && $mail->objectId === 'cus_a' && $mail->workspaceUlid === 'ws_ulid');
    });

    it('are none for a customer Stripe does not know: the list of its subscriptions says so', function (): void {
        $stripe = billingOn();
        $stripe->failNext('GET /v1/checkout/sessions', 404);
        $stripe->failNext('GET /v1/subscriptions', 404);

        billingCancel(new CancelStripeSubscription('cus_gone'));

        expect($stripe->count('POST /v1/checkout/sessions/{id}/expire'))->toBe(0)->and($stripe->count('DELETE /v1/subscriptions/{id}'))->toBe(0);
    });
});

describe('what it tells the administrators', function (): void {
    beforeEach(function (): void {
        Mail::fake();
        $this->administrator = User::factory()->admin()->create(['email' => 'admin@example.test', 'locale' => 'en']);
        User::factory()->create(['email' => 'member@example.test']);
    });

    it('is mailed when the last try failed, with the customer and the workspace', function (): void {
        (new CancelStripeSubscription('cus_open', true, null, '01jworkspace'))->failed(StripeException::unreachable(new RuntimeException('Stripe is down.')));

        Mail::assertQueued(BillingAlertMail::class, 1);
        Mail::assertQueued(BillingAlertMail::class, function (BillingAlertMail $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('admin@example.test')
                && $mail->eventType === 'cancellation_failed'
                && $mail->objectId === 'cus_open'
                && $mail->workspaceUlid === '01jworkspace'
                && str_contains($html, 'cus_open')
                && str_contains($html, '01jworkspace')
                && str_contains($html, 'https://dashboard.stripe.com/customers/cus_open');
        });
    });

    it('links to the test side of the dashboard when the key of the instance is a test key', function (): void {
        billingOn();

        expect(Billing::livemode())->toBeFalse();

        (new CancelStripeSubscription('cus_open'))->failed(StripeException::unreachable(new RuntimeException('Stripe is down.')));

        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->dashboardUrl() === 'https://dashboard.stripe.com/test/customers/cus_open' && str_contains($mail->render(), 'test mode'));
    });

    it('is mailed without a workspace when none is known', function (): void {
        (new CancelStripeSubscription('cus_open'))->failed(StripeException::unreachable(new RuntimeException('Stripe is down.')));

        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->objectId === 'cus_open' && $mail->workspaceUlid === null && ! str_contains($mail->render(), 'Workspace:'));
    });

    it('is mailed when Stripe refuses the cancellation for good, or the list of the subscriptions, or there is no Stripe any more', function (string $case): void {
        $stripe = billingOn();
        $stripe->subscribe('cus_a');

        match ($case) {
            'a cancellation refused' => $stripe->failNext('DELETE /v1/subscriptions/{id}', 403),
            'the list refused' => $stripe->failNext('GET /v1/subscriptions', 403),
            default => billingOff(),
        };

        billingCancel(new CancelStripeSubscription('cus_a', false, null, '01jworkspace'));

        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->eventType === 'cancellation_failed' && $mail->objectId === 'cus_a' && $mail->workspaceUlid === '01jworkspace');
    })->with(['a cancellation refused', 'the list refused', 'no Stripe any more']);

    it('is not mailed when everything was cancelled, nor for a failure worth another try (the queue comes back)', function (): void {
        $stripe = billingOn();
        $stripe->subscribe('cus_a');
        $stripe->subscribe('cus_b');
        $stripe->failNext('DELETE /v1/subscriptions/{id}', 500);

        expect(fn () => billingCancel(new CancelStripeSubscription('cus_a')))->toThrow(StripeException::class);
        billingCancel(new CancelStripeSubscription('cus_b'));

        Mail::assertNothingQueued();
    });

    it('is mailed once a day for a customer, however many jobs give up', function (): void {
        $job = new CancelStripeSubscription('cus_open');
        $job->failed(StripeException::unreachable(new RuntimeException('Stripe is down.')));
        $job->failed(StripeException::unreachable(new RuntimeException('Stripe is down.')));

        Mail::assertQueued(BillingAlertMail::class, 1);

        $this->travel(25)->hours();
        $job->failed(StripeException::unreachable(new RuntimeException('Stripe is down.')));

        Mail::assertQueued(BillingAlertMail::class, 2);
    });

    it('does not make the job fail when the mail cannot be queued: it is written in the log', function (): void {
        Log::spy();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('The queue is down.'));

        (new CancelStripeSubscription('cus_open'))->failed(StripeException::unreachable(new RuntimeException('Stripe is down.')));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'administrators could not be told') && $context['customer'] === 'cus_open')->once();
    });
});
