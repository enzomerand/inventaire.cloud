<?php

declare(strict_types=1);

use App\Billing\BillingSync;
use App\Billing\Plan;
use App\Billing\WorkspacePlan;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Jobs\SyncBillingAccount;
use App\Mail\BillingAlertMail;
use App\Models\BillingAccount;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeStripe;

require_once __DIR__.'/../../Support/billing.php';

/*
 * `POST /webhooks/stripe`: Stripe saying that something happened to a customer. It proves who it is by the signature of its body, is
 * believed about nothing, wakes up a synchronisation, and warns the administrators when the event is a risk.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->useLanguage('en');
});

/**
 * Calls the webhook the way Stripe does: the raw body and its signature in a header.
 *
 * @param  array<string, mixed>|string  $body  The event, or the raw body.
 * @param  array<string, string>|null  $headers  The headers; the right signature of the body when null.
 */
function billingWebhook(object $test, array|string $body, ?array $headers = null, string $ip = '127.0.0.1'): TestResponse
{
    $raw = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : $body;
    $headers ??= ['Stripe-Signature' => billingSign($raw)];
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => '*/*', 'REMOTE_ADDR' => $ip];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call('POST', '/webhooks/stripe', [], [], [], $server, $raw);
}

/**
 * An event about a customer, as Stripe posts it.
 *
 * @param  array<string, mixed>  $object
 * @return array<string, mixed>
 */
function billingEventFor(string $type, ?string $customer, array $object = []): array
{
    return FakeStripe::event($type, $object + ['customer' => $customer]);
}

/**
 * The accounts the jobs of the queue are about.
 *
 * @return list<int>
 */
function billingQueued(): array
{
    return Queue::pushed(SyncBillingAccount::class)->map(fn (SyncBillingAccount $job): int => $job->billingAccountId)->values()->all();
}

/**
 * An event of a dispute or of an early fraud warning.
 *
 * @param  array<string, mixed>  $object
 * @return array<string, mixed>
 */
function billingRisk(string $type, array $object = [], bool $livemode = true, ?string $id = null): array
{
    $event = FakeStripe::event($type, $object, $id);
    $event['livemode'] = $livemode;

    return $event;
}

/**
 * An administrator of the instance, who reads English unless told otherwise.
 */
function billingAdmin(string $email = 'admin@example.test', ?string $locale = 'en'): User
{
    return User::factory()->admin()->create(['email' => $email, 'locale' => $locale]);
}

describe('who may call it', function (): void {
    it('does not exist while billing is off, or while the instance has no signing secret', function (callable $setUp): void {
        $setUp();
        $account = BillingAccount::factory()->create();

        $response = billingWebhook($this, billingEventFor('invoice.paid', $account->stripe_customer_id));

        $response->assertNotFound()->assertJsonPath('message', 'Not found.');
        expect($response->headers->get('Content-Type'))->toContain('application/json');
        Queue::assertNothingPushed();
    })->with([
        'billing off' => [fn () => billingOff()],
        'billing off, a signing secret left' => [function (): void {
            billingOff();
            config(['services.stripe.webhook_secret' => FakeStripe::WEBHOOK_SECRET]);
        }],
        'no signing secret' => [fn () => billingOn(['services.stripe.webhook_secret' => null])],
        'a blank signing secret' => [fn () => billingOn(['services.stripe.webhook_secret' => '   '])],
        'no secret key' => [fn () => billingOn(['services.stripe.secret' => null])],
    ]);

    it('refuses a call without a valid signature: 400 in JSON, with a code', function (callable $headers): void {
        billingOn();
        $account = BillingAccount::factory()->create();
        $body = json_encode(billingEventFor('invoice.paid', $account->stripe_customer_id), JSON_THROW_ON_ERROR);

        $response = billingWebhook($this, $body, $headers($body));

        $response->assertStatus(400)->assertExactJson(['message' => 'The signature is missing or wrong.', 'code' => 'invalid_signature']);
        expect($response->headers->get('Content-Type'))->toContain('application/json');
        Queue::assertNotPushed(SyncBillingAccount::class);
    })->with([
        'no header' => [fn (string $body): array => []],
        'an empty header' => [fn (string $body): array => ['Stripe-Signature' => '']],
        'garbage' => [fn (string $body): array => ['Stripe-Signature' => 'nope']],
        'another secret' => [fn (string $body): array => ['Stripe-Signature' => billingSign($body, 'whsec_another')]],
        'a signature of another body' => [fn (string $body): array => ['Stripe-Signature' => billingSign($body.' ')]],
        'a signature that is too old' => [fn (string $body): array => ['Stripe-Signature' => billingSign($body, null, time() - 301)]],
        'a signature from the future' => [fn (string $body): array => ['Stripe-Signature' => billingSign($body, null, time() + 301)]],
        'a signature of the test scheme only' => [fn (string $body): array => ['Stripe-Signature' => str_replace(',v1=', ',v0=', billingSign($body))]],
        'the signature in another header' => [fn (string $body): array => ['X-Stripe-Signature' => billingSign($body)]],
    ]);

    it('accepts a signature made in the last five minutes, and one of several that a rotation of the secret gives', function (): void {
        billingOn();
        $account = BillingAccount::factory()->create();
        $body = json_encode(billingEventFor('invoice.paid', $account->stripe_customer_id), JSON_THROW_ON_ERROR);
        $timestamp = time();
        $good = substr(billingSign($body, null, $timestamp), strlen("t={$timestamp},"));
        // During a rotation of the secret Stripe signs with the old one and the new one: any of the signatures may be the right one.
        $rotated = "t={$timestamp},v1=".str_repeat('0', 64).",{$good}";

        billingWebhook($this, $body, ['Stripe-Signature' => billingSign($body, null, time() - 299)])->assertOk();
        billingWebhook($this, $body, ['Stripe-Signature' => $rotated])->assertOk();
        // The second event is the same customer's, whose synchronisation is waiting already: one job.
        expect(billingQueued())->toBe([$account->id]);
    });

    it('is a POST: nothing else is answered', function (): void {
        billingOn();

        $this->getJson('/webhooks/stripe')->assertStatus(405);
    });

    it('refuses a body that is signed and is not JSON, or is not an object', function (string $body): void {
        billingOn();

        $response = billingWebhook($this, $body);

        $response->assertStatus(400)->assertExactJson(['message' => 'The body is not valid JSON.', 'code' => 'invalid_body']);
        Queue::assertNothingPushed();
    })->with(['text' => ['not json'], 'empty' => [''], 'a number' => ['12'], 'a string' => ['"invoice.paid"'], 'null' => ['null'], 'cut short' => ['{"type": "invoice.paid", ']]);

    it('answers in the language the call asks for', function (): void {
        billingOn();

        billingWebhook($this, 'x', ['Accept-Language' => 'fr', 'Stripe-Signature' => 'nope'])->assertStatus(400)->assertJsonPath('message', 'La signature est absente ou erronée.');
        billingWebhook($this, 'nope', ['Accept-Language' => 'fr', 'Stripe-Signature' => billingSign('nope')])->assertStatus(400)->assertJsonPath('message', 'Le corps de la requête n\'est pas du JSON valide.');

        billingOff();

        billingWebhook($this, '{}', ['Accept-Language' => 'fr'])->assertNotFound()->assertJsonPath('message', 'Introuvable.');
    });
});

describe('the size of the call', function (): void {
    it('refuses a body larger than a megabyte, with a 413 in JSON, even signed', function (): void {
        billingOn();
        $event = billingEventFor('invoice.paid', BillingAccount::factory()->create()->stripe_customer_id);
        $event['padding'] = str_repeat('a', StripeWebhookController::MAX_BYTES);

        $response = billingWebhook($this, $event);

        $response->assertStatus(413)->assertJsonPath('message', 'The body is too large.');
        Queue::assertNotPushed(SyncBillingAccount::class);
    });

    it('refuses on the length it is announced with, without reading the body', function (): void {
        billingOn();

        $response = $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) (StripeWebhookController::MAX_BYTES + 1),
        ], '{}');

        $response->assertStatus(413);
    });

    it('reads a body of exactly a megabyte', function (): void {
        billingOn();
        $account = BillingAccount::factory()->create();
        $event = billingEventFor('invoice.paid', $account->stripe_customer_id);
        $event['padding'] = '';
        $event['padding'] = str_repeat('a', StripeWebhookController::MAX_BYTES - strlen(json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        expect(strlen($body))->toBe(StripeWebhookController::MAX_BYTES);

        billingWebhook($this, $body)->assertOk();
        expect(billingQueued())->toBe([$account->id]);
    });
});

describe('what it does with an event', function (): void {
    it('queues the synchronisation of the account of the customer, for every event that means "read it again"', function (string $type, array $object): void {
        billingOn();
        $account = BillingAccount::factory()->create();
        $other = BillingAccount::factory()->create();

        $response = billingWebhook($this, FakeStripe::event($type, $object + ['customer' => $account->stripe_customer_id]));

        $response->assertOk()->assertExactJson(['received' => true]);
        expect($response->headers->get('Content-Type'))->toContain('application/json')
            ->and(billingQueued())->toBe([$account->id])
            ->and($other->id)->not->toBe($account->id);
    })->with([
        'a checkout completed' => ['checkout.session.completed', ['object' => 'checkout.session', 'id' => 'cs_test_1', 'mode' => 'subscription']],
        'a subscription created' => ['customer.subscription.created', ['object' => 'subscription', 'id' => 'sub_1', 'status' => 'active']],
        'a subscription updated' => ['customer.subscription.updated', ['object' => 'subscription', 'id' => 'sub_1', 'status' => 'past_due']],
        'a subscription deleted' => ['customer.subscription.deleted', ['object' => 'subscription', 'id' => 'sub_1', 'status' => 'canceled']],
        'a subscription paused' => ['customer.subscription.paused', ['object' => 'subscription', 'id' => 'sub_1', 'status' => 'paused']],
        'a subscription resumed' => ['customer.subscription.resumed', ['object' => 'subscription', 'id' => 'sub_1', 'status' => 'active']],
        'an invoice paid' => ['invoice.paid', ['object' => 'invoice', 'id' => 'in_1']],
        'an invoice that failed to be paid' => ['invoice.payment_failed', ['object' => 'invoice', 'id' => 'in_1']],
        'a charge refunded' => ['charge.refunded', ['object' => 'charge', 'id' => 'ch_1', 'amount_refunded' => 600]],
    ]);

    it('does not look at the mode of the event, nor at its age or id', function (bool $livemode): void {
        billingOn();
        $account = BillingAccount::factory()->create();
        $event = billingEventFor('invoice.paid', $account->stripe_customer_id);
        $event['livemode'] = $livemode;
        $event['created'] = 1;
        unset($event['id']);

        billingWebhook($this, $event)->assertOk();

        expect(billingQueued())->toBe([$account->id]);
    })->with([true, false]);

    it('does nothing for the events it does not follow', function (string $type): void {
        Mail::fake();
        billingOn();
        $account = BillingAccount::factory()->create();

        $response = billingWebhook($this, billingEventFor($type, $account->stripe_customer_id));

        $response->assertOk()->assertExactJson(['received' => true]);
        Queue::assertNothingPushed();
        Mail::assertNothingQueued();
    })->with([
        'customer.created', 'customer.updated', 'customer.deleted', 'customer.subscription.trial_will_end', 'invoice.created', 'invoice.finalized',
        'payment_intent.succeeded', 'charge.succeeded', 'charge.dispute.closed', 'charge.dispute.updated', 'radar.early_fraud_warning.updated',
        'checkout.session.expired', 'Invoice.Paid', 'invoice.paid ', '', 'something.new',
    ]);

    it('answers 200 all the same for what it cannot make sense of, and does nothing', function (array $event): void {
        billingOn();
        BillingAccount::factory()->create();

        billingWebhook($this, $event)->assertOk()->assertExactJson(['received' => true]);

        Queue::assertNothingPushed();
    })->with([
        'no type' => [['id' => 'evt_1', 'data' => ['object' => ['customer' => 'cus_x']]]],
        'a type that is not text' => [['type' => ['invoice.paid'], 'data' => ['object' => ['customer' => 'cus_x']]]],
        'no data' => [['type' => 'invoice.paid']],
        'data that is not an object' => [['type' => 'invoice.paid', 'data' => 'cus_x']],
        'an object that is not an object' => [['type' => 'invoice.paid', 'data' => ['object' => 'cus_x']]],
        'an empty list' => [[]],
        'a list' => [[1, 2, 3]],
    ]);

    it('ignores a customer nobody knows, a missing one, and one that is not an id, writing the first in the log', function (mixed $customer): void {
        Log::spy();
        billingOn();
        BillingAccount::factory()->create(['stripe_customer_id' => 'cus_known']);

        billingWebhook($this, billingEventFor('invoice.paid', null, ['customer' => $customer]))->assertOk()->assertExactJson(['received' => true]);

        Queue::assertNothingPushed();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'customer this instance does not know') && $context === ['type' => 'invoice.paid'])->once();
    })->with([
        'a customer of another site' => ['cus_stranger'],
        'no customer (a charge of a guest)' => [null],
        'a customer already expanded' => [['id' => 'cus_known', 'object' => 'customer']],
        'a number' => [12],
        'an id with something else in it' => ["cus_known'; drop table billing_accounts; --"],
        'an id of another case' => ['CUS_KNOWN'],
        'the start of a known id' => ['cus_know'],
        'an id too long' => [str_repeat('c', 300)],
    ]);

    it('is a call to a queue that is down, answered all the same, and written in the log', function (): void {
        Log::spy();
        billingOn();
        $account = BillingAccount::factory()->create();
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('The queue is down.')));

        billingWebhook($this, billingEventFor('invoice.paid', $account->stripe_customer_id))->assertOk()->assertExactJson(['received' => true]);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'could not be followed'))->once();
    });
});

describe('what Stripe says is never believed', function (): void {
    it('does not make a workspace Pro because the call says so: the subscriptions are read again', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        // The call claims an active subscription; the API has none.
        $event = billingEventFor('customer.subscription.created', $account->stripe_customer_id, ['id' => 'sub_forged', 'status' => 'active', 'items' => ['data' => [['price' => ['id' => 'price_monthly_test']]]]]);

        billingWebhook($this, $event)->assertOk();
        Queue::pushed(SyncBillingAccount::class)->each(fn (SyncBillingAccount $job) => $job->handle(app(BillingSync::class)));

        expect(WorkspacePlan::for($account->workspace))->toBe(Plan::Free)
            ->and(BillingAccount::query()->findOrFail($account->id)->stripe_subscription_id)->toBeNull()
            ->and($stripe->count('GET /v1/subscriptions'))->toBe(1);
    });

    it('does not make a workspace Free because the call says so either', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');
        $event = billingEventFor('customer.subscription.deleted', $account->stripe_customer_id, ['status' => 'canceled']);

        billingWebhook($this, $event)->assertOk();
        Queue::pushed(SyncBillingAccount::class)->each(fn (SyncBillingAccount $job) => $job->handle(app(BillingSync::class)));

        expect(WorkspacePlan::for($account->workspace))->toBe(Plan::Pro);
    });

    it('turns the workspace Pro when the API agrees', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe);
        $stripe->subscribe($account->stripe_customer_id, 'active');

        billingWebhook($this, billingEventFor('checkout.session.completed', $account->stripe_customer_id))->assertOk();
        Queue::pushed(SyncBillingAccount::class)->each(fn (SyncBillingAccount $job) => $job->handle(app(BillingSync::class)));

        expect(WorkspacePlan::for($account->workspace))->toBe(Plan::Pro);
    });

    it('calls Stripe for nothing itself: it is fast, the job reads', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');

        billingWebhook($this, billingEventFor('invoice.paid', $account->stripe_customer_id))->assertOk();

        expect($stripe->calls)->toBe([]);
    });
});

describe('a risk event', function (): void {
    beforeEach(function (): void {
        Mail::fake();
        billingOn();
    });

    it('is told by mail to every administrator, one mail each, and to nobody else', function (): void {
        $first = billingAdmin('first@example.test');
        $second = billingAdmin('second@example.test');
        $member = User::factory()->create(['email' => 'member@example.test', 'locale' => 'en']);

        $response = billingWebhook($this, billingRisk('charge.dispute.created', ['id' => 'du_123', 'amount' => 1250, 'currency' => 'eur', 'charge' => 'ch_1']));

        $response->assertOk()->assertExactJson(['received' => true]);
        Mail::assertQueued(BillingAlertMail::class, 2);
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->hasTo('first@example.test') && ! $mail->hasTo('second@example.test'));
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->hasTo('second@example.test') && ! $mail->hasTo('first@example.test'));
        Mail::assertNotQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->hasTo('member@example.test'));
        Queue::assertNothingPushed();
        expect([$first->is_admin, $second->is_admin, $member->is_admin])->toBe([true, true, false]);
    });

    it('says what it is, the reference, the amount and where to look at it', function (string $type, string $page, string $label): void {
        billingAdmin();

        billingWebhook($this, billingRisk($type, ['id' => 'obj_123', 'amount' => 1250, 'currency' => 'eur']))->assertOk();

        Mail::assertQueued(BillingAlertMail::class, function (BillingAlertMail $mail) use ($type, $page, $label): bool {
            $html = $mail->render();

            return $mail->eventType === $type
                && $mail->dashboardUrl() === "https://dashboard.stripe.com/{$page}/obj_123"
                && str_contains($html, $label)
                && str_contains($html, 'obj_123')
                && str_contains($html, '12.50 EUR')
                && str_contains($html, "https://dashboard.stripe.com/{$page}/obj_123")
                && ! str_contains($html, 'test mode');
        });
    })->with([
        'a dispute' => ['charge.dispute.created', 'disputes', 'A customer disputed a payment'],
        'an early fraud warning' => ['radar.early_fraud_warning.created', 'radar/early-fraud-warnings', 'Stripe received an early fraud warning'],
    ]);

    it('opens the test mode of the dashboard for an event of the test mode, and says so', function (): void {
        billingAdmin();

        billingWebhook($this, billingRisk('charge.dispute.created', ['id' => 'du_test'], livemode: false))->assertOk();

        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->dashboardUrl() === 'https://dashboard.stripe.com/test/disputes/du_test'
            && str_contains($mail->render(), 'test mode'));
    });

    it('has the plain text and the HTML version, the same in substance', function (): void {
        billingAdmin();

        billingWebhook($this, billingRisk('charge.dispute.created', ['id' => 'du_1', 'amount' => 600, 'currency' => 'eur']))->assertOk();

        Mail::assertQueued(BillingAlertMail::class, function (BillingAlertMail $mail): bool {
            $mail->assertSeeInHtml('du_1')->assertSeeInHtml('<a href="https://dashboard.stripe.com/disputes/du_1"', false);
            $mail->assertSeeInText('du_1')->assertSeeInText('6.00 EUR')->assertSeeInText('https://dashboard.stripe.com/disputes/du_1');

            return true;
        });
    });

    it('is written in the language of each administrator, the one of the instance for those who have none', function (): void {
        billingAdmin('english@example.test', 'en');
        billingAdmin('default@example.test', null);

        billingWebhook($this, billingRisk('charge.dispute.created', ['id' => 'du_1']))->assertOk();

        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->hasTo('english@example.test') && $mail->locale === 'en');
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->hasTo('default@example.test') && $mail->locale === config('app.default_locale'));
    });

    it('renders in French', function (): void {
        $mail = new BillingAlertMail('charge.dispute.created', 'du_1', 600, 'eur', false);

        $html = $mail->locale('fr')->render();

        expect($html)->toContain('Un client a contesté un paiement')->toContain('Cette alerte vient du mode test de Stripe.')->toContain('Montant : 6.00 EUR');

        $this->useLanguage('fr');

        expect($mail->envelope()->subject)->toContain('Alerte Stripe sur')->toContain('Un client a contesté un paiement');
    });

    it('tells the administrators once, however many times Stripe sends the event', function (): void {
        billingAdmin();
        $event = billingRisk('charge.dispute.created', ['id' => 'du_1'], id: 'evt_same_one');

        billingWebhook($this, $event)->assertOk();
        billingWebhook($this, $event)->assertOk();
        billingWebhook($this, billingRisk('charge.dispute.created', ['id' => 'du_1'], id: 'evt_another'))->assertOk();

        Mail::assertQueued(BillingAlertMail::class, 2);
    });

    it('leaves out what is not of the shape it should: the reference becomes the page of its kind, the amount goes', function (array $object): void {
        billingAdmin();

        billingWebhook($this, billingRisk('charge.dispute.created', $object))->assertOk();

        Mail::assertQueued(BillingAlertMail::class, function (BillingAlertMail $mail): bool {
            $html = $mail->render();

            return $mail->objectId === null && $mail->amount === null && $mail->currency === null
                && $mail->dashboardUrl() === 'https://dashboard.stripe.com/disputes'
                && ! str_contains($html, 'Amount:') && ! str_contains($html, 'Stripe reference:') && ! str_contains($html, '<script');
        });
    })->with([
        'nothing' => [[]],
        'odd values' => [['id' => '<script>alert(1)</script>', 'amount' => '12.50', 'currency' => 'euros']],
        'an id with a slash' => [['id' => 'du_1/../../x', 'amount' => -5, 'currency' => 'EUR']],
        'an id too long' => [['id' => str_repeat('d', 65), 'amount' => 1.5, 'currency' => null]],
    ]);

    it('shows an amount in a currency without cents as it is', function (): void {
        $mail = new BillingAlertMail('charge.dispute.created', 'du_1', 5000, 'jpy', true);

        expect($mail->render())->toContain('5 000 JPY');
    });

    it('does not follow it as a subscription event, and needs no administrator to answer', function (): void {
        $account = BillingAccount::factory()->create();

        billingWebhook($this, billingRisk('charge.dispute.created', ['id' => 'du_1', 'customer' => $account->stripe_customer_id]))->assertOk()->assertExactJson(['received' => true]);

        Queue::assertNothingPushed();
        Mail::assertNothingQueued();
    });

    it('is answered all the same when the mail cannot be queued, and written in the log', function (): void {
        Log::spy();
        billingAdmin();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('The queue is down.'));

        billingWebhook($this, billingRisk('charge.dispute.created', ['id' => 'du_1']))->assertOk()->assertExactJson(['received' => true]);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'could not be followed'))->once();
    });

    it('does not lock the accounts to find the administrators', function (): void {
        billingAdmin();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        billingWebhook($this, billingRisk('charge.dispute.created', ['id' => 'du_1']))->assertOk();

        expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'for update') || str_contains($sql, 'for share')))->toBe([]);
    });
});

describe('the pace', function (): void {
    it('is slowed down after 120 refused calls a minute from one address, whatever it sends next, in JSON, and not the other addresses', function (): void {
        billingOn();
        $event = billingEventFor('invoice.paid', BillingAccount::factory()->create()->stripe_customer_id);

        for ($call = 1; $call <= StripeWebhookController::FAILURES_PER_MINUTE; $call++) {
            billingWebhook($this, $event, ['Stripe-Signature' => 'wrong'])->assertStatus(400);
        }

        $slowed = billingWebhook($this, $event);
        $slowedAgain = billingWebhook($this, $event, ['Stripe-Signature' => 'wrong']);
        $other = billingWebhook($this, $event, null, '203.0.113.9');

        $slowed->assertStatus(429)->assertJsonStructure(['message', 'code']);
        expect($slowed->headers->get('Content-Type'))->toContain('application/json')
            ->and($slowed->headers->has('Retry-After'))->toBeTrue()
            ->and($slowedAgain->status())->toBe(429)
            ->and($other->status())->toBe(200);
    });

    it('counts a body that is too large among the calls it refuses', function (): void {
        billingOn();

        for ($call = 1; $call <= StripeWebhookController::FAILURES_PER_MINUTE; $call++) {
            $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) (StripeWebhookController::MAX_BYTES + 1)], '{}')->assertStatus(413);
        }

        billingWebhook($this, '{}')->assertStatus(429);
    });

    it('counts only the calls it refuses: any number of calls that are signed is answered below the pace', function (): void {
        billingOn();
        $event = billingEventFor('invoice.paid', BillingAccount::factory()->create()->stripe_customer_id);

        for ($call = 1; $call <= 150; $call++) {
            billingWebhook($this, $event)->assertOk();
        }

        billingWebhook($this, $event, ['Stripe-Signature' => 'wrong'])->assertStatus(400);
    });

    it('stops a loop of signed calls after 1200 a minute from one address, and still refuses a forged one with a 400', function (): void {
        billingOn();
        $event = billingEventFor('invoice.paid', BillingAccount::factory()->create()->stripe_customer_id);

        for ($call = 1; $call <= StripeWebhookController::CALLS_PER_MINUTE; $call++) {
            RateLimiter::hit('stripe-webhook-calls:127.0.0.1', 60);
        }

        $slowed = billingWebhook($this, $event);

        $slowed->assertStatus(429)->assertJsonStructure(['message', 'code']);
        expect($slowed->headers->has('Retry-After'))->toBeTrue()
            ->and(billingWebhook($this, $event, ['Stripe-Signature' => 'wrong'])->status())->toBe(400)
            ->and(billingWebhook($this, $event, null, '203.0.113.9')->status())->toBe(200);
    });

    it('lets a new minute begin', function (): void {
        billingOn();
        $event = billingEventFor('invoice.paid', BillingAccount::factory()->create()->stripe_customer_id);

        for ($call = 1; $call <= StripeWebhookController::FAILURES_PER_MINUTE; $call++) {
            billingWebhook($this, $event, ['Stripe-Signature' => 'wrong'])->assertStatus(400);
        }

        billingWebhook($this, $event)->assertStatus(429);
        $this->travel(61)->seconds();

        billingWebhook($this, $event)->assertOk();
    });
});

describe('the route', function (): void {
    it('is named, a POST only, without session nor CSRF check, and paced by the controller (no throttle middleware counts every call)', function (): void {
        $route = Route::getRoutes()->getByName('webhooks.stripe');

        expect($route)->not->toBeNull()
            ->and($route->uri())->toBe('webhooks/stripe')
            ->and($route->methods())->toBe(['POST'])
            ->and($route->excludedMiddleware())->toContain(StartSession::class, PreventRequestForgery::class)
            ->and(collect($route->gatherMiddleware())->filter(fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle')))->toHaveCount(0)
            ->and(route('webhooks.stripe', [], false))->toBe('/webhooks/stripe');
    });

    it('sets no cookie and starts no session', function (): void {
        billingOn();

        $response = billingWebhook($this, billingEventFor('invoice.paid', 'cus_x'));

        $response->assertOk();
        expect($response->headers->getCookies())->toBe([]);
    });

    it('is answered in JSON before the installation too, as the webhooks of other services', function (): void {
        $this->bootInstaller(installed: false);

        $this->post('/webhooks/stripe')->assertStatus(503)->assertJsonPath('code', 'not_installed');
    });

    it('reads the signature with the verifier of the application, and the secret from the settings', function (): void {
        $source = (string) file_get_contents((new ReflectionClass(StripeWebhookController::class))->getFileName());

        expect($source)->toContain('WebhookSignature::verify($body,')->toContain('Billing::webhookSecret()');
    });
});
