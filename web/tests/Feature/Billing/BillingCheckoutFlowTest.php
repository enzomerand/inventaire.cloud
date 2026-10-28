<?php

declare(strict_types=1);

use App\Models\BillingAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeStripe;

require_once __DIR__.'/../../Support/billing.php';
require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/*
 * How a checkout is opened for a workspace: one at a time (an atomic lock of the cache, held while Stripe is called), in the order
 * expire what is open, read the subscriptions, create the session, and a session that expires within the half hour.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * A team whose workspace has an account for a subscription in a status (none: no account), paid by the owner, and a Stripe that knows it.
 *
 * @return array{owner: User, editor: User, viewer: User, stranger: User, workspace: Workspace, stripe: FakeStripe, account: BillingAccount|null}
 */
function billingFlowTeam(?string $status = 'canceled'): array
{
    $stripe = billingOn();
    $team = inventoryTeam();
    $account = null;

    if ($status !== null) {
        $account = BillingAccount::factory()->for($team['workspace'])->paidBy($team['owner'])->subscribed($status)->create();
        $stripe->addCustomer($account->stripe_customer_id, 'owner@example.test');
        $stripe->subscribe($account->stripe_customer_id, $status);
    }

    return $team + ['stripe' => $stripe, 'account' => $account];
}

/**
 * The checkout of a workspace, asked for by its owner.
 */
function billingFlowCheckout(object $test, array $team, string $interval = 'month'): TestResponse
{
    return inventoryCall($test, $team['owner'], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/billing/checkout", ['interval' => $interval]);
}

describe('one checkout at a time for a workspace', function (): void {
    it('refuses a request while another one is opening a payment page for the same workspace, and does nothing', function (): void {
        $team = billingFlowTeam();
        $lock = Cache::lock('billing-checkout:'.$team['workspace']->ulid, 60);
        expect($lock->get())->toBeTrue();

        billingFlowCheckout($this, $team)
            ->assertStatus(409)
            ->assertJsonPath('code', 'checkout_in_progress')
            ->assertJsonPath('message', 'A payment page is already being opened for this workspace. Try again in a moment.');

        // Nothing was asked of Stripe and nothing was written; the lock of the other request is still theirs.
        expect($team['stripe']->calls)->toBe([])
            ->and($team['account']->fresh()->stripe_customer_id)->toBe($team['account']->stripe_customer_id)
            ->and(Cache::lock('billing-checkout:'.$team['workspace']->ulid, 60)->get())->toBeFalse();

        $lock->release();
        billingFlowCheckout($this, $team)->assertOk();
    });

    it('answers in French', function (): void {
        $team = billingFlowTeam();
        $this->useLanguage('fr');
        Cache::lock('billing-checkout:'.$team['workspace']->ulid, 60)->get();

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/billing/checkout", ['interval' => 'month'], ['Accept-Language' => 'fr'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'checkout_in_progress')
            ->assertJsonPath('message', "Une page de paiement est déjà en cours d'ouverture pour cet espace de travail. Réessayez dans un instant.");
    });

    it('holds the lock while Stripe is called, in no transaction of the database, and only for its own workspace', function (): void {
        $team = billingFlowTeam();
        $other = billingFlowTeam();
        $base = DB::transactionLevel();
        $held = [];
        $check = function () use ($team, $other, $base, &$held): void {
            $held[] = [
                'mine' => Cache::lock('billing-checkout:'.$team['workspace']->ulid, 1)->get(),
                'other' => ($lock = Cache::lock('billing-checkout:'.$other['workspace']->ulid, 1))->get(),
                'transactions' => DB::transactionLevel() === $base,
            ];
            $lock->release();
        };
        $team['stripe']->onCall('GET /v1/checkout/sessions', $check)->onCall('GET /v1/subscriptions', $check)->onCall('POST /v1/checkout/sessions', $check);

        billingFlowCheckout($this, $team)->assertOk();

        expect($held)->toHaveCount(3)
            ->and(array_unique(array_column($held, 'mine')))->toBe([false])
            ->and(array_unique(array_column($held, 'other')))->toBe([true])
            ->and(array_unique(array_column($held, 'transactions')))->toBe([true]);
    });

    it('lets go of the lock whatever the request came to', function (string $case): void {
        $team = billingFlowTeam($case === 'a subscription' ? 'active' : 'canceled');
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/billing/checkout";

        match ($case) {
            'a failure of Stripe' => $team['stripe']->failNext('POST /v1/checkout/sessions', 500),
            'a workspace that is gone' => $team['stripe']->onCall('GET /v1/subscriptions', fn () => DB::table('workspaces')->where('id', $team['workspace']->id)->delete()),
            default => null,
        };

        $answer = inventoryCall($this, $team['owner'], 'POST', $uri, ['interval' => 'month']);

        expect($answer->status())->toBeIn([200, 404, 409, 503])
            ->and(Cache::lock('billing-checkout:'.$team['workspace']->ulid, 60)->get())->toBeTrue();
    })->with(['a success', 'a failure of Stripe', 'a subscription', 'a workspace that is gone']);

    it('holds the lock for three minutes if the request lasts that long: longer than the five Stripe calls it makes, 20 seconds each at the most', function (): void {
        $team = billingFlowTeam();
        $held = [];
        $team['stripe']->onCall('POST /v1/checkout/sessions', function () use ($team, &$held): void {
            $this->travel(170)->seconds();
            $held['after 170 s'] = ! Cache::lock('billing-checkout:'.$team['workspace']->ulid, 1)->get();
            $this->travel(15)->seconds();
            $held['after 185 s'] = ! Cache::lock('billing-checkout:'.$team['workspace']->ulid, 1)->get();
        });

        billingFlowCheckout($this, $team)->assertOk();

        // Still the request's at 170 seconds (the whole of the calls it can make), gone for good soon after: a process that died does not
        // block the workspace for long.
        expect($held)->toBe(['after 170 s' => true, 'after 185 s' => false]);
    });

    it('does not hold on to a lock for good: it ends after a minute', function (): void {
        $team = billingFlowTeam();
        Cache::lock('billing-checkout:'.$team['workspace']->ulid, 60)->get();

        $this->travel(61)->seconds();

        billingFlowCheckout($this, $team)->assertOk();
    });

    it('takes the lock only once the request is known to be one that goes to Stripe', function (): void {
        $team = billingFlowTeam();
        Cache::lock('billing-checkout:'.$team['workspace']->ulid, 60)->get();

        // A wrong interval is told so, whoever else is checking out.
        billingFlowCheckout($this, $team, 'week')->assertStatus(422)->assertJsonValidationErrors('interval');
    });
});

describe('the order of a checkout', function (): void {
    it('expires what is open, then reads the subscriptions, then creates the session', function (): void {
        $team = billingFlowTeam();
        $team['stripe']->sessions['cs_open'] = ['id' => 'cs_open', 'customer' => $team['account']->stripe_customer_id, 'status' => 'open'];

        billingFlowCheckout($this, $team)->assertOk();

        expect($team['stripe']->calls)->toBe([
            'GET /v1/checkout/sessions',
            'POST /v1/checkout/sessions/{id}/expire',
            'GET /v1/subscriptions',
            'POST /v1/customers/{id}',
            'POST /v1/checkout/sessions',
        ])
            ->and($team['stripe']->sessions['cs_open']['status'])->toBe('expired');
    });

    it('sees a subscription that a session paid on while it was expired, and refuses', function (): void {
        $team = billingFlowTeam();
        $customer = $team['account']->stripe_customer_id;
        $team['stripe']->sessions['cs_open'] = ['id' => 'cs_open', 'customer' => $customer, 'status' => 'open'];
        $team['stripe']->onCall('POST /v1/checkout/sessions/{id}/expire', function () use ($team, $customer): void {
            $team['stripe']->completeCheckout('cs_open');
            $team['stripe']->subscribe($customer, 'active');
        });

        billingFlowCheckout($this, $team)->assertStatus(409)->assertJsonPath('code', 'already_subscribed');

        expect($team['stripe']->count('POST /v1/checkout/sessions'))->toBe(0)
            ->and($team['account']->fresh()->status)->toBe('active');
    });

    it('continues, without a spurious failure, when the session it could not expire ended by itself', function (): void {
        $team = billingFlowTeam();
        $team['stripe']->sessions['cs_open'] = ['id' => 'cs_open', 'customer' => $team['account']->stripe_customer_id, 'status' => 'open'];
        $team['stripe']->onCall('POST /v1/checkout/sessions/{id}/expire', fn () => $team['stripe']->sessions['cs_open']['status'] = 'expired');

        billingFlowCheckout($this, $team)->assertOk();

        expect($team['stripe']->count('POST /v1/checkout/sessions'))->toBe(1);
    });

    it('makes a customer first for a workspace that has none, and has nothing to expire or read for it', function (): void {
        $team = billingFlowTeam(null);

        billingFlowCheckout($this, $team)->assertOk();

        expect($team['stripe']->calls)->toBe(['POST /v1/customers', 'POST /v1/checkout/sessions']);
    });
});

describe('the session', function (): void {
    it('expires as soon as Stripe allows, so that a page left open cannot be paid long after the workspace is gone', function (): void {
        $team = billingFlowTeam();
        $this->travelTo(now()->startOfSecond());

        billingFlowCheckout($this, $team)->assertOk();
        $session = array_values($team['stripe']->sessions)[0];

        expect((int) $session['expires_at'] - now()->getTimestamp())->toBe(1860);
    });
});
