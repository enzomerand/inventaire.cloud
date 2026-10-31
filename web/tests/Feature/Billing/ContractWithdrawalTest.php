<?php

declare(strict_types=1);

use App\Billing\WithdrawalRefusedException;
use App\Billing\Withdrawals;
use App\Jobs\CancelStripeSubscription;
use App\Mail\BillingAlertMail;
use App\Mail\WithdrawalAcknowledgedMail;
use App\Models\BillingAccount;
use App\Models\BillingWithdrawal;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mime\Email;
use Tests\Support\FakeStripe;

require_once __DIR__.'/../../Support/billing.php';
require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/*
 * The two things the terms of sale promise a client, through the API: cancelling the contract (the portal of Stripe opened on the
 * cancellation) and withdrawing from it during the fourteen days that follow it (the declaration is stored, then the subscription is
 * cancelled, the person acknowledged and the administrators told what to refund). Stripe is the FakeStripe of the tests.
 *
 * The clock is fixed at a known moment of a winter day, so that the times the mails write ("3:30 PM (UTC+1)") and the days counted are exact.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
    config(['app.display_timezone' => 'Europe/Paris', 'billing.currency' => 'eur', 'billing.amounts' => ['month' => 600, 'year' => 6000]]);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 14:30:00', 'UTC'));
});

/**
 * A team whose workspace has a subscription its owner pays, begun `$secondsAgo` seconds ago for a period of 30 days (365 for a yearly
 * one), and a Stripe that knows the customer, the subscription (with the same start) and an invoice of `$paid` cents.
 *
 * @return array{owner: User, editor: User, viewer: User, stranger: User, workspace: Workspace, stripe: FakeStripe, account: BillingAccount, subscription: string, startedAt: CarbonImmutable}
 */
function contractTeam(string $interval = 'month', int $secondsAgo = 3 * 86400, ?int $paid = 600): array
{
    $stripe = billingOn();
    $team = inventoryTeam();
    // The owner reads English, and was told of the contract when it began (the confirmation is not what these tests are about).
    $team['owner']->forceFill(['locale' => 'en'])->save();
    $startedAt = CarbonImmutable::now()->subSeconds($secondsAgo);
    $periodEnd = $startedAt->addDays($interval === 'year' ? 365 : 30);
    $subscriptionId = 'sub_'.Str::random(14);
    $account = BillingAccount::factory()->for($team['workspace'])->paidBy($team['owner'])->subscribed('active', $interval)->create([
        'stripe_subscription_id' => $subscriptionId,
        'confirmation_sent_for' => $subscriptionId,
        'started_at' => $startedAt,
        'current_period_end' => $periodEnd,
    ]);
    $stripe->addCustomer($account->stripe_customer_id, 'owner@example.test');
    $subscription = $stripe->subscribe($account->stripe_customer_id, 'active', $interval, $periodEnd->getTimestamp(), false, $account->stripe_subscription_id);
    $stripe->change($subscription, ['start_date' => $startedAt->getTimestamp()]);

    if ($paid !== null) {
        $stripe->invoice($subscription, $paid);
    }

    return $team + ['stripe' => $stripe, 'account' => $account, 'subscription' => $subscription, 'startedAt' => $startedAt];
}

/**
 * Declares a withdrawal as a person at their browser.
 *
 * @param  array<string, mixed>  $data
 */
function contractWithdraw(object $test, array $team, ?User $as = null, array $data = []): TestResponse
{
    return inventoryCall($test, $as ?? $team['owner'], 'POST', billingApiUri($team['workspace'], 'withdraw'), $data + ['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
}

/**
 * An administrator who reads the alerts in English.
 */
function contractAdmin(string $email = 'admin@example.test'): User
{
    return User::factory()->admin()->create(['email' => $email, 'locale' => 'en']);
}

/**
 * The mails the instance sent, as `[recipient, subject, text]`.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function contractMails(): array
{
    return array_map(fn (Email $mail): array => [$mail->getTo()[0]->getAddress(), (string) $mail->getSubject(), (string) $mail->getTextBody()], sentMails());
}

/**
 * The text of the mail sent to an address; fails the test when there is none.
 */
function contractMailTo(string $address, ?string $subject = null): string
{
    foreach (contractMails() as [$to, $title, $text]) {
        if ($to === $address && ($subject === null || str_contains($title, $subject))) {
            return $text;
        }
    }

    throw new RuntimeException('No mail'.($subject === null ? '' : " [{$subject}]")." to {$address} among: ".json_encode(array_map(fn (array $mail): array => [$mail[0], $mail[1]], contractMails())));
}

describe('withdrawing from the contract', function (): void {
    it('stores the declaration, then cancels the subscription, acknowledges the person and tells the administrators what to refund', function (): void {
        contractAdmin();
        $team = contractTeam();
        $base = DB::transactionLevel();
        $seen = null;
        // The cancellation is asked of Stripe once the declaration is committed: no transaction is open, and the row is there.
        $team['stripe']->onCall('DELETE /v1/subscriptions/{id}', function () use (&$seen): void {
            $seen = [DB::transactionLevel(), BillingWithdrawal::query()->count()];
        });

        $answer = contractWithdraw($this, $team)->assertCreated();

        $withdrawal = BillingWithdrawal::query()->sole();

        expect($seen)->toBe([$base, 1])
            ->and($withdrawal->only(['workspace_id', 'user_id', 'stripe_customer_id', 'stripe_subscription_id', 'name', 'email', 'amount_paid_cents', 'refund_cents', 'currency']))->toBe([
                'workspace_id' => $team['workspace']->id,
                'user_id' => $team['owner']->id,
                'stripe_customer_id' => $team['account']->stripe_customer_id,
                'stripe_subscription_id' => $team['subscription'],
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.test',
                'amount_paid_cents' => 600,
                // Three days used out of thirty: a tenth is kept.
                'refund_cents' => 540,
                'currency' => 'eur',
            ])
            ->and($withdrawal->submitted_at?->toIso8601String())->toBe('2026-11-10T14:30:00+00:00')
            ->and($withdrawal->acknowledged_at)->not->toBeNull()
            ->and($team['stripe']->subscriptions[$team['subscription']]['status'])->toBe('canceled')
            ->and($team['account']->refresh()->status)->toBe('canceled')
            ->and($answer->json('data.withdrawn'))->toBeTrue()
            ->and($answer->json('data.withdrawal_until'))->toBeNull();
    });

    it('acknowledges the withdrawal on a durable medium: the declaration, the date and time with their zone, the effects and the refund', function (): void {
        contractAdmin();
        $team = contractTeam();

        contractWithdraw($this, $team, data: ['name' => 'Ada Lovelace', 'email' => 'Receipts@Example.test'])->assertCreated();

        $text = contractMailTo('receipts@example.test', 'Acknowledgement of your withdrawal');

        // The address is the one typed, normalized; it is not the one of the account.
        expect($text)->toContain('Hello Ada Lovelace,')
            ->toContain('Name: Ada Lovelace')
            ->toContain('Email address: receipts@example.test')
            ->toContain('Contract: Pro subscription to '.config('app.name').', monthly')
            ->toContain('Workspace: Shop')
            ->toContain('Ordered on: November 7, 2026')
            // 14:30 UTC is 3:30 PM in Paris in November: the zone is written next to the time of day.
            ->toContain('Declaration received on: November 10, 2026 3:30 PM (UTC+1)')
            ->toContain('Your subscription ends now and the workspace goes back to the Free plan. Nothing is deleted.')
            ->toContain('at the latest on November 24, 2026')
            ->toContain('Amount to be refunded: €5.40')
            ->toContain('(paid: €6; service provided: 3 days out of 30)');
        expect(sentMails()[0]->getHtmlBody())->toContain('Declaration received on: November 10, 2026 3:30 PM (UTC+1)');
    });

    it('is acknowledged in the language of the person, with the labels of the terms', function (): void {
        contractAdmin();
        $team = contractTeam();
        $team['owner']->forceFill(['locale' => 'fr'])->save();

        contractWithdraw($this, $team)->assertCreated();

        $text = contractMailTo('ada@example.test', 'Accusé de réception');

        expect($text)->toContain('Nous accusons réception de votre déclaration de rétractation')
            ->toContain('Déclaration reçue le : 10 novembre 2026 15:30 (UTC+1)')
            ->toContain("Montant à rembourser : 5,40\u{00A0}€");
    });

    it('tells every administrator the amount paid, the refund to make and its deadline, in their language', function (): void {
        contractAdmin('admin@example.test');
        User::factory()->admin()->create(['email' => 'admin-fr@example.test', 'locale' => 'fr']);
        $team = contractTeam();

        contractWithdraw($this, $team)->assertCreated();

        $english = contractMailTo('admin@example.test', 'A customer withdrew from their contract');
        $french = contractMailTo('admin-fr@example.test', "Un client s'est rétracté de son contrat");

        expect($english)->toContain('Stripe customer: '.$team['account']->stripe_customer_id)
            ->toContain('Workspace: '.$team['workspace']->ulid)
            ->toContain('Subscription: '.$team['subscription'])
            ->toContain('Declaration received on: November 10, 2026 3:30 PM (UTC+1)')
            ->toContain('Amount paid: 6.00 EUR')
            ->toContain('Refund to make: 5.40 EUR')
            ->toContain('Refund before: November 24, 2026')
            ->toContain('The acknowledgement was sent to the customer.')
            ->toContain('https://dashboard.stripe.com/test/customers/'.$team['account']->stripe_customer_id)
            // The customer is named by their Stripe reference only, never by a name or an address.
            ->not->toContain('Ada Lovelace')->not->toContain('ada@example.test');
        expect($french)->toContain('Remboursement à effectuer : 5.40 EUR')->toContain('À rembourser avant le : 24 novembre 2026');
    });

    it('does not tell of a cancellation: the subscription ended at once, not with the period', function (): void {
        contractAdmin();
        $team = contractTeam();

        contractWithdraw($this, $team)->assertCreated();

        $subjects = implode("\n", array_map(fn (array $mail): string => $mail[1], contractMails()));

        expect($subjects)->not->toContain('is cancelled')
            ->and($team['account']->refresh()->cancel_notice_sent_for)->toBeNull();
    });

    it('is allowed on the last moment of the fourteenth day and refused a second later', function (int $secondsAgo, int $status): void {
        Queue::fake();
        Mail::fake();
        $team = contractTeam(secondsAgo: $secondsAgo);

        $answer = contractWithdraw($this, $team)->assertStatus($status);

        if ($status === 409) {
            $answer->assertJsonPath('code', 'withdrawal_period_over');
        }

        expect(BillingWithdrawal::query()->count())->toBe($status === 201 ? 1 : 0);

        if ($status === 409) {
            Queue::assertNothingPushed();
            Mail::assertNothingQueued();
        } else {
            Queue::assertPushed(CancelStripeSubscription::class, 1);
        }
    })->with([
        'day 1' => [86400, 201],
        'day 14, to the second' => [14 * 86400, 201],
        'day 15' => [14 * 86400 + 1, 409],
        'day 20' => [20 * 86400, 409],
    ]);

    it('counts the fourteen days again for a new subscription', function (): void {
        Queue::fake();
        Mail::fake();
        $team = contractTeam(secondsAgo: 30 * 86400);
        contractWithdraw($this, $team)->assertStatus(409)->assertJsonPath('code', 'withdrawal_period_over');

        // The customer subscribes again: the new subscription starts a new window, which the next reading writes.
        $team['stripe']->setStatus($team['subscription'], 'canceled');
        $new = $team['stripe']->subscribe($team['account']->stripe_customer_id, 'active', 'month', now()->addDays(30)->getTimestamp());
        $team['stripe']->invoice($new, 600);
        $team['stripe']->change($new, ['start_date' => now()->subDay()->getTimestamp()]);

        contractWithdraw($this, $team)->assertCreated();

        expect(BillingWithdrawal::query()->sole()->stripe_subscription_id)->toBe($new)
            ->and($team['account']->refresh()->started_at?->toDateString())->toBe('2026-11-09');
    });

    it('refuses a client who is not an owner, and does not tell a stranger the workspace exists', function (): void {
        Queue::fake();
        Mail::fake();
        $team = contractTeam();

        contractWithdraw($this, $team, $team['editor'])->assertForbidden();
        contractWithdraw($this, $team, $team['viewer'])->assertForbidden();
        contractWithdraw($this, $team, $team['stranger'])->assertNotFound();

        expect(BillingWithdrawal::query()->count())->toBe(0)
            ->and($team['stripe']->calls)->toBe([]);
        Queue::assertNothingPushed();
        Mail::assertNothingQueued();
    });

    it('is not the right of an owner who is not the one who pays while the payer subscription runs', function (): void {
        Queue::fake();
        $team = contractTeam();
        $second = inventoryPerson('Second owner');
        $team['workspace']->members()->attach($second->id, ['role' => 'owner']);

        contractWithdraw($this, $team, $second)->assertStatus(409)->assertJsonPath('code', 'previous_owner_subscription');

        expect(BillingWithdrawal::query()->count())->toBe(0);
    });

    it('needs a name and an address that are valid, and trims and normalizes them', function (): void {
        Queue::fake();
        Mail::fake();
        $team = contractTeam();

        contractWithdraw($this, $team, data: ['name' => '   ', 'email' => 'ada@example.test'])->assertUnprocessable()->assertJsonValidationErrors('name');
        contractWithdraw($this, $team, data: ['name' => 'Ada', 'email' => 'not an address'])->assertUnprocessable()->assertJsonValidationErrors('email');
        contractWithdraw($this, $team, data: ['name' => "Ada\nLovelace", 'email' => 'ada@example.test'])->assertUnprocessable()->assertJsonValidationErrors('name');
        expect(BillingWithdrawal::query()->count())->toBe(0);

        contractWithdraw($this, $team, data: ['name' => '  Ada Lovelace  ', 'email' => '  ADA@Example.TEST '])->assertCreated();

        expect(BillingWithdrawal::query()->sole()->only(['name', 'email']))->toBe(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
    });

    it('refuses what is not a live subscription: none, an ended one, or one with no known start', function (string $case): void {
        Queue::fake();
        $team = contractTeam();

        match ($case) {
            'ended' => $team['stripe']->setStatus($team['subscription'], 'canceled'),
            'incomplete' => $team['stripe']->setStatus($team['subscription'], 'incomplete'),
            'unknown start' => $team['stripe']->change($team['subscription'], ['start_date' => null]),
        };

        contractWithdraw($this, $team)->assertStatus(409)->assertJsonPath('code', 'no_live_subscription');

        expect(BillingWithdrawal::query()->count())->toBe(0);
    })->with(['ended', 'incomplete', 'unknown start']);

    it('has nothing to withdraw from for a workspace that never went to Checkout, and on an instance that sells nothing', function (): void {
        $stripe = billingOn();
        $team = inventoryTeam();

        contractWithdraw($this, $team)->assertStatus(409)->assertJsonPath('code', 'no_billing_account');
        expect($stripe->calls)->toBe([]);

        billingOff();
        Http::fake();
        contractWithdraw($this, $team)->assertStatus(409)->assertJsonPath('code', 'billing_disabled');
    });

    it('is withdrawn from once: a second declaration of the same subscription is refused', function (): void {
        Queue::fake();
        Mail::fake();
        contractAdmin();
        $team = contractTeam();

        contractWithdraw($this, $team)->assertCreated();
        // The subscription is still live (the job is only queued): the second declaration is the same contract.
        contractWithdraw($this, $team)->assertStatus(409)->assertJsonPath('code', 'already_withdrawn');

        expect(BillingWithdrawal::query()->count())->toBe(1);
        Queue::assertPushed(CancelStripeSubscription::class, 1);
        Mail::assertQueued(WithdrawalAcknowledgedMail::class, 1);
        Mail::assertQueued(BillingAlertMail::class, 1);
    });

    it('is refused as having no live subscription once the cancellation went through', function (): void {
        contractAdmin();
        $team = contractTeam();

        contractWithdraw($this, $team)->assertCreated();
        contractWithdraw($this, $team)->assertStatus(409)->assertJsonPath('code', 'no_live_subscription');

        expect(BillingWithdrawal::query()->count())->toBe(1);
    });

    it('records one declaration only when two arrive at the same moment', function (): void {
        Queue::fake();
        $team = contractTeam();
        $service = app(Withdrawals::class);
        $invoice = ['id' => 'in_1', 'amount_paid' => 600, 'currency' => 'eur'];

        $service->record($team['workspace'], $team['owner'], 'Ada', 'ada@example.test', $team['subscription'], $invoice);

        // The second request judged the account before the first was stored, and gets to the lock after it.
        expect(fn () => $service->record($team['workspace'], $team['owner'], 'Ada', 'ada@example.test', $team['subscription'], $invoice))
            ->toThrow(fn (WithdrawalRefusedException $refused) => expect($refused->reason)->toBe('already_withdrawn'))
            ->and(BillingWithdrawal::query()->count())->toBe(1);
    });

    it('judges the window again under the lock, and the subscription it was asked about', function (): void {
        Queue::fake();
        $team = contractTeam();
        $service = app(Withdrawals::class);

        // The subscription the request was judged on has been replaced by another since.
        expect(fn () => $service->record($team['workspace'], $team['owner'], 'Ada', 'ada@example.test', 'sub_other', null))
            ->toThrow(fn (WithdrawalRefusedException $refused) => expect($refused->reason)->toBe('no_live_subscription'));

        // The fourteen days ran out between the judgement and the lock.
        $team['account']->forceFill(['started_at' => now()->subDays(14)->subSecond()])->save();

        expect(fn () => $service->record($team['workspace'], $team['owner'], 'Ada', 'ada@example.test', $team['subscription'], null))
            ->toThrow(fn (WithdrawalRefusedException $refused) => expect($refused->reason)->toBe('withdrawal_period_over'))
            ->and(BillingWithdrawal::query()->count())->toBe(0);
    });

    it('does not block the withdrawal when the invoice cannot be read: the amount is left to be checked', function (string $case): void {
        contractAdmin();
        $team = $case === 'no paid invoice' ? contractTeam(paid: null) : contractTeam();

        if ($case === 'a failure of Stripe') {
            $team['stripe']->failNext('GET /v1/invoices', 500);
        }

        contractWithdraw($this, $team)->assertCreated();

        $withdrawal = BillingWithdrawal::query()->sole();
        $admin = contractMailTo('admin@example.test', 'A customer withdrew from their contract');
        $ack = contractMailTo('ada@example.test', 'Acknowledgement of your withdrawal');

        expect($withdrawal->only(['amount_paid_cents', 'refund_cents', 'currency']))->toBe(['amount_paid_cents' => null, 'refund_cents' => null, 'currency' => null])
            ->and($admin)->toContain('Amount paid: amount to be checked')->toContain('Refund to make: amount to be checked')->toContain('Refund before: November 24, 2026')
            ->and($ack)->toContain('Your subscription ends now')->not->toContain('Amount to be refunded')
            ->and($team['stripe']->subscriptions[$team['subscription']]['status'])->toBe('canceled');
    })->with(['a failure of Stripe', 'no paid invoice']);

    it('asks for the invoice of the subscription outside any transaction, and only the paid ones', function (): void {
        Queue::fake();
        $team = contractTeam();
        $base = DB::transactionLevel();
        $level = null;
        $team['stripe']->onCall('GET /v1/invoices', function () use (&$level): void {
            $level = DB::transactionLevel();
        });

        contractWithdraw($this, $team)->assertCreated();

        parse_str((string) parse_url($team['stripe']->requestsOf('GET /v1/invoices')[0]->url(), PHP_URL_QUERY), $query);

        expect($level)->toBe($base)
            ->and($query)->toBe(['subscription' => $team['subscription'], 'status' => 'paid', 'limit' => '1']);
    });

    it('rounds the days used up and keeps the whole amount when the period is over, whichever the interval', function (int $secondsAgo, string $interval, int $paid, int $refund): void {
        Queue::fake();
        Mail::fake();
        $team = contractTeam($interval, $secondsAgo, $paid);

        contractWithdraw($this, $team)->assertCreated();

        expect(BillingWithdrawal::query()->sole()->refund_cents)->toBe($refund);
    })->with([
        'a second after the start: a whole day is used' => [1, 'month', 3000, 3000 - 100],
        'exactly one day' => [86400, 'month', 3000, 3000 - 100],
        'a second into the second day' => [86401, 'month', 3000, 3000 - 200],
        'a yearly subscription, three days in' => [3 * 86400, 'year', 36500, 36500 - 300],
        'an amount that does not divide: the share kept is rounded down' => [86400, 'month', 100, 97],
    ]);

    it('computes what is to be refunded from the days of the period, rounded up, at most all of them', function (): void {
        $start = CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC');
        $end = $start->addDays(30);

        expect(Withdrawals::refundCents(3000, $start, $end, $start))->toBe(3000)
            ->and(Withdrawals::refundCents(3000, $start, $end, $start->addSecond()))->toBe(2900)
            ->and(Withdrawals::refundCents(3000, $start, $end, $start->addDays(14)))->toBe(1600)
            ->and(Withdrawals::refundCents(3000, $start, $end, $end->addDays(5)))->toBe(0)
            ->and(Withdrawals::refundCents(3000, $start, $end, $start->subDay()))->toBe(3000)
            ->and(Withdrawals::days($start, $end, $start->addHours(30)))->toBe(['used' => 2, 'total' => 30]);
    });

    it('still ends the subscription when the declaration was made while Stripe could not be read', function (): void {
        Queue::fake();
        $team = contractTeam();
        $team['stripe']->failNext('GET /v1/subscriptions', 503);

        contractWithdraw($this, $team)->assertCreated();

        // The stored subscription was judged; the job that cancels it comes back to Stripe by itself.
        Queue::assertPushed(CancelStripeSubscription::class, fn (CancelStripeSubscription $job): bool => $job->customerId === $team['account']->stripe_customer_id && ! $job->atPeriodEnd && $job->billingAccountId === $team['account']->id && $job->workspaceUlid === $team['workspace']->ulid);
        expect(BillingWithdrawal::query()->count())->toBe(1);
    });

    it('tells the administrators when the subscription cannot be cancelled, and keeps the declaration and the acknowledgement', function (int $status): void {
        contractAdmin();
        $team = contractTeam();
        $team['stripe']->failNext('DELETE /v1/subscriptions/{id}', $status, 5);

        contractWithdraw($this, $team)->assertCreated();

        expect(BillingWithdrawal::query()->count())->toBe(1)
            ->and(contractMailTo('ada@example.test', 'Acknowledgement of your withdrawal'))->toContain('Your subscription ends now')
            ->and(contractMailTo('admin@example.test', 'A subscription could not be cancelled'))->toContain('Stripe customer: '.$team['account']->stripe_customer_id)
            ->and(contractMailTo('admin@example.test', 'A customer withdrew from their contract'))->toContain('Refund to make: 5.40 EUR');
    })->with([
        'a refusal that will be the same next time' => [400],
        'a Stripe that fails' => [500],
    ]);

    it('tells the administrators when the cancellation cannot even be queued', function (): void {
        contractAdmin();
        $team = contractTeam();
        Queue::shouldReceive('push')->andThrow(new RuntimeException('The queue is down.'));

        // Only the job is lost with the queue: the mails below go out through the mailer that is not queued here.
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('locale')->andReturnSelf();
        Mail::shouldReceive('queue')->andReturn(null);

        $logged = [];
        Log::shouldReceive('error')->andReturnUsing(function (string $message) use (&$logged): void {
            $logged[] = $message;
        });
        Log::shouldReceive('warning', 'info')->andReturnNull();

        contractWithdraw($this, $team)->assertCreated();

        expect($logged)->toContain('The cancellation of a subscription that was withdrawn from could not be queued: cancel it in the dashboard of Stripe.')
            ->and(BillingWithdrawal::query()->count())->toBe(1);
    });

    it('stores the declaration and tells the administrators when the acknowledgement cannot be sent', function (): void {
        contractAdmin();
        $team = contractTeam();
        billingMailBreaksFor(['ada@example.test']);

        contractWithdraw($this, $team)->assertCreated();

        $withdrawal = BillingWithdrawal::query()->sole();
        $alert = contractMailTo('admin@example.test', 'A customer withdrew from their contract');

        expect($withdrawal->acknowledged_at)->toBeNull()
            ->and($team['stripe']->subscriptions[$team['subscription']]['status'])->toBe('canceled')
            ->and($alert)->toContain('The acknowledgement could NOT be sent to the customer: write to them.')
            ->and($alert)->toContain('Refund to make: 5.40 EUR');
    });

    it('is paced: five a minute for each person', function (): void {
        Queue::fake();
        Mail::fake();
        $team = contractTeam();

        for ($i = 0; $i < 5; $i++) {
            contractWithdraw($this, $team, data: ['name' => '', 'email' => ''])->assertUnprocessable();
        }

        contractWithdraw($this, $team)->assertStatus(429)->assertHeader('Retry-After');
    });

    it('keeps the proof when the workspace and then the account are deleted', function (): void {
        Queue::fake();
        Mail::fake();
        $team = contractTeam();
        contractWithdraw($this, $team)->assertCreated();

        $team['workspace']->delete();

        $afterWorkspace = BillingWithdrawal::query()->sole();
        expect($afterWorkspace->workspace_id)->toBeNull()->and($afterWorkspace->user_id)->toBe($team['owner']->id);

        $team['owner']->delete();

        $afterAccount = BillingWithdrawal::query()->sole();
        expect($afterAccount->user_id)->toBeNull()
            ->and($afterAccount->only(['name', 'email', 'stripe_customer_id', 'stripe_subscription_id', 'amount_paid_cents', 'refund_cents']))->toBe([
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.test',
                'stripe_customer_id' => $team['account']->stripe_customer_id,
                'stripe_subscription_id' => $team['subscription'],
                'amount_paid_cents' => 600,
                'refund_cents' => 540,
            ]);
    });
});

describe('cancelling the contract', function (): void {
    /**
     * Asks for the portal page of the cancellation as a person at their browser.
     */
    function contractCancel(object $test, array $team, ?User $as = null): TestResponse
    {
        return inventoryCall($test, $as ?? $team['owner'], 'POST', billingApiUri($team['workspace'], 'cancel'));
    }

    it('opens the customer portal straight on the cancellation of the subscription and answers with its page', function (): void {
        $team = contractTeam();
        $base = DB::transactionLevel();
        $level = null;
        $team['stripe']->onCall('POST /v1/billing_portal/sessions', function () use (&$level): void {
            $level = DB::transactionLevel();
        });
        $page = rtrim(config()->string('app.url'), '/').'/app/w/'.$team['workspace']->ulid.'/billing';

        $answer = contractCancel($this, $team)->assertOk();
        $portal = array_values($team['stripe']->portals)[0];

        expect($answer->json())->toBe(['data' => ['url' => 'https://billing.stripe.com/session/'.$portal['id']]])
            // The subscription is read first: what is cancelled is the one that is live now; no call inside a transaction.
            ->and($team['stripe']->calls)->toBe(['GET /v1/subscriptions', 'POST /v1/billing_portal/sessions'])
            ->and($level)->toBe($base)
            ->and($portal)->toMatchArray([
                'customer' => $team['account']->stripe_customer_id,
                'return_url' => $page,
                'locale' => 'en',
                // The deep link to the cancellation of this subscription, and back to the page once it is done.
                'flow_data' => [
                    'type' => 'subscription_cancel',
                    'subscription_cancel' => ['subscription' => $team['subscription']],
                    'after_completion' => ['type' => 'redirect', 'redirect' => ['return_url' => $page.'?cancellation=done']],
                ],
            ]);
    });

    it('is for the owner who pays: a member is refused, a stranger is told the workspace is not there, another owner has no say over the payer subscription', function (): void {
        $team = contractTeam();
        $second = inventoryPerson('Second owner');
        $team['workspace']->members()->attach($second->id, ['role' => 'owner']);

        contractCancel($this, $team, $team['editor'])->assertForbidden();
        contractCancel($this, $team, $team['viewer'])->assertForbidden();
        contractCancel($this, $team, $team['stranger'])->assertNotFound();
        contractCancel($this, $team, $second)->assertStatus(409)->assertJsonPath('code', 'previous_owner_subscription');

        expect($team['stripe']->count('POST /v1/billing_portal/sessions'))->toBe(0);
    });

    it('has nothing to cancel when no subscription is live, and says when the cancellation is already scheduled', function (): void {
        $team = contractTeam();
        $team['stripe']->setStatus($team['subscription'], 'canceled');
        contractCancel($this, $team)->assertStatus(409)->assertJsonPath('code', 'no_live_subscription');

        $team = contractTeam();
        $team['stripe']->change($team['subscription'], ['cancel_at_period_end' => true]);
        contractCancel($this, $team)->assertStatus(409)->assertJsonPath('code', 'cancellation_scheduled');
        expect($team['stripe']->count('POST /v1/billing_portal/sessions'))->toBe(0);
    });

    it('has nothing to open for a workspace that never went to Checkout, or on an instance that sells nothing', function (): void {
        $stripe = billingOn();
        $team = inventoryTeam();

        contractCancel($this, $team)->assertStatus(409)->assertJsonPath('code', 'no_billing_account');
        expect($stripe->calls)->toBe([]);

        billingOff();
        Http::fake();
        contractCancel($this, $team)->assertStatus(409)->assertJsonPath('code', 'billing_disabled');
    });

    it('answers that the payment service is unavailable when Stripe fails or refuses the session', function (string $call): void {
        $team = contractTeam();
        $team['stripe']->failNext($call, $call === 'GET /v1/subscriptions' ? 500 : 400);

        contractCancel($this, $team)->assertStatus(503)->assertJsonPath('code', 'billing_unavailable');
    })->with(['GET /v1/subscriptions', 'POST /v1/billing_portal/sessions']);

    it('is paced: ten a minute for each person', function (): void {
        $team = contractTeam();

        for ($i = 0; $i < 10; $i++) {
            contractCancel($this, $team)->assertOk();
        }

        contractCancel($this, $team)->assertStatus(429)->assertHeader('Retry-After');
    });
});

describe('the overview of a contract', function (): void {
    it('tells when the subscription began and until when it can be withdrawn from', function (): void {
        $team = contractTeam();

        inventoryCall($this, $team['editor'], 'GET', billingApiUri($team['workspace']))
            ->assertOk()
            ->assertJsonPath('data.selling', true)
            ->assertJsonPath('data.started_at', '2026-11-07T14:30:00+00:00')
            ->assertJsonPath('data.withdrawal_until', '2026-11-21T14:30:00+00:00')
            ->assertJsonPath('data.withdrawn', false);
    });

    it('stops offering the withdrawal after the fourteen days, after it was made, and when nothing is live', function (string $case): void {
        Queue::fake();
        Mail::fake();
        $team = match ($case) {
            'day 15' => contractTeam(secondsAgo: 14 * 86400 + 1),
            default => contractTeam(),
        };

        if ($case === 'withdrawn') {
            contractWithdraw($this, $team)->assertCreated();
        }

        if ($case === 'ended') {
            $team['account']->forceFill(['status' => 'canceled'])->save();
        }

        $data = inventoryCall($this, $team['owner'], 'GET', billingApiUri($team['workspace']))->assertOk()->json('data');

        expect($data['withdrawal_until'])->toBeNull()->and($data['withdrawn'])->toBe($case === 'withdrawn');
    })->with(['day 15', 'withdrawn', 'ended']);

    it('says a plan cannot be bought yet when Stripe is set up but the seller is not complete', function (): void {
        $team = contractTeam();
        billingSeller('vat_number');

        inventoryCall($this, $team['owner'], 'GET', billingApiUri($team['workspace']))
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.selling', false);
    });

    it('reads nothing about a contract while billing is off', function (): void {
        billingOff();
        $team = inventoryTeam();
        DB::flushQueryLog();
        DB::enableQueryLog();

        inventoryCall($this, $team['owner'], 'GET', billingApiUri($team['workspace']))
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.selling', false)
            ->assertJsonPath('data.started_at', null)
            ->assertJsonPath('data.withdrawn', false);

        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        expect($sql)->not->toContain('billing_withdrawals')->not->toContain('billing_accounts');
    });
});
