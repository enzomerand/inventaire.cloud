<?php

declare(strict_types=1);

use App\Billing\BillingCustomers;
use App\Billing\PreviousOwnerSubscriptionException;
use App\Models\BillingAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeStripe;

require_once __DIR__.'/../../Support/billing.php';
require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/*
 * The customer at Stripe is the one of the owner who pays, not of the workspace: a workspace that changed hands does not hand its customer
 * (the email, the invoices, the cards of the owner before) to the new owner. While the subscription of the owner before is in play the new
 * owner is refused; once it is over they get a customer of their own and become the payer.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * A workspace that changed hands: the account is paid by the owner before, who is not a member any more, and the owner now is somebody
 * else. The Stripe of the test knows the customer of the account and its subscription.
 *
 * @return array{owner: User, previous: User, workspace: Workspace, stripe: FakeStripe, account: BillingAccount, customer: string}
 */
function billingPayerHandedOver(?string $status = 'active', bool $cancelAtPeriodEnd = true): array
{
    $stripe = billingOn();
    $previous = inventoryPerson('Previous owner');
    $owner = inventoryPerson('New owner');
    $workspace = app(Workspaces::class)->create($previous, 'Shop');
    $workspace->members()->attach($owner->id, ['role' => 'owner']);
    $workspace->members()->detach($previous->id);
    $account = BillingAccount::factory()->for($workspace)->paidBy($previous)->create(['stripe_customer_id' => 'cus_previous']);
    $stripe->addCustomer('cus_previous', $previous->email, 'Shop', $workspace->ulid);

    if ($status !== null) {
        $periodEnd = now()->addDays(20)->startOfSecond();
        $stripe->subscribe('cus_previous', $status, 'month', $periodEnd->getTimestamp(), $cancelAtPeriodEnd);
    }

    return ['owner' => $owner, 'previous' => $previous, 'workspace' => $workspace, 'stripe' => $stripe, 'account' => $account, 'customer' => 'cus_previous'];
}

/**
 * The URI of an endpoint of the subscription of a workspace.
 */
function billingPayerUri(Workspace $workspace, string $endpoint): string
{
    return "/api/v1/workspaces/{$workspace->ulid}/billing/{$endpoint}";
}

describe('Checkout by an owner who is not the payer', function (): void {
    it('is refused while the subscription of the payer is in play, whatever its status, and touches nothing', function (string $status): void {
        $world = billingPayerHandedOver($status);

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'previous_owner_subscription');

        // The customer of the owner before is left as it is: not given the email of the new owner, not replaced, no session for it.
        $account = $world['account']->fresh();
        expect($account->stripe_customer_id)->toBe('cus_previous')
            ->and($account->payer_id)->toBe($world['previous']->id)
            ->and($world['stripe']->customers['cus_previous']['email'])->toBe($world['previous']->email)
            ->and($world['stripe']->calls)->toBe(['GET /v1/checkout/sessions', 'GET /v1/subscriptions'])
            ->and($world['stripe']->count('POST /v1/customers'))->toBe(0)
            ->and($world['stripe']->count('POST /v1/checkout/sessions'))->toBe(0);
    })->with(['active', 'trialing', 'past_due', 'unpaid', 'paused', 'incomplete']);

    it('says when the subscription ends, as the owner before has it set to end with the period', function (): void {
        $world = billingPayerHandedOver('active', true);
        $periodEnd = now()->addDays(20)->startOfSecond();

        $answer = inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'month'])->assertStatus(409);

        $date = $periodEnd->copy()->setTimezone(config('app.display_timezone'))->locale('en')->isoFormat('LL');
        expect($answer->json('message'))->toBe("The subscription of the previous owner of this workspace runs until {$date}. You can subscribe once it has ended.")
            ->and($answer->json('ends_at'))->toBe($periodEnd->utc()->toIso8601String());
    });

    it('says it in French, with the date', function (): void {
        $world = billingPayerHandedOver('active', true);
        $this->useLanguage('fr');

        $answer = inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'month'], ['Accept-Language' => 'fr'])->assertStatus(409);

        expect($answer->json('message'))->toStartWith("L'abonnement du précédent propriétaire de cet espace de travail court jusqu'au ")
            ->and($answer->json('message'))->toEndWith("Vous pourrez vous abonner lorsqu'il sera terminé.");
    });

    it('does not give a date for a subscription that is not set to end', function (): void {
        $world = billingPayerHandedOver('active', false);

        $answer = inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'month'])->assertStatus(409);

        expect($answer->json('message'))->toBe('The subscription of the previous owner of this workspace is still running. You can subscribe once it has ended.')
            ->and($answer->json('ends_at'))->toBeNull();
    });

    it('goes by Stripe and not by the row: a subscription the row does not know yet stands in the way too', function (): void {
        $world = billingPayerHandedOver('active');
        // The row remembers a subscription that is over; Stripe has a live one.
        $world['account']->forceFill(['status' => 'canceled', 'stripe_subscription_id' => 'sub_old', 'synced_at' => now()->subDay()])->save();

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'previous_owner_subscription');
    });

    it('gets a customer of their own once the subscription of the payer is over, and becomes the payer', function (string $status): void {
        Log::spy();
        $world = billingPayerHandedOver($status, false);
        $world['stripe']->sessions['cs_old'] = ['id' => 'cs_old', 'customer' => 'cus_previous', 'status' => 'open'];

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'year'])->assertOk();

        $account = BillingAccount::query()->where('workspace_id', $world['workspace']->id)->sole();
        $session = array_values(array_filter($world['stripe']->sessions, fn (array $session): bool => $session['id'] !== 'cs_old'))[0];
        expect($account->stripe_customer_id)->not->toBe('cus_previous')
            ->and($account->payer_id)->toBe($world['owner']->id)
            ->and([$account->stripe_subscription_id, $account->status, $account->price_id, $account->interval, $account->current_period_end, $account->cancel_at_period_end, $account->synced_at])
            ->toBe([null, null, null, null, null, false, null])
            ->and($world['stripe']->customers[$account->stripe_customer_id])->toMatchArray([
                'email' => $world['owner']->email,
                'name' => 'Shop',
                'metadata' => ['workspace' => $world['workspace']->ulid],
            ])
            ->and($session['customer'])->toBe($account->stripe_customer_id)
            ->and($session['line_items'][0]['price'])->toBe(FakeStripe::PRICE_YEARLY)
            // The old customer is not touched: its email, its subscription and its invoices stay, and the page that was open on it is expired.
            ->and($world['stripe']->customers['cus_previous']['email'])->toBe($world['previous']->email)
            ->and($world['stripe']->count('POST /v1/customers/{id}'))->toBe(0)
            ->and($world['stripe']->sessions['cs_old']['status'])->toBe('expired')
            ->and(BillingAccount::query()->count())->toBe(1);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => ($context['customer'] ?? null) === 'cus_previous'
            && ($context['replaced_by'] ?? null) === $account->stripe_customer_id
            && ($context['reason'] ?? null) === 'new_owner'
            && ($context['workspace'] ?? null) === $world['workspace']->ulid)->once();
    })->with(['canceled', 'incomplete_expired']);

    it('gets a customer of their own when the workspace had a customer and never a subscription', function (): void {
        $world = billingPayerHandedOver(null);

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();

        expect($world['account']->fresh()->payer_id)->toBe($world['owner']->id)
            ->and($world['account']->fresh()->stripe_customer_id)->not->toBe('cus_previous');
    });

    it('is a payer like any other afterwards: the customer is kept, its email follows, and the owner before is the one who is refused', function (): void {
        $world = billingPayerHandedOver('canceled', false);
        $uri = billingPayerUri($world['workspace'], 'checkout');
        inventoryCall($this, $world['owner'], 'POST', $uri, ['interval' => 'month'])->assertOk();
        $customer = $world['account']->fresh()->stripe_customer_id;
        $world['stripe']->customers[$customer]['email'] = 'changed@example.test';

        inventoryCall($this, $world['owner'], 'POST', $uri, ['interval' => 'month'])->assertOk();

        expect($world['account']->fresh()->stripe_customer_id)->toBe($customer)
            ->and($world['stripe']->customers[$customer]['email'])->toBe($world['owner']->email);

        // The new payer subscribes; the owner before comes back as an owner and is not the payer.
        $world['stripe']->subscribe($customer, 'active');
        $world['workspace']->members()->attach($world['previous']->id, ['role' => 'owner']);

        inventoryCall($this, $world['previous'], 'POST', $uri, ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'previous_owner_subscription');
        // And the payer is told what the workspace has: a subscription of their own.
        inventoryCall($this, $world['owner'], 'POST', $uri, ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_subscribed');
    });

    it('treats an account whose payer is unknown (the account of the payer was deleted) as one that has another payer', function (): void {
        $world = billingPayerHandedOver('active');
        DB::table('users')->where('id', $world['previous']->id)->delete();

        expect($world['account']->fresh()->payer_id)->toBeNull();

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'previous_owner_subscription');
    });

    it('is refused all the same when the subscription turns up between the read and the new customer', function (): void {
        $world = billingPayerHandedOver('canceled', false);
        // A webhook wrote a live subscription after the read of the checkout and before the new customer is stored.
        $world['stripe']->onCall('POST /v1/customers', fn () => $world['account']->forceFill(['status' => 'active', 'stripe_subscription_id' => 'sub_late'])->save());

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'previous_owner_subscription');

        $account = $world['account']->fresh();
        expect($account->stripe_customer_id)->toBe('cus_previous')
            ->and($account->stripe_subscription_id)->toBe('sub_late')
            ->and($account->payer_id)->toBe($world['previous']->id)
            ->and($world['stripe']->count('POST /v1/checkout/sessions'))->toBe(0);
    });

    it('makes the new customer with a key made of the workspace, of the customer it replaces and of what is sent, and no email', function (): void {
        $world = billingPayerHandedOver('canceled', false);

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();

        $key = $world['stripe']->requestsOf('POST /v1/customers')[0]->header('Idempotency-Key')[0];
        $sent = substr(hash('sha256', implode("\n", [$world['owner']->email, $world['workspace']->name, 'en'])), 0, 16);

        expect($key)->toBe('customer-'.$world['workspace']->ulid.'-'.substr(hash('sha256', 'cus_previous'), 0, 16).'-'.$sent)
            ->and($key)->not->toContain($world['owner']->email);
    });

    it('is told the account is gone when the owner was deleted while the new customer was made, and the old customer stays', function (): void {
        $world = billingPayerHandedOver('canceled', false);
        $world['stripe']->onCall('POST /v1/customers', fn () => DB::table('users')->where('id', $world['owner']->id)->delete());

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'month'])->assertNotFound();

        expect($world['account']->fresh()->stripe_customer_id)->toBe('cus_previous')
            ->and($world['account']->fresh()->payer_id)->toBe($world['previous']->id);
    });

    it('is asked to try again when another owner replaced the customer first: the customer that was stored is theirs, the one made for nothing is in the log', function (): void {
        Log::spy();
        $world = billingPayerHandedOver('canceled', false);
        $second = inventoryPerson('Second owner');
        $world['workspace']->members()->attach($second->id, ['role' => 'owner']);
        // The second owner's request swaps the old customer while the first one is making its own.
        $world['stripe']->onCall('POST /v1/customers', function () use ($world, $second): void {
            $world['stripe']->addCustomer('cus_swapped_first');
            $world['account']->forceFill(['stripe_customer_id' => 'cus_swapped_first', 'payer_id' => $second->id])->save();
        });

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'checkout_in_progress');

        expect($world['account']->fresh()->only(['stripe_customer_id', 'payer_id']))->toBe(['stripe_customer_id' => 'cus_swapped_first', 'payer_id' => $second->id])
            ->and($world['stripe']->count('POST /v1/checkout/sessions'))->toBe(0);
        $orphan = array_values(array_diff(array_keys($world['stripe']->customers), ['cus_previous', 'cus_swapped_first']));
        expect($orphan)->toHaveCount(1);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'is not kept')
            && ($context['customer'] ?? null) === $orphan[0]
            && ($context['kept'] ?? null) === 'cus_swapped_first'
            && ($context['workspace'] ?? null) === $world['workspace']->ulid)->once();
    });

    it('is refused as before when the customer another owner replaced first already has a subscription in play', function (): void {
        $world = billingPayerHandedOver('canceled', false);
        $second = inventoryPerson('Second owner');
        $world['workspace']->members()->attach($second->id, ['role' => 'owner']);
        $world['stripe']->onCall('POST /v1/customers', function () use ($world, $second): void {
            $world['stripe']->addCustomer('cus_swapped_first');
            $world['account']->forceFill(['stripe_customer_id' => 'cus_swapped_first', 'payer_id' => $second->id, 'status' => 'active', 'stripe_subscription_id' => 'sub_second'])->save();
        });

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'previous_owner_subscription');
    });

    it('is told the workspace is gone when it was deleted while the new customer was made', function (): void {
        $world = billingPayerHandedOver('canceled', false);
        $world['stripe']->onCall('POST /v1/customers', fn () => DB::table('workspaces')->where('id', $world['workspace']->id)->delete());

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'checkout'), ['interval' => 'month'])->assertNotFound();
    });
});

describe('the portal for an owner who is not the payer', function (): void {
    it('is refused while the subscription of the payer is in play: the invoices of somebody else are not shown', function (string $status): void {
        $world = billingPayerHandedOver($status);

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'portal'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'previous_owner_subscription');

        expect($world['stripe']->calls)->toBe(['GET /v1/subscriptions'])
            ->and($world['stripe']->portals)->toBe([])
            ->and($world['stripe']->customers['cus_previous']['email'])->toBe($world['previous']->email)
            ->and($world['account']->fresh()->payer_id)->toBe($world['previous']->id);
    })->with(['active', 'past_due', 'trialing']);

    it('reads the subscription now: the row may be hours old', function (): void {
        $world = billingPayerHandedOver('active');
        $world['account']->forceFill(['status' => 'canceled', 'synced_at' => now()->subHours(5)])->save();

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'portal'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'previous_owner_subscription');
    });

    it('opens the portal of a customer of their own once the subscription is over', function (): void {
        $world = billingPayerHandedOver('canceled', false);

        $answer = inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'portal'))->assertOk();

        $account = $world['account']->fresh();
        $portal = array_values($world['stripe']->portals)[0];
        expect($account->stripe_customer_id)->not->toBe('cus_previous')
            ->and($account->payer_id)->toBe($world['owner']->id)
            ->and($portal['customer'])->toBe($account->stripe_customer_id)
            ->and($answer->json('data.url'))->toBe('https://billing.stripe.com/session/'.$portal['id'])
            // Never the portal of the customer of the owner before.
            ->and(array_column($world['stripe']->portals, 'customer'))->not->toContain('cus_previous')
            ->and($world['stripe']->customers['cus_previous']['email'])->toBe($world['previous']->email);
    });

    it('is refused if a subscription turns up between the read and the new customer', function (): void {
        $world = billingPayerHandedOver('canceled', false);
        $world['stripe']->onCall('POST /v1/customers', fn () => $world['account']->forceFill(['status' => 'active', 'stripe_subscription_id' => 'sub_late'])->save());

        inventoryCall($this, $world['owner'], 'POST', billingPayerUri($world['workspace'], 'portal'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'previous_owner_subscription');

        expect($world['stripe']->portals)->toBe([])
            ->and($world['account']->fresh()->stripe_customer_id)->toBe('cus_previous');
    });

    it('is the same as before for the payer: the customer is kept and gets their email', function (): void {
        $world = billingPayerHandedOver('active');
        $world['workspace']->members()->attach($world['previous']->id, ['role' => 'owner']);

        inventoryCall($this, $world['previous'], 'POST', billingPayerUri($world['workspace'], 'portal'))->assertOk();

        expect($world['stripe']->calls)->toBe(['POST /v1/customers/{id}', 'POST /v1/billing_portal/sessions'])
            ->and($world['account']->fresh()->stripe_customer_id)->toBe('cus_previous');
    });
});

describe('who is the payer', function (): void {
    it('is the owner who started the checkout: a workspace that never had a customer makes one for them', function (): void {
        billingOn();
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'POST', billingPayerUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();

        expect(BillingAccount::query()->sole()->payer_id)->toBe($team['owner']->id);
    });

    it('is the owner whose request stored the customer first, when two owners start a checkout together: the other is asked to try again', function (): void {
        Log::spy();
        $stripe = billingOn();
        $team = inventoryTeam();
        $second = inventoryPerson('Second owner');
        $team['workspace']->members()->attach($second->id, ['role' => 'owner']);
        // The second owner's request stores its customer while the first one is making its own.
        $stripe->onCall('POST /v1/customers', function () use ($team, $second, $stripe): void {
            $stripe->addCustomer('cus_stored_first');
            BillingAccount::factory()->paidBy($second)->create(['workspace_id' => $team['workspace']->id, 'stripe_customer_id' => 'cus_stored_first']);
        });

        // The customer that was stored is the second owner's: the first is not handed it, and may try again.
        inventoryCall($this, $team['owner'], 'POST', billingPayerUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'checkout_in_progress');

        expect(BillingAccount::query()->sole()->payer_id)->toBe($second->id)
            ->and($stripe->count('POST /v1/checkout/sessions'))->toBe(0);
        // The customer this request made at Stripe, which nothing keeps, is written in the log.
        $orphan = array_values(array_diff(array_keys($stripe->customers), ['cus_stored_first']));
        expect($orphan)->toHaveCount(1);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'is not kept')
            && ($context['customer'] ?? null) === $orphan[0]
            && ($context['kept'] ?? null) === 'cus_stored_first'
            && ($context['workspace'] ?? null) === $team['workspace']->ulid)->once();
    });

    it('is known by the service, which compares the user and not their position', function (): void {
        $account = BillingAccount::factory()->create();
        $user = User::factory()->create();
        $service = app(BillingCustomers::class);

        expect($service->isPayer($account, $user))->toBeFalse();

        $account->forceFill(['payer_id' => $user->id])->save();

        expect($service->isPayer($account->fresh(), $user))->toBeTrue()
            ->and($service->isPayer($account->fresh(), User::factory()->create()))->toBeFalse();
    });

    it('is refused a customer by the service too: an owner who is not the payer gets the exception', function (): void {
        $world = billingPayerHandedOver('active');
        // The service goes by the row (the checkout synchronises it just before).
        $world['account']->forceFill(['status' => 'active', 'stripe_subscription_id' => 'sub_x'])->save();

        expect(fn () => app(BillingCustomers::class)->ensure($world['workspace'], $world['owner'], 'en'))->toThrow(PreviousOwnerSubscriptionException::class);

        expect($world['stripe']->count('POST /v1/customers'))->toBe(0)
            ->and($world['account']->fresh()->stripe_customer_id)->toBe('cus_previous');
    });
});
