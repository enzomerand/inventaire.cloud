<?php

declare(strict_types=1);

// Helpers shared by the tests of billing. Each test file loads them with require_once, so a file can be run alone. Their names all start
// with "billing" so that they never collide with the helpers of tests/Pest.php or of other test files.

use App\Models\BillingAccount;
use App\Models\Workspace;
use Tests\Support\FakeStripe;

/**
 * An instance that sells plans, and a Stripe for it: the secret key, the signing secret and the two Prices are set, and the network is a
 * {@see FakeStripe} that answers like the real one and refuses any other request.
 *
 * @param  array<string, mixed>  $config  Settings to put over the defaults, by name (`'services.stripe.prices.yearly' => null`).
 */
function billingOn(array $config = []): FakeStripe
{
    config($config + [
        'services.stripe.secret' => FakeStripe::SECRET,
        'services.stripe.webhook_secret' => FakeStripe::WEBHOOK_SECRET,
        'services.stripe.prices.monthly' => FakeStripe::PRICE_MONTHLY,
        'services.stripe.prices.yearly' => FakeStripe::PRICE_YEARLY,
    ]);

    return FakeStripe::install();
}

/**
 * An instance that sells nothing, which is how every other test starts: no key, no Price.
 */
function billingOff(): void
{
    config([
        'services.stripe.secret' => null,
        'services.stripe.webhook_secret' => null,
        'services.stripe.prices.monthly' => null,
        'services.stripe.prices.yearly' => null,
    ]);
}

/**
 * A workspace with the billing account a subscription in a status gives it; without a status, a workspace that never started a
 * checkout (no account at all).
 *
 * @param  string|null  $status  Stripe's word for the subscription: `active`, `trialing`, `past_due`, `canceled`...
 */
function billingWorkspace(?string $status = null, string $interval = 'month'): Workspace
{
    $workspace = Workspace::factory()->create();

    if ($status !== null) {
        BillingAccount::factory()->for($workspace)->subscribed($status, $interval)->create();
    }

    return $workspace;
}

/**
 * The account of a workspace whose customer Stripe knows, as it is before anything was synchronised: no subscription is recorded, and
 * `$subscribed` gives the customer one at Stripe (the account does not know it until it is synchronised).
 *
 * @param  string|null  $status  The status of the subscription Stripe has for the customer; none when null.
 * @param  array<string, mixed>  $attributes  Columns to put over the account's defaults.
 */
function billingAccountAt(FakeStripe $stripe, ?string $status = null, string $interval = 'month', array $attributes = []): BillingAccount
{
    $account = BillingAccount::factory()->for(Workspace::factory())->create($attributes);
    $stripe->addCustomer($account->stripe_customer_id);

    if ($status !== null) {
        $stripe->subscribe($account->stripe_customer_id, $status, $interval);
    }

    return $account;
}

/**
 * The `Stripe-Signature` header of a raw body, signed with the signing secret of the endpoint (see {@see FakeStripe::signedEvent()}, which
 * does it for an event).
 *
 * @param  string|null  $secret  The signing secret; the right one when null, a wrong one is how a forgery is made.
 * @param  int|null  $timestamp  When it was signed, as a Unix timestamp; now when null.
 */
function billingSign(string $payload, ?string $secret = null, ?int $timestamp = null): string
{
    $timestamp ??= time();

    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? FakeStripe::WEBHOOK_SECRET);
}
