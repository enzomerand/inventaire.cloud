<?php

declare(strict_types=1);

use App\Billing\Billing;
use App\Billing\BillingCustomers;
use App\Billing\Plan;
use App\Billing\StripeException;
use App\Billing\WorkspacePlan;
use App\Mail\BillingAlertMail;
use App\Models\BillingAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeStripe;

require_once __DIR__.'/../../Support/billing.php';
require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/*
 * The subscription of a workspace through the API (`/workspaces/{workspace}/billing`): any member reads the plan, the limits and the usage;
 * its owners go to Checkout and to the portal of Stripe and ask for the subscription to be read again. Stripe is the FakeStripe of the
 * tests, which answers like the real one and refuses any other request. A token never reaches these routes (the classification tests hold
 * that); here the web front's session does.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
    config([
        'billing.currency' => 'eur',
        'billing.amounts' => ['month' => 600, 'year' => 6000],
        'billing.plans.free.limits' => ['inventories' => 3, 'items' => 500, 'storage_bytes' => 200000, 'members' => 3, 'share_links' => 2],
        'billing.plans.free.features' => ['photo_entry' => false],
        'billing.plans.pro.limits' => ['inventories' => 100, 'items' => 10000, 'storage_bytes' => 10000000, 'members' => 50, 'share_links' => 20],
        'billing.plans.pro.features' => ['photo_entry' => true],
    ]);
});

/**
 * The address of the instance, as Stripe is told to bring people back to it.
 */
function billingApiRoot(): string
{
    return rtrim(config()->string('app.url'), '/');
}

/**
 * A team whose workspace has a billing account for a subscription in a status (none: the workspace never went to Checkout), and a Stripe
 * that knows its customer and subscription.
 *
 * @return array{owner: User, editor: User, viewer: User, stranger: User, workspace: Workspace, stripe: FakeStripe, account: BillingAccount|null}
 */
function billingApiTeam(?string $status = null, string $interval = 'month'): array
{
    $stripe = billingOn();
    $team = inventoryTeam();
    $account = null;

    if ($status !== null) {
        // The owner started the checkout the customer belongs to: they are the payer.
        $account = BillingAccount::factory()->for($team['workspace'])->paidBy($team['owner'])->subscribed($status, $interval)->create();
        $stripe->addCustomer($account->stripe_customer_id, 'old-payer@example.test');
        $stripe->subscribe($account->stripe_customer_id, $status, $interval);
    }

    return $team + ['stripe' => $stripe, 'account' => $account];
}

describe('the overview', function (): void {
    it('is given to every member of a Free workspace, with what only the owner may do told apart', function (string $who, bool $canManage): void {
        $team = billingApiTeam();

        inventoryCall($this, $team[$who], 'GET', billingApiUri($team['workspace']))
            ->assertOk()
            ->assertExactJson(['data' => [
                'enabled' => true,
                'selling' => true,
                'plan' => 'free',
                'status' => null,
                'interval' => null,
                'current_period_end' => null,
                'cancel_at_period_end' => false,
                'started_at' => null,
                'withdrawal_until' => null,
                'withdrawn' => false,
                'can_manage' => $canManage,
                'prices' => ['currency' => 'eur', 'month' => 600, 'year' => 6000],
                'limits' => ['inventories' => 3, 'items' => 500, 'storage_bytes' => 200000, 'members' => 3, 'share_links' => 2, 'photo_entry' => false],
                'usage' => ['inventories' => 0, 'storage_bytes' => 0, 'members' => 3],
            ]]);

        expect($team['stripe']->calls)->toBe([]);
    })->with([
        'the owner' => ['owner', true],
        'an editor' => ['editor', false],
        'a viewer' => ['viewer', false],
    ]);

    it('is not given to somebody who does not belong to the workspace, nor for a workspace that does not exist', function (): void {
        $team = billingApiTeam();

        inventoryCall($this, $team['stranger'], 'GET', billingApiUri($team['workspace']))->assertNotFound();
        inventoryCall($this, $team['owner'], 'GET', '/api/v1/workspaces/01JZZZZZZZZZZZZZZZZZZZZZZZ/billing')->assertNotFound();
    });

    it('is not given to a visitor who is not signed in', function (): void {
        $team = billingApiTeam();

        $this->flushSession();
        app('auth')->forgetGuards();

        $this->json('GET', billingApiUri($team['workspace']), [], frontend())->assertUnauthorized();
    });

    it('counts the inventories, the bytes of the files, and the members with the invitations that wait', function (): void {
        $team = billingApiTeam();
        inventoryIn($team['workspace'], 'Tools');
        inventoryIn($team['workspace'], 'Books');
        DB::table('workspaces')->where('id', $team['workspace']->id)->update(['storage_used' => 123456]);
        WorkspaceInvitation::factory()->create(['workspace_id' => $team['workspace']->id]);
        WorkspaceInvitation::factory()->expired()->create(['workspace_id' => $team['workspace']->id]);

        inventoryCall($this, $team['owner'], 'GET', billingApiUri($team['workspace']))
            ->assertOk()
            ->assertJsonPath('data.usage', ['inventories' => 2, 'storage_bytes' => 123456, 'members' => 4]);
    });

    it('tells the subscription of a Pro workspace and the limits of Pro', function (): void {
        $team = billingApiTeam('active', 'year');
        $team['account']->forceFill(['current_period_end' => '2027-03-05 10:30:00', 'cancel_at_period_end' => true])->save();

        inventoryCall($this, $team['editor'], 'GET', billingApiUri($team['workspace']))
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.plan', 'pro')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.interval', 'year')
            ->assertJsonPath('data.current_period_end', '2027-03-05T10:30:00+00:00')
            ->assertJsonPath('data.cancel_at_period_end', true)
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.limits', ['inventories' => 100, 'items' => 10000, 'storage_bytes' => 10000000, 'members' => 50, 'share_links' => 20, 'photo_entry' => true]);
    });

    it('keeps a workspace whose payment failed on Pro, and puts one whose subscription ended back on Free', function (string $status, string $plan): void {
        $team = billingApiTeam($status);

        inventoryCall($this, $team['owner'], 'GET', billingApiUri($team['workspace']))
            ->assertOk()
            ->assertJsonPath('data.plan', $plan)
            ->assertJsonPath('data.status', $status)
            ->assertJsonPath('data.limits.inventories', $plan === 'pro' ? 100 : 3);
    })->with([
        'past due' => ['past_due', 'pro'],
        'trialing' => ['trialing', 'pro'],
        'canceled' => ['canceled', 'free'],
        'unpaid' => ['unpaid', 'free'],
    ]);

    it('shows no yearly price when the instance has none', function (): void {
        $team = billingApiTeam();
        config(['services.stripe.prices.yearly' => null]);

        inventoryCall($this, $team['owner'], 'GET', billingApiUri($team['workspace']))
            ->assertOk()
            ->assertJsonPath('data.prices', ['currency' => 'eur', 'month' => 600, 'year' => null]);
    });

    it('says that no plan is offered on an instance without Stripe, with the limits of the instance', function (): void {
        Http::fake();
        Http::preventStrayRequests();
        billingOff();
        config([
            'inventories.max_per_workspace' => 7,
            'inventories.max_items' => 70,
            'inventories.storage_per_workspace' => 700,
            'workspaces.max_members' => 9,
            'inventories.max_share_links' => 5,
        ]);
        $team = inventoryTeam();
        // A leftover account of the time the instance sold plans changes nothing.
        BillingAccount::factory()->for($team['workspace'])->subscribed()->create();

        inventoryCall($this, $team['owner'], 'GET', billingApiUri($team['workspace']))
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.plan', 'free')
            ->assertJsonPath('data.status', null)
            ->assertJsonPath('data.prices.year', null)
            ->assertJsonPath('data.limits', ['inventories' => 7, 'items' => 70, 'storage_bytes' => 700, 'members' => 9, 'share_links' => 5, 'photo_entry' => true]);

        Http::assertNothingSent();
    });
});

describe('Checkout', function (): void {
    it('makes the customer of the workspace, opens the session and answers with the page of Stripe', function (): void {
        $team = billingApiTeam();
        $base = DB::transactionLevel();
        $team['stripe']->onCall('POST /v1/customers', fn () => expect(DB::transactionLevel())->toBe($base))
            ->onCall('POST /v1/checkout/sessions', fn () => expect(DB::transactionLevel())->toBe($base));

        $answer = inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertOk();
        $account = BillingAccount::query()->where('workspace_id', $team['workspace']->id)->firstOrFail();
        $session = array_values($team['stripe']->sessions)[0];

        expect($answer->json())->toBe(['data' => ['url' => 'https://checkout.stripe.com/c/pay/'.$session['id']]])
            // A customer made for the request has no session to expire and no subscription to read.
            ->and($team['stripe']->calls)->toBe(['POST /v1/customers', 'POST /v1/checkout/sessions'])
            ->and($account->payer_id)->toBe($team['owner']->id)
            ->and($team['stripe']->customers[$account->stripe_customer_id])->toMatchArray([
                'email' => $team['owner']->email,
                'name' => 'Shop',
                'metadata' => ['workspace' => $team['workspace']->ulid],
                'preferred_locales' => ['en'],
            ])
            ->and($account->status)->toBeNull()
            ->and($session)->toMatchArray([
                'mode' => 'subscription',
                'customer' => $account->stripe_customer_id,
                'line_items' => [['price' => FakeStripe::PRICE_MONTHLY, 'quantity' => '1']],
                'success_url' => billingApiRoot().'/app/w/'.$team['workspace']->ulid.'/billing?checkout=done',
                'cancel_url' => billingApiRoot().'/app/w/'.$team['workspace']->ulid.'/billing',
                'client_reference_id' => $team['workspace']->ulid,
                // The marker of the instance, so that the webhook cancels only the orphans of this instance (see StripeWebhookTest).
                'metadata' => ['instance' => Billing::instanceMarker()],
                'subscription_data' => ['metadata' => ['workspace' => $team['workspace']->ulid, 'instance' => Billing::instanceMarker()]],
                'allow_promotion_codes' => 'true',
                'billing_address_collection' => 'required',
                'locale' => 'en',
                'integration_identifier' => 'inventaire-cloud-pkqzrvmw',
            ])
            ->and($session)->not->toHaveKey('payment_method_types');
    });

    it('sells the yearly plan when the owner chooses it, in the language of the owner', function (): void {
        $team = billingApiTeam();
        $this->useLanguage('fr');

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'year'], ['Accept-Language' => 'fr'])
            ->assertOk();
        $session = array_values($team['stripe']->sessions)[0];

        expect($session['line_items'][0]['price'])->toBe(FakeStripe::PRICE_YEARLY)
            ->and($session['locale'])->toBe('fr')
            ->and(array_values($team['stripe']->customers)[0]['preferred_locales'])->toBe(['fr']);
    });

    it('keeps the customer it made, and gives it the address of the owner who comes back', function (): void {
        $team = billingApiTeam('canceled');
        $customer = $team['account']->stripe_customer_id;

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();

        expect($team['stripe']->calls)->toBe(['GET /v1/checkout/sessions', 'GET /v1/subscriptions', 'POST /v1/customers/{id}', 'POST /v1/checkout/sessions'])
            ->and($team['stripe']->customers[$customer]['email'])->toBe($team['owner']->email)
            ->and(array_values($team['stripe']->sessions)[0]['customer'])->toBe($customer)
            ->and(BillingAccount::query()->where('workspace_id', $team['workspace']->id)->count())->toBe(1);
    });

    it('lets a workspace whose subscription is over buy again', function (): void {
        $team = billingApiTeam('canceled');

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();
    });

    it('expires what is open, reads the subscriptions at Stripe and refuses a workspace that is on Pro already, without opening anything', function (string $status): void {
        $team = billingApiTeam($status);

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_subscribed');

        expect($team['stripe']->calls)->toBe(['GET /v1/checkout/sessions', 'GET /v1/subscriptions'])
            ->and($team['stripe']->sessions)->toBe([]);
    })->with(['active', 'trialing', 'past_due']);

    it('goes by Stripe and not by the row: a payment whose news has not come yet is a subscription all the same', function (): void {
        // The row still says the old subscription is over; Stripe has a live one (the webhook is on its way).
        $team = billingApiTeam('canceled');
        $team['stripe']->subscribe($team['account']->stripe_customer_id, 'active');

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_subscribed');

        expect($team['account']->fresh()->status)->toBe('active')
            ->and($team['stripe']->calls)->toBe(['GET /v1/checkout/sessions', 'GET /v1/subscriptions'])
            ->and($team['stripe']->sessions)->toBe([]);
    });

    it('refuses a workspace whose subscription is still in play without giving Pro: it is settled, not bought again', function (string $status): void {
        $team = billingApiTeam($status);

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'subscription_pending')
            ->assertJsonPath('message', 'This workspace has a subscription that is not settled yet. Wait for its payment to be confirmed, or settle it from the subscription management.');

        expect($team['stripe']->calls)->toBe(['GET /v1/checkout/sessions', 'GET /v1/subscriptions'])
            ->and($team['stripe']->sessions)->toBe([]);
    })->with(['incomplete', 'unpaid', 'paused']);

    it('says that Stripe cannot be asked, and opens nothing, when the subscriptions cannot be read', function (): void {
        $team = billingApiTeam('canceled');
        $team['stripe']->failNext('GET /v1/subscriptions', 500);

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        expect($team['stripe']->sessions)->toBe([]);
    });

    it('expires the Checkout sessions of the customer that are still open, so that two tabs cannot both buy', function (): void {
        $team = billingApiTeam('canceled');
        $uri = billingApiUri($team['workspace'], 'checkout');
        $customer = $team['account']->stripe_customer_id;
        inventoryCall($this, $team['owner'], 'POST', $uri, ['interval' => 'month'])->assertOk();
        inventoryCall($this, $team['owner'], 'POST', $uri, ['interval' => 'year'])->assertOk();
        [$first, $second] = array_keys($team['stripe']->sessions);

        expect($team['stripe']->sessions[$first]['status'])->toBe('expired')
            ->and($team['stripe']->sessions[$second]['status'])->toBe('open')
            ->and($team['stripe']->sessions[$second]['customer'])->toBe($customer)
            ->and($team['stripe']->count('POST /v1/checkout/sessions/{id}/expire'))->toBe(1)
            // The open sessions are expired before the subscriptions are read: nothing can be paid from then on.
            ->and(array_slice($team['stripe']->calls, 4))->toBe(['GET /v1/checkout/sessions', 'POST /v1/checkout/sessions/{id}/expire', 'GET /v1/subscriptions', 'POST /v1/customers/{id}', 'POST /v1/checkout/sessions']);
        parse_str((string) parse_url($team['stripe']->requestsOf('GET /v1/checkout/sessions')[1]->url(), PHP_URL_QUERY), $query);
        expect($query)->toBe(['customer' => $customer, 'status' => 'open', 'limit' => '100']);
    });

    it('leaves the sessions of other customers alone, and the ones that are over', function (): void {
        $team = billingApiTeam('canceled');
        $stripe = $team['stripe'];
        $stripe->addCustomer('cus_other');
        $stripe->sessions['cs_other'] = ['id' => 'cs_other', 'customer' => 'cus_other', 'status' => 'open'];
        $stripe->sessions['cs_done'] = ['id' => 'cs_done', 'customer' => $team['account']->stripe_customer_id, 'status' => 'complete'];
        $stripe->sessions['cs_gone'] = ['id' => 'cs_gone', 'customer' => $team['account']->stripe_customer_id, 'status' => 'expired'];

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();

        expect($stripe->count('POST /v1/checkout/sessions/{id}/expire'))->toBe(0)
            ->and($stripe->sessions['cs_other']['status'])->toBe('open');
    });

    it('sees the payment made on a session that could not be expired any more (it was paid on meanwhile), and opens nothing', function (): void {
        $team = billingApiTeam('canceled');
        $uri = billingApiUri($team['workspace'], 'checkout');
        inventoryCall($this, $team['owner'], 'POST', $uri, ['interval' => 'month'])->assertOk();
        $open = array_key_first($team['stripe']->sessions);
        // The person pays on the first tab just as the second one asks.
        $team['stripe']->onCall('POST /v1/checkout/sessions/{id}/expire', function () use ($team, $open): void {
            $team['stripe']->completeCheckout($open);
            $team['stripe']->subscribe($team['account']->stripe_customer_id, 'active');
        });

        // The refusal of Stripe to expire it (400) is not a failure: the subscriptions are read right after, and it started one.
        inventoryCall($this, $team['owner'], 'POST', $uri, ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_subscribed');

        expect($team['stripe']->count('POST /v1/checkout/sessions'))->toBe(1)
            ->and($team['account']->fresh()->status)->toBe('active');
    });

    it('goes on to open the session when the one it could not expire had ended by itself', function (): void {
        $team = billingApiTeam('canceled');
        $uri = billingApiUri($team['workspace'], 'checkout');
        inventoryCall($this, $team['owner'], 'POST', $uri, ['interval' => 'month'])->assertOk();
        $open = array_key_first($team['stripe']->sessions);
        // It expires by itself just as the second tab asks.
        $team['stripe']->onCall('POST /v1/checkout/sessions/{id}/expire', function () use ($team, $open): void {
            $team['stripe']->sessions[$open]['status'] = 'expired';
        });

        inventoryCall($this, $team['owner'], 'POST', $uri, ['interval' => 'month'])->assertOk();

        expect($team['stripe']->count('POST /v1/checkout/sessions'))->toBe(2);
    });

    it('says that Stripe cannot be asked, and opens nothing, when a session cannot be expired for another reason', function (): void {
        $team = billingApiTeam('canceled');
        $uri = billingApiUri($team['workspace'], 'checkout');
        inventoryCall($this, $team['owner'], 'POST', $uri, ['interval' => 'month'])->assertOk();
        $team['stripe']->failNext('POST /v1/checkout/sessions/{id}/expire', 500);

        inventoryCall($this, $team['owner'], 'POST', $uri, ['interval' => 'month'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        expect($team['stripe']->count('POST /v1/checkout/sessions'))->toBe(1);
    });

    it('opens nothing when the open sessions cannot be listed', function (): void {
        $team = billingApiTeam('canceled');
        $team['stripe']->failNext('GET /v1/checkout/sessions', 500);

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        expect($team['stripe']->sessions)->toBe([]);
    });

    it('is paced: ten a minute for each person', function (): void {
        $team = billingApiTeam('canceled');
        $uri = billingApiUri($team['workspace'], 'checkout');

        for ($i = 0; $i < 10; $i++) {
            inventoryCall($this, $team['owner'], 'POST', $uri, ['interval' => 'month'])->assertOk();
        }

        inventoryCall($this, $team['owner'], 'POST', $uri, ['interval' => 'month'])->assertStatus(429)->assertHeader('Retry-After');

        // Another person has a count of their own, and so does the portal.
        $other = inventoryPerson('Second owner');
        $team['workspace']->members()->attach($other->id, ['role' => 'owner']);

        inventoryCall($this, $other, 'POST', $uri, ['interval' => 'month'])->assertOk();
        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'portal'))->assertOk();
        expect($team['stripe']->count('POST /v1/checkout/sessions'))->toBe(11);
    });

    it('is for the owners: a member is refused and a stranger is told the workspace is not there', function (): void {
        $team = billingApiTeam();
        $uri = billingApiUri($team['workspace'], 'checkout');

        inventoryCall($this, $team['editor'], 'POST', $uri, ['interval' => 'month'])->assertForbidden();
        inventoryCall($this, $team['viewer'], 'POST', $uri, ['interval' => 'month'])->assertForbidden();
        inventoryCall($this, $team['stranger'], 'POST', $uri, ['interval' => 'month'])->assertNotFound();

        expect($team['stripe']->calls)->toBe([])
            ->and(BillingAccount::query()->count())->toBe(0);
    });

    it('answers a conflict on an instance that sells no plan', function (): void {
        Http::fake();
        Http::preventStrayRequests();
        billingOff();
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_disabled');

        Http::assertNothingSent();
    });

    it('asks for an interval the instance sells', function (?string $interval, array $config, string $code): void {
        $team = billingApiTeam();
        config($config);

        $answer = inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), $interval === null ? [] : ['interval' => $interval])
            ->assertStatus(422)
            ->assertJsonValidationErrors('interval');

        expect($answer->json('code'))->toBe($code === '' ? null : $code)
            ->and($team['stripe']->calls)->toBe([]);
    })->with([
        'none' => [null, [], ''],
        'a word that is not an interval' => ['week', [], ''],
        'a year when no yearly Price is set' => ['year', ['services.stripe.prices.yearly' => null], 'interval_not_offered'],
    ]);

    it('answers that the payment service is unavailable when Stripe fails, and says nothing of the failure', function (?int $status): void {
        $team = billingApiTeam();
        $team['stripe']->failNext('POST /v1/checkout/sessions', $status);

        $answer = inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        // The customer was made and stored: the next try goes straight to the session.
        expect(BillingAccount::query()->where('workspace_id', $team['workspace']->id)->count())->toBe(1)
            ->and($answer->getContent())->not->toContain(FakeStripe::SECRET);
    })->with(['an error of Stripe' => [500], 'a refusal' => [400], 'a connection that is lost' => [null]]);

    it('stores nothing when the customer cannot be made', function (): void {
        $team = billingApiTeam();
        $team['stripe']->failNext('POST /v1/customers', 500);

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        expect(BillingAccount::query()->count())->toBe(0)
            ->and($team['stripe']->count('POST /v1/checkout/sessions'))->toBe(0);
    });

    it('keeps the customer the same owner stored while it was making its own (two clicks)', function (): void {
        $team = billingApiTeam();
        $workspaceId = $team['workspace']->id;
        $team['stripe']->onCall('POST /v1/customers', function () use ($workspaceId, $team): void {
            $team['stripe']->addCustomer('cus_stored_first');
            BillingAccount::factory()->paidBy($team['owner'])->create(['workspace_id' => $workspaceId, 'stripe_customer_id' => 'cus_stored_first']);
        });

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();

        expect(BillingAccount::query()->where('workspace_id', $workspaceId)->pluck('stripe_customer_id')->all())->toBe(['cus_stored_first'])
            ->and(array_values($team['stripe']->sessions)[0]['customer'])->toBe('cus_stored_first');
    });

    it('makes the customer with a key that changes with what is sent, so that a name changed within the day is not refused by Stripe', function (): void {
        $team = billingApiTeam();
        $post = fn () => inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();

        // The first request makes the customer; it is lost before the second (a session left open and a row that was cleared, say).
        $post();
        BillingAccount::query()->delete();
        $team['workspace']->forceFill(['name' => 'Shop renamed'])->save();
        $post();

        $keys = array_map(fn ($request): string => $request->header('Idempotency-Key')[0], $team['stripe']->requestsOf('POST /v1/customers'));

        expect($keys)->toHaveCount(2)
            ->and($keys[0])->toBe('customer-'.$team['workspace']->ulid.'-'.substr(hash('sha256', implode("\n", [$team['owner']->email, 'Shop', 'en'])), 0, 16))
            ->and($keys[1])->toBe('customer-'.$team['workspace']->ulid.'-'.substr(hash('sha256', implode("\n", [$team['owner']->email, 'Shop renamed', 'en'])), 0, 16))
            ->and($keys[1])->not->toBe($keys[0]);
    });

    it('is told the account is gone when the owner was deleted while the customer was made, and stores nothing', function (): void {
        $team = billingApiTeam();
        // The owner's account is locked before the workspace's, and found gone: not a failure of the foreign key on the insert.
        $team['stripe']->onCall('POST /v1/customers', fn () => DB::table('users')->where('id', $team['owner']->id)->delete());

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertNotFound();

        expect(BillingAccount::query()->count())->toBe(0)
            ->and($team['stripe']->count('POST /v1/checkout/sessions'))->toBe(0);
    });

    it('asks the owner whose customer was stored second to try again: another owner won the race, and the customer is theirs', function (): void {
        $team = billingApiTeam();
        $second = inventoryPerson('Second owner');
        $team['workspace']->members()->attach($second->id, ['role' => 'owner']);
        $team['stripe']->onCall('POST /v1/customers', function () use ($team, $second): void {
            $team['stripe']->addCustomer('cus_stored_first');
            BillingAccount::factory()->paidBy($second)->create(['workspace_id' => $team['workspace']->id, 'stripe_customer_id' => 'cus_stored_first']);
        });

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'checkout_in_progress');

        expect(BillingAccount::query()->sole()->only(['stripe_customer_id', 'payer_id']))->toBe(['stripe_customer_id' => 'cus_stored_first', 'payer_id' => $second->id])
            ->and($team['stripe']->count('POST /v1/checkout/sessions'))->toBe(0);
    });

    it('is told the workspace is gone when it was deleted while the customer was made', function (): void {
        $team = billingApiTeam();
        $team['stripe']->onCall('POST /v1/customers', fn () => DB::table('workspaces')->where('id', $team['workspace']->id)->delete());

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertNotFound();

        expect(BillingAccount::query()->count())->toBe(0)
            ->and($team['stripe']->count('POST /v1/checkout/sessions'))->toBe(0);
    });
});

describe('the portal', function (): void {
    it('opens a session for the customer of the workspace and answers with the page of Stripe', function (): void {
        $team = billingApiTeam('active');
        $base = DB::transactionLevel();
        $team['stripe']->onCall('POST /v1/billing_portal/sessions', fn () => expect(DB::transactionLevel())->toBe($base));

        $answer = inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'portal'))->assertOk();
        $portal = array_values($team['stripe']->portals)[0];

        expect($answer->json())->toBe(['data' => ['url' => 'https://billing.stripe.com/session/'.$portal['id']]])
            ->and($team['stripe']->calls)->toBe(['POST /v1/customers/{id}', 'POST /v1/billing_portal/sessions'])
            ->and($team['stripe']->customers[$team['account']->stripe_customer_id]['email'])->toBe($team['owner']->email)
            ->and($portal)->toMatchArray([
                'customer' => $team['account']->stripe_customer_id,
                'return_url' => billingApiRoot().'/app/w/'.$team['workspace']->ulid.'/billing',
                'locale' => 'en',
            ]);
    });

    it('is open to a workspace that is back on Free but has a customer', function (): void {
        $team = billingApiTeam('canceled');

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'portal'))->assertOk();
    });

    it('has nothing to open for a workspace that never went to Checkout', function (): void {
        $team = billingApiTeam();

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'portal'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_billing_account');

        expect($team['stripe']->calls)->toBe([]);
    });

    it('is for the owners: a member is refused and a stranger is told the workspace is not there', function (): void {
        $team = billingApiTeam('active');
        $uri = billingApiUri($team['workspace'], 'portal');

        inventoryCall($this, $team['editor'], 'POST', $uri)->assertForbidden();
        inventoryCall($this, $team['viewer'], 'POST', $uri)->assertForbidden();
        inventoryCall($this, $team['stranger'], 'POST', $uri)->assertNotFound();

        expect($team['stripe']->calls)->toBe([]);
    });

    it('answers a conflict on an instance that sells no plan', function (): void {
        Http::fake();
        Http::preventStrayRequests();
        billingOff();
        $team = inventoryTeam();
        BillingAccount::factory()->for($team['workspace'])->subscribed()->create();

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'portal'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_disabled');

        Http::assertNothingSent();
    });

    it('answers that the payment service is unavailable when Stripe fails', function (string $call): void {
        $team = billingApiTeam('active');
        $team['stripe']->failNext($call, 500);

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'portal'))
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');
    })->with(['POST /v1/customers/{id}', 'POST /v1/billing_portal/sessions']);

    it('is paced: ten a minute for each person', function (): void {
        // Back on Free, so that a second owner (who is not the payer) is not refused for the subscription of the payer.
        $team = billingApiTeam('canceled');
        $uri = billingApiUri($team['workspace'], 'portal');

        for ($i = 0; $i < 10; $i++) {
            inventoryCall($this, $team['owner'], 'POST', $uri)->assertOk();
        }

        inventoryCall($this, $team['owner'], 'POST', $uri)->assertStatus(429)->assertHeader('Retry-After');

        // Another person has a count of their own.
        $other = inventoryPerson('Second owner');
        $team['workspace']->members()->attach($other->id, ['role' => 'owner']);

        inventoryCall($this, $other, 'POST', $uri)->assertOk();
        expect($team['stripe']->count('POST /v1/billing_portal/sessions'))->toBe(11);
    });
});

/**
 * A team whose account holds a customer and a subscription (an active one unless told otherwise) that the Stripe of the test does not
 * have, paid for by the owner.
 *
 * @return array{owner: User, editor: User, viewer: User, stranger: User, workspace: Workspace, stripe: FakeStripe, account: BillingAccount}
 */
function billingApiTeamWithLostCustomer(string $status = 'active'): array
{
    $stripe = billingOn();
    $team = inventoryTeam();
    $account = BillingAccount::factory()->for($team['workspace'])->paidBy($team['owner'])->subscribed($status)->create(['stripe_customer_id' => 'cus_lost']);

    return $team + ['stripe' => $stripe, 'account' => $account];
}

describe('a customer that Stripe does not know any more', function (): void {
    // The keys of the instance were switched from the test mode to the live one (or to another Stripe account): the customer the account
    // holds is not there, and nor is its subscription.

    it('is replaced by a new one, whose Checkout session is opened, and the subscription it had is forgotten', function (): void {
        $team = billingApiTeamWithLostCustomer();
        $base = DB::transactionLevel();
        $team['stripe']->onCall('POST /v1/customers', fn () => expect(DB::transactionLevel())->toBe($base));

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();
        $account = BillingAccount::query()->where('workspace_id', $team['workspace']->id)->sole();

        expect($account->stripe_customer_id)->not->toBe('cus_lost')
            ->and($team['stripe']->customers)->toHaveKey($account->stripe_customer_id)
            ->and($team['stripe']->customers[$account->stripe_customer_id])->toMatchArray(['email' => $team['owner']->email, 'metadata' => ['workspace' => $team['workspace']->ulid]])
            ->and(array_values($team['stripe']->sessions)[0]['customer'])->toBe($account->stripe_customer_id)
            ->and([$account->stripe_subscription_id, $account->status, $account->price_id, $account->interval, $account->current_period_end, $account->cancel_at_period_end, $account->synced_at])
            ->toBe([null, null, null, null, null, false, null])
            ->and(BillingAccount::query()->count())->toBe(1);
    });

    it('is replaced under the lock of the workspace, keeping the one another request stored first', function (): void {
        $team = billingApiTeamWithLostCustomer();
        $team['stripe']->onCall('POST /v1/customers', function () use ($team): void {
            $team['stripe']->addCustomer('cus_replaced_first');
            $team['account']->forceFill(['stripe_customer_id' => 'cus_replaced_first'])->save();
        });

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();

        expect($team['account']->fresh()->stripe_customer_id)->toBe('cus_replaced_first')
            ->and(array_values($team['stripe']->sessions)[0]['customer'])->toBe('cus_replaced_first');
    });

    it('is replaced with a key of its own, so that Stripe does not hand back a customer of the same name that was deleted', function (): void {
        $team = billingApiTeamWithLostCustomer();

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();

        $key = $team['stripe']->requestsOf('POST /v1/customers')[0]->header('Idempotency-Key')[0];
        $sent = substr(hash('sha256', implode("\n", [$team['owner']->email, 'Shop', 'en'])), 0, 16);
        $first = 'customer-'.$team['workspace']->ulid.'-'.$sent;

        // Made of the workspace, of the customer it replaces and of what is sent, all through hashes: no email (the address of a person)
        // goes into a key, and a name changed within the day does not make Stripe refuse the key (it was used with other parameters).
        expect($key)->not->toBe($first)
            ->and($key)->toBe('customer-'.$team['workspace']->ulid.'-'.substr(hash('sha256', 'cus_lost'), 0, 16).'-'.$sent)
            ->and($key)->not->toContain($team['owner']->email);
    });

    it('is not replaced for a failure that is not Stripe saying the customer is not there', function (string $kind): void {
        $team = billingApiTeamWithLostCustomer();

        if ($kind === 'a 404 that is not the answer of Stripe') {
            // A proxy, a wrong address: what answers the update of the customer says no `resource_missing`.
            $team['stripe']->onCall('GET /v1/subscriptions', fn () => $team['stripe']->answerWith(Http::response('', 404)));
        } else {
            $team['stripe']->failNext('POST /v1/customers/{id}', 500);
        }

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        expect($team['account']->fresh()->stripe_customer_id)->toBe('cus_lost')
            ->and($team['stripe']->count('POST /v1/customers'))->toBe(0);
    })->with(['an error of Stripe', 'a 404 that is not the answer of Stripe']);

    it('is replaced when it is really gone at Stripe and the account has no subscription in play: the list of its open sessions is empty', function (): void {
        $team = billingApiTeamWithLostCustomer('canceled');
        // Stripe answers `resource_missing` to every call that names the customer, the list of its open Checkout sessions included.
        $team['stripe']->loseCustomer('cus_lost');

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();
        $account = BillingAccount::query()->where('workspace_id', $team['workspace']->id)->sole();

        expect($account->stripe_customer_id)->not->toBe('cus_lost')
            ->and($account->status)->toBeNull()
            ->and($team['stripe']->count('GET /v1/checkout/sessions'))->toBe(1)
            ->and(array_values($team['stripe']->sessions)[0]['customer'])->toBe($account->stripe_customer_id);
    });

    it('keeps a subscription in play when its customer is gone at Stripe: nothing is cleared nor replaced, the administrators are told and the person gets a 503', function (): void {
        Log::spy();
        Mail::fake();
        $administrator = User::factory()->admin()->create(['email' => 'admin@example.test', 'locale' => 'en']);
        $team = billingApiTeamWithLostCustomer('active');
        $team['stripe']->loseCustomer('cus_lost');

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        $account = $team['account']->fresh();
        expect($account->stripe_customer_id)->toBe('cus_lost')
            ->and($account->status)->toBe('active')
            ->and($account->stripe_subscription_id)->not->toBeNull()
            ->and($account->payer_id)->toBe($team['owner']->id)
            ->and(WorkspacePlan::for($team['workspace']))->toBe(Plan::Pro)
            ->and($team['stripe']->count('POST /v1/customers'))->toBe(0)
            ->and($team['stripe']->count('POST /v1/checkout/sessions'))->toBe(0);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'wrong-mode API key')
            && ($context['customer'] ?? null) === 'cus_lost'
            && ($context['workspace'] ?? null) === $team['workspace']->ulid)->once();
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->hasTo($administrator->email) && $mail->eventType === 'customer_lost' && $mail->objectId === 'cus_lost');
    });

    it('keeps a subscription in play for the refresh and the portal too, whatever the answer says of the customer', function (string $endpoint): void {
        $team = billingApiTeamWithLostCustomer('active');
        $team['stripe']->loseCustomer('cus_lost');

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], $endpoint))
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        expect($team['account']->fresh()->status)->toBe('active')
            ->and(WorkspacePlan::for($team['workspace']))->toBe(Plan::Pro);
    })->with(['refresh', 'portal']);

    it('tells the administrators when the portal finds the customer of a subscription in play gone at Stripe: the error in the log, one mail, a 503, nothing changed', function (): void {
        Log::spy();
        Mail::fake();
        $administrator = User::factory()->admin()->create(['email' => 'admin@example.test', 'locale' => 'en']);
        $team = billingApiTeamWithLostCustomer('active');
        $team['stripe']->loseCustomer('cus_lost');

        foreach ([1, 2] as $ignored) {
            inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'portal'))
                ->assertStatus(503)
                ->assertJsonPath('code', 'billing_unavailable');
        }

        $account = $team['account']->fresh();
        expect($account->stripe_customer_id)->toBe('cus_lost')
            ->and($account->status)->toBe('active')
            ->and($team['stripe']->count('POST /v1/billing_portal/sessions'))->toBe(0)
            ->and($team['stripe']->count('POST /v1/customers'))->toBe(0);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'wrong-mode API key')
            && ($context['customer'] ?? null) === 'cus_lost'
            && ($context['workspace'] ?? null) === $team['workspace']->ulid)->twice();
        // Once a day for the instance, however often it is asked.
        Mail::assertQueued(BillingAlertMail::class, 1);
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->hasTo($administrator->email) && $mail->eventType === 'customer_lost' && $mail->objectId === 'cus_lost');
    });

    it('tells nobody when the portal finds the customer gone and the row has no subscription in play: the answer is the same 503', function (): void {
        Log::spy();
        Mail::fake();
        User::factory()->admin()->create();
        $team = billingApiTeamWithLostCustomer('canceled');
        $team['stripe']->loseCustomer('cus_lost');

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'portal'))
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        Log::shouldNotHaveReceived('error');
        Mail::assertNothingQueued();
    });

    it('is told the workspace is gone when it was deleted while the new customer was made', function (): void {
        $team = billingApiTeamWithLostCustomer();
        $team['stripe']->onCall('POST /v1/customers', fn () => DB::table('workspaces')->where('id', $team['workspace']->id)->delete());

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertNotFound();

        expect($team['stripe']->count('POST /v1/checkout/sessions'))->toBe(0);
    });

    it('is replaced by the service that makes the customer, which gives back the account with the new customer', function (): void {
        Log::spy();
        $team = billingApiTeamWithLostCustomer('canceled');

        $account = app(BillingCustomers::class)->ensure($team['workspace'], $team['owner'], 'fr');

        expect($account->stripe_customer_id)->not->toBe('cus_lost')
            ->and($account->payer_id)->toBe($team['owner']->id)
            ->and($account->status)->toBeNull()
            ->and($team['stripe']->customers[$account->stripe_customer_id]['preferred_locales'])->toBe(['fr'])
            ->and(WorkspacePlan::for($team['workspace']))->toBe(Plan::Free);
        // The customer that was replaced is always written in the log.
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => ($context['customer'] ?? null) === 'cus_lost'
            && ($context['replaced_by'] ?? null) === $account->stripe_customer_id
            && ($context['reason'] ?? null) === 'lost')->once();
    });

    it('is not replaced while the account has a subscription in play: the service refuses, writes the customer in the log and tells the administrators', function (): void {
        Log::spy();
        Mail::fake();
        $administrator = User::factory()->admin()->create(['email' => 'admin@example.test', 'locale' => 'en']);
        $team = billingApiTeamWithLostCustomer('active');
        $customers = app(BillingCustomers::class);

        // The row still says the subscription is in play (nothing synchronised it first).
        expect(fn () => $customers->ensure($team['workspace'], $team['owner'], 'en'))->toThrow(StripeException::class, 'resource_missing');
        expect(fn () => $customers->ensure($team['workspace'], $team['owner'], 'en'))->toThrow(StripeException::class);

        $account = $team['account']->fresh();
        expect($account->stripe_customer_id)->toBe('cus_lost')
            ->and($account->status)->toBe('active')
            ->and($account->payer_id)->toBe($team['owner']->id)
            ->and($team['stripe']->count('POST /v1/customers'))->toBe(0);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'is not replaced')
            && ($context['customer'] ?? null) === 'cus_lost'
            && ($context['workspace'] ?? null) === $team['workspace']->ulid)->twice();
        // Once a day for the account, however many times it is asked.
        Mail::assertQueued(BillingAlertMail::class, 1);
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->hasTo($administrator->email)
            && $mail->eventType === 'customer_lost'
            && $mail->objectId === 'cus_lost'
            && $mail->workspaceUlid === $team['workspace']->ulid);
    });

    it('is told the service is unavailable, with nothing changed, when a subscription was written while the new customer was made', function (): void {
        Mail::fake();
        User::factory()->admin()->create(['email' => 'admin@example.test']);
        $team = billingApiTeamWithLostCustomer('canceled');
        // A synchronisation (or a webhook) wrote a subscription between the moment the customer was found missing and the moment it is replaced.
        $team['stripe']->onCall('POST /v1/customers', fn () => $team['account']->forceFill(['status' => 'active', 'stripe_subscription_id' => 'sub_written_meanwhile'])->save());

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        expect($team['account']->fresh()->stripe_customer_id)->toBe('cus_lost')
            ->and($team['account']->fresh()->stripe_subscription_id)->toBe('sub_written_meanwhile')
            ->and($team['stripe']->count('POST /v1/checkout/sessions'))->toBe(0);
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->eventType === 'customer_lost');
    });
});

describe('the refresh', function (): void {
    it('reads the subscription at Stripe now and answers with the overview as it is then', function (): void {
        $team = billingApiTeam();
        $account = BillingAccount::factory()->for($team['workspace'])->create();
        $team['stripe']->addCustomer($account->stripe_customer_id);
        $periodEnd = now()->addDays(20)->startOfSecond();
        $team['stripe']->subscribe($account->stripe_customer_id, 'active', 'year', $periodEnd->getTimestamp());
        $base = DB::transactionLevel();
        $team['stripe']->onCall('GET /v1/subscriptions', fn () => expect(DB::transactionLevel())->toBe($base));

        inventoryCall($this, $team['owner'], 'GET', billingApiUri($team['workspace']))->assertJsonPath('data.plan', 'free');

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'refresh'))
            ->assertOk()
            ->assertJsonPath('data.plan', 'pro')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.interval', 'year')
            ->assertJsonPath('data.current_period_end', $periodEnd->utc()->toIso8601String())
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.limits.inventories', 100);

        expect($team['stripe']->calls)->toBe(['GET /v1/subscriptions'])
            ->and($account->fresh()->status)->toBe('active');
    });

    it('puts a workspace back on Free when the subscription ended at Stripe', function (): void {
        $team = billingApiTeam('active');
        $team['stripe']->setStatus(array_key_first($team['stripe']->subscriptions), 'canceled');

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'refresh'))
            ->assertOk()
            ->assertJsonPath('data.plan', 'free')
            ->assertJsonPath('data.status', 'canceled');
    });

    it('has nothing to read for a workspace that never went to Checkout', function (): void {
        $team = billingApiTeam();

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'refresh'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_billing_account');

        expect($team['stripe']->calls)->toBe([]);
    });

    it('is for the owners: a member is refused and a stranger is told the workspace is not there', function (): void {
        $team = billingApiTeam('active');
        $uri = billingApiUri($team['workspace'], 'refresh');

        inventoryCall($this, $team['editor'], 'POST', $uri)->assertForbidden();
        inventoryCall($this, $team['stranger'], 'POST', $uri)->assertNotFound();

        expect($team['stripe']->calls)->toBe([]);
    });

    it('answers a conflict on an instance that sells no plan', function (): void {
        Http::fake();
        Http::preventStrayRequests();
        billingOff();
        $team = inventoryTeam();
        BillingAccount::factory()->for($team['workspace'])->subscribed()->create();

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'refresh'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_disabled');

        Http::assertNothingSent();
    });

    it('answers that the payment service is unavailable when Stripe fails, and leaves the plan as it was', function (): void {
        $team = billingApiTeam('active');
        $team['stripe']->failNext('GET /v1/subscriptions', 500);

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'refresh'))
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        expect($team['account']->fresh()->status)->toBe('active');
    });

    it('is paced: six a minute for each person', function (): void {
        $team = billingApiTeam('active');
        $uri = billingApiUri($team['workspace'], 'refresh');

        for ($i = 0; $i < 6; $i++) {
            inventoryCall($this, $team['owner'], 'POST', $uri)->assertOk();
        }

        inventoryCall($this, $team['owner'], 'POST', $uri)->assertStatus(429)->assertHeader('Retry-After');

        // Another person has a count of their own; so does the overview, which is not paced.
        $other = inventoryPerson('Second owner');
        $team['workspace']->members()->attach($other->id, ['role' => 'owner']);

        inventoryCall($this, $other, 'POST', $uri)->assertOk();
        inventoryCall($this, $team['owner'], 'GET', billingApiUri($team['workspace']))->assertOk();
        expect($team['stripe']->count('GET /v1/subscriptions'))->toBe(7);
    });
});
