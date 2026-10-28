<?php

declare(strict_types=1);

use App\Billing\BillingSync;
use App\Billing\StripeException;
use App\Jobs\SyncBillingAccount;
use App\Mail\BillingAlertMail;
use App\Models\BillingAccount;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeStripe;

require_once __DIR__.'/../../Support/billing.php';

/*
 * The job that reads a customer again: what it does with the answer of Stripe, how it is kept to one per account, and what it does with
 * a failure. Only a failure worth another try is thrown; a refusal is written in the log and the job is done with.
 */

uses(RefreshDatabase::class);

/**
 * Has a worker of the database queue take the next job that is due and give it one try.
 */
function billingWorkOnce(object $test): void
{
    // The memory of the worker is the one of the whole test run: the default limit of the command (128 MB) would stop it.
    $test->artisan('queue:work', ['--once' => true, '--memory' => 8192])->assertSuccessful();
}

describe('what it does', function (): void {
    it('brings the account up to date with Stripe', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');

        (new SyncBillingAccount($account->id))->handle(app(BillingSync::class));

        expect(BillingAccount::query()->findOrFail($account->id))
            ->status->toBe('active')
            ->synced_at->not->toBeNull();
        expect($stripe->count('GET /v1/subscriptions'))->toBe(1);
    });

    it('does nothing for an account that is gone, and does not call Stripe', function (): void {
        $stripe = billingOn();

        (new SyncBillingAccount(987654))->handle(app(BillingSync::class));

        expect($stripe->calls)->toBe([]);
    });

    it('is the job a burst of events costs one of: unique per account while it waits, never two at once', function (): void {
        $job = new SyncBillingAccount(42);
        $middleware = $job->middleware();

        expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
            ->and($job->uniqueId())->toBe('42')
            ->and((new SyncBillingAccount(43))->uniqueId())->not->toBe($job->uniqueId())
            ->and($middleware)->toHaveCount(1)
            ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
            ->and($middleware[0]->key)->toBe('42')
            ->and($middleware[0]->releaseAfter)->toBe(SyncBillingAccount::OVERLAP_RELEASE)
            ->and($middleware[0]->expiresAfter)->toBe(SyncBillingAccount::LOCK_EXPIRES)
            ->and(SyncBillingAccount::LOCK_EXPIRES)->toBeGreaterThan(SyncBillingAccount::TIMEOUT);
    });
});

describe('what it does with a failure', function (): void {
    it('throws what is worth another try, for the queue to try again', function (?int $status): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $stripe->failNext('GET /v1/subscriptions', $status);

        expect(fn () => (new SyncBillingAccount($account->id))->handle(app(BillingSync::class)))->toThrow(StripeException::class)
            ->and(BillingAccount::query()->findOrFail($account->id)->status)->toBeNull();
    })->with(['an error of Stripe' => [500], 'a service that is down' => [503], 'too many calls' => [429], 'a lost connection' => [null]]);

    it('does not throw a refusal, which will be the same next time: it is written in the log', function (int $status): void {
        Log::spy();
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $stripe->failNext('GET /v1/subscriptions', $status);

        (new SyncBillingAccount($account->id))->handle(app(BillingSync::class));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['status'] === $status && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), FakeStripe::SECRET))->once();
        expect(BillingAccount::query()->findOrFail($account->id)->status)->toBeNull();
    })->with([400, 401, 403]);

    it('gives up on a customer Stripe does not know while the account has a subscription in play, and leaves the account as it is', function (): void {
        Log::spy();
        Mail::fake();
        User::factory()->admin()->create();
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        (new SyncBillingAccount($account->id))->handle(app(BillingSync::class));
        $stripe->loseCustomer($account->stripe_customer_id);

        // Not thrown: the same answer comes each time, the job is done with and the administrators were told.
        (new SyncBillingAccount($account->id))->handle(app(BillingSync::class));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => ($context['reason'] ?? null) === 'resource_missing')->once();
        expect(BillingAccount::query()->findOrFail($account->id)->status)->toBe('active');
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->eventType === 'customer_lost');
    });

    it('writes in the log that it gave up, with the account and not the key', function (): void {
        Log::spy();
        billingOn();

        (new SyncBillingAccount(7))->failed(new RuntimeException('Stripe is down.'));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['billing_account'] === 7)->once();
    });
});

describe('on a real queue', function (): void {
    beforeEach(function (): void {
        config(['queue.default' => 'database']);
    });

    it('queues one job for a burst of dispatches, and another once it has begun', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');

        foreach (range(1, 5) as $ignored) {
            SyncBillingAccount::dispatch($account->id);
        }

        expect(DB::table('jobs')->count())->toBe(1);

        billingWorkOnce($this);

        expect(DB::table('jobs')->count())->toBe(0)
            ->and(BillingAccount::query()->findOrFail($account->id)->status)->toBe('active');

        // The lock is let go of when the job starts: an event that comes after is not lost.
        SyncBillingAccount::dispatch($account->id);

        expect(DB::table('jobs')->count())->toBe(1);
    });

    it('queues one job for each account', function (): void {
        $stripe = billingOn();

        SyncBillingAccount::dispatch(billingAccountAt($stripe, 'active')->id);
        SyncBillingAccount::dispatch(billingAccountAt($stripe, 'active')->id);

        expect(DB::table('jobs')->count())->toBe(2);
    });

    it('puts a job back when another of the same account runs, and runs it when the lock is let go of', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $lock = Cache::lock('laravel-queue-overlap:'.SyncBillingAccount::class.':'.$account->id, 3600);
        expect($lock->get())->toBeTrue();
        SyncBillingAccount::dispatch($account->id);

        // More times than a job that counted that as a try could stand.
        foreach (range(1, 8) as $round) {
            billingWorkOnce($this);

            expect(DB::table('failed_jobs')->count())->toBe(0, "failed at the round {$round}")->and(DB::table('jobs')->count())->toBe(1);
            $this->travel(SyncBillingAccount::OVERLAP_RELEASE + 1)->seconds();
        }

        expect($stripe->calls)->toBe([]);

        $lock->release();
        billingWorkOnce($this);

        expect(DB::table('jobs')->count())->toBe(0)->and(BillingAccount::query()->findOrFail($account->id)->status)->toBe('active');
    });

    it('tries again after a failure worth it, and is done once Stripe answers', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $stripe->failNext('GET /v1/subscriptions', 500);
        SyncBillingAccount::dispatch($account->id);

        billingWorkOnce($this);

        expect(DB::table('failed_jobs')->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(1);

        $this->travel(31)->seconds();
        billingWorkOnce($this);

        expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and(BillingAccount::query()->findOrFail($account->id)->status)->toBe('active');
    });

    it('is failed after three failures, which the schedule makes up for', function (): void {
        Log::spy();
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $stripe->failNext('GET /v1/subscriptions', 500, 3);
        SyncBillingAccount::dispatch($account->id);

        foreach ([1, 2, 3] as $round) {
            billingWorkOnce($this);
            $this->travel(121)->seconds();
        }

        expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(1);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => ($context['billing_account'] ?? null) === $account->id && str_contains($message, 'schedule'))->once();
    });

    it('is done with a refusal at once, without being tried again or failed', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $stripe->failNext('GET /v1/subscriptions', 403);
        SyncBillingAccount::dispatch($account->id);

        billingWorkOnce($this);

        expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0)->and($stripe->count('GET /v1/subscriptions'))->toBe(1);
    });

    it('is done with an account that was deleted while it waited', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        SyncBillingAccount::dispatch($account->id);
        $account->workspace->delete();

        billingWorkOnce($this);

        // The deletion of the workspace queued the purge of its files too: only the job of the account is of concern here.
        expect(DB::table('jobs')->where('payload', 'like', '%SyncBillingAccount%')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and($stripe->calls)->toBe([]);
    });
});
