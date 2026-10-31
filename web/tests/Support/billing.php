<?php

declare(strict_types=1);

// Helpers shared by the tests of billing. Each test file loads them with require_once, so a file can be run alone. Their names all start
// with "billing" so that they never collide with the helpers of tests/Pest.php or of other test files.

use App\Models\BillingAccount;
use App\Models\Workspace;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\FakeStripe;

/**
 * The legal identity of a complete seller, as `config/legal.php` holds it: every required detail is filled in, the capital too.
 *
 * @return array<string, string>
 */
function billingSellerSettings(): array
{
    return [
        'legal.seller.name' => 'Palan Test',
        'legal.seller.form' => 'SAS',
        'legal.seller.capital' => '1 000 euros',
        'legal.seller.address' => '1 rue de Test, 44000 Nantes',
        'legal.seller.registration' => 'RCS Nantes 123 456 789',
        'legal.seller.vat_number' => 'FR12345678901',
        'legal.seller.email' => 'ventes@example.test',
        'legal.seller.phone' => '+33 2 00 00 00 00',
        'legal.seller.mediator_name' => 'Médiateur Test',
        'legal.seller.mediator_address' => '2 rue du Médiateur, 75000 Paris',
        'legal.seller.mediator_url' => 'https://mediateur.example.test',
    ];
}

/**
 * Gives the instance a complete seller identity, apart from the details named in `$blank` (set to an empty text): the way a test makes
 * the seller incomplete (`billingSeller('vat_number')`).
 *
 * @param  string  ...$blank  The keys of `legal.seller.*` to leave blank (`vat_number`, `mediator_url`...).
 */
function billingSeller(string ...$blank): void
{
    $settings = billingSellerSettings();

    foreach ($blank as $key) {
        $settings["legal.seller.{$key}"] = '';
    }

    config($settings);
}

/**
 * An instance that sells plans, and a Stripe for it: the secret key, the signing secret and the two Prices are set, the identity of the
 * seller is complete (a sale needs valid terms), and the network is a {@see FakeStripe} that answers like the real one and refuses any
 * other request. `billingSeller('vat_number')` makes the seller incomplete afterwards: the instance then sells nothing.
 *
 * @param  array<string, mixed>  $config  Settings to put over the defaults, by name (`'services.stripe.prices.yearly' => null`).
 */
function billingOn(array $config = []): FakeStripe
{
    config($config + billingSellerSettings() + [
        'services.stripe.secret' => FakeStripe::SECRET,
        'services.stripe.webhook_secret' => FakeStripe::WEBHOOK_SECRET,
        'services.stripe.prices.monthly' => FakeStripe::PRICE_MONTHLY,
        'services.stripe.prices.yearly' => FakeStripe::PRICE_YEARLY,
    ]);

    return FakeStripe::install();
}

/**
 * An instance that previews its plans without Stripe: nothing is bought, but the pricing page, the terms and the pricing section of the
 * home page exist (`BILLING_PRICING_PREVIEW`).
 */
function billingPreview(): void
{
    billingOff();
    config(['billing.pricing_preview' => true]);
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
 * The `Stripe-Signature` header of a raw body, signed with the signing secret of the endpoint.
 *
 * @param  string|null  $secret  The signing secret; the right one when null, a wrong one is how a forgery is made.
 * @param  int|null  $timestamp  When it was signed, as a Unix timestamp; now when null.
 */
function billingSign(string $payload, ?string $secret = null, ?int $timestamp = null): string
{
    $timestamp ??= time();

    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? FakeStripe::WEBHOOK_SECRET);
}

/**
 * The billing configuration as the environment gives it, evaluated again with the variables swapped for the duration of the call (the
 * application read its own when it booted).
 *
 * @param  array<string, string|null>  $variables  The variables to set; null removes one.
 * @param  string  $file  The configuration file to evaluate (`billing.php`; `legal.php` for the identity of the seller).
 * @return array<string, mixed>
 */
function billingConfigRead(array $variables, string $file = 'billing.php'): array
{
    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);

        if ($value !== null) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }
    }

    try {
        /** @var array<string, mixed> $config */
        $config = require config_path($file);

        return $config;
    } finally {
        foreach ($previous as $name => [$env, $server, $process]) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            if ($env !== null) {
                $_ENV[$name] = $env;
            }

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            if ($process !== false) {
                putenv("{$name}={$process}");
            }
        }
    }
}

/**
 * Has a worker of the database queue take the next job that is due and give it one try.
 */
function billingWorkOnce(object $test): void
{
    // The memory of the worker is the one of the whole test run: the default limit of the command (128 MB) would stop it.
    $test->artisan('queue:work', ['--once' => true, '--memory' => 8192])->assertSuccessful();
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
 * A mail transport that keeps what it sends like the array mailer does, except for some recipients, whose mail it refuses with an
 * exception (a mail server that is down for them), so that a mail that cannot be sent is tested end to end.
 */
final class BillingBreakingTransport extends ArrayTransport
{
    /**
     * @param  list<string>  $addresses  The recipients whose mail fails; every recipient when empty.
     */
    public function __construct(private readonly array $addresses = [])
    {
        parent::__construct();
    }

    /**
     * Keeps the message, or fails for the recipients it was told to fail for.
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $recipient = $message instanceof Email ? ($message->getTo()[0] ?? null)?->getAddress() : null;

        if ($this->addresses === [] || in_array($recipient, $this->addresses, true)) {
            throw new TransportException('The mail is broken on purpose.');
        }

        return parent::send($message, $envelope);
    }
}

/**
 * Makes the mail of some addresses (all of them when none is given) fail to go out, the others being kept like the array mailer keeps them:
 * `sentMails()` lists those.
 *
 * @param  list<string>  $addresses  The recipients whose mail fails.
 */
function billingMailBreaksFor(array $addresses = []): void
{
    Mail::extend('billing-breaking', fn () => new BillingBreakingTransport($addresses));
    config(['mail.mailers.billing-breaking' => ['transport' => 'billing-breaking'], 'mail.default' => 'billing-breaking']);
    app('mail.manager')->purge();
}
