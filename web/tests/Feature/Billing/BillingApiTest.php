<?php

declare(strict_types=1);

use App\Models\BillingAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
 * The address of an endpoint of the subscription of a workspace.
 *
 * @param  string  $endpoint  `''` for the overview, or `checkout`, `portal`, `refresh`.
 */
function billingApiUri(Workspace $workspace, string $endpoint = ''): string
{
    return "/api/v1/workspaces/{$workspace->ulid}/billing".($endpoint === '' ? '' : "/{$endpoint}");
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
        $account = BillingAccount::factory()->for($team['workspace'])->subscribed($status, $interval)->create();
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
                'plan' => 'free',
                'status' => null,
                'interval' => null,
                'current_period_end' => null,
                'cancel_at_period_end' => false,
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
            ->and($team['stripe']->calls)->toBe(['POST /v1/customers', 'POST /v1/checkout/sessions'])
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
                'subscription_data' => ['metadata' => ['workspace' => $team['workspace']->ulid]],
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
        $team = billingApiTeam('incomplete');
        $customer = $team['account']->stripe_customer_id;

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();

        expect($team['stripe']->calls)->toBe(['POST /v1/customers/{id}', 'POST /v1/checkout/sessions'])
            ->and($team['stripe']->customers[$customer]['email'])->toBe($team['owner']->email)
            ->and(array_values($team['stripe']->sessions)[0]['customer'])->toBe($customer)
            ->and(BillingAccount::query()->where('workspace_id', $team['workspace']->id)->count())->toBe(1);
    });

    it('lets a workspace whose subscription is over buy again', function (string $status): void {
        $team = billingApiTeam($status);

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();
    })->with(['canceled', 'unpaid', 'incomplete']);

    it('refuses a workspace that is on Pro already, without asking Stripe', function (string $status): void {
        $team = billingApiTeam($status);

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_subscribed');

        expect($team['stripe']->calls)->toBe([]);
    })->with(['active', 'trialing', 'past_due']);

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

    it('keeps the customer another request stored while it was making its own', function (): void {
        $team = billingApiTeam();
        $workspaceId = $team['workspace']->id;
        $team['stripe']->onCall('POST /v1/customers', function () use ($workspaceId, $team): void {
            $team['stripe']->addCustomer('cus_stored_first');
            BillingAccount::factory()->create(['workspace_id' => $workspaceId, 'stripe_customer_id' => 'cus_stored_first']);
        });

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();

        expect(BillingAccount::query()->where('workspace_id', $workspaceId)->pluck('stripe_customer_id')->all())->toBe(['cus_stored_first'])
            ->and(array_values($team['stripe']->sessions)[0]['customer'])->toBe('cus_stored_first');
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
