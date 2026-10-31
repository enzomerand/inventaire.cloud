<?php

declare(strict_types=1);

namespace App\Billing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The calls this application makes to Stripe, through its REST API (`https://api.stripe.com/v1/*`), without Stripe's library.
 *
 * Every call carries the secret key as a bearer token and the version of the API the code is written for (`Stripe-Version`), waits 20
 * seconds for an answer and 5 to connect, and follows no redirect: the key must not travel to another host. Requests that create or change
 * something are form-encoded (as Stripe wants them) and carry an `Idempotency-Key`: the caller's, so that a call it repeats after a failure
 * is not done twice, or else a random one. A call that does not succeed throws a {@see StripeException}, which says whether trying again
 * can help; the client itself never retries, the jobs that call it do (with the pace of the queue). Neither the key nor anything about the
 * customer is ever written in a message or a log.
 */
final class StripeClient
{
    /** Seconds Stripe may take to answer. */
    public const TIMEOUT = 20;

    /** Seconds to establish the connection. */
    private const CONNECT_TIMEOUT = 5;

    /** How many subscriptions a page of the list holds (the most Stripe gives). */
    private const PAGE_SIZE = 100;

    /** How many pages of subscriptions are read at most: a customer with more than that is not a workspace, and is refused rather than cut short. */
    public const MAX_PAGES = 10;

    /**
     * Creates the Stripe customer of a workspace.
     *
     * @param  string  $email  The address Stripe sends the receipts to: the one of the owner of the workspace.
     * @param  string  $name  The name on the invoices: the one of the workspace.
     * @param  string  $workspaceUlid  Written in the metadata of the customer, for a human who reads the dashboard.
     * @param  string  $locale  The language of the person, for the emails and invoices Stripe sends.
     * @param  string|null  $idempotencyKey  The key that makes a repeated call create one customer; a random one when null.
     * @return string The id of the customer (`cus_…`).
     *
     * @throws StripeException
     */
    public function createCustomer(string $email, string $name, string $workspaceUlid, string $locale, ?string $idempotencyKey = null): string
    {
        $call = 'POST /v1/customers';
        $response = $this->post($call, '/v1/customers', [
            'email' => $email,
            'name' => $name,
            'metadata' => ['workspace' => $workspaceUlid],
            'preferred_locales' => [$locale],
        ], $idempotencyKey);

        return $this->identifier($call, $response);
    }

    /**
     * Gives a customer another email address (the owner of the workspace changed).
     *
     * @throws StripeException
     */
    public function updateCustomer(string $customerId, string $email): void
    {
        $this->post('POST /v1/customers/{id}', '/v1/customers/'.rawurlencode($customerId), ['email' => $email]);
    }

    /**
     * Creates a Checkout session. The parameters are built by {@see CheckoutParameters::make()}.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{id: string, url: string} The id of the session and the address of the page Stripe hosts, to send the person to.
     *
     * @throws StripeException
     */
    public function createCheckoutSession(array $parameters): array
    {
        $call = 'POST /v1/checkout/sessions';
        $response = $this->post($call, '/v1/checkout/sessions', $parameters);

        return ['id' => $this->identifier($call, $response), 'url' => $this->address($call, $response)];
    }

    /**
     * Creates a session of the customer portal, where the customer changes their card, reads their invoices or cancels.
     *
     * With `$cancelSubscription` the session is a **deep link to the cancellation of that subscription** (`flow_data[type]=subscription_cancel`):
     * the person lands on the page that asks them to confirm, with the rest of the portal hidden, and is brought back to
     * `$afterCancellationUrl` once it is done (`flow_data[after_completion][type]=redirect`). The portal must have cancellation enabled in its
     * configuration, otherwise Stripe refuses the session (`400`).
     *
     * @param  string  $returnUrl  Where the portal's "back" link leads.
     * @param  string  $locale  The language of the person.
     * @param  string|null  $cancelSubscription  The subscription (`sub_…`) the person is taken to cancel; the whole portal when null.
     * @param  string|null  $afterCancellationUrl  Where the person is brought back once the cancellation is done; `$returnUrl` when null.
     * @return string The address of the portal page, to send the person to.
     *
     * @throws StripeException
     */
    public function createPortalSession(string $customerId, string $returnUrl, string $locale, ?string $cancelSubscription = null, ?string $afterCancellationUrl = null): string
    {
        $call = 'POST /v1/billing_portal/sessions';
        $response = $this->post($call, '/v1/billing_portal/sessions', [
            'customer' => $customerId,
            'return_url' => $returnUrl,
            'locale' => CheckoutParameters::locale($locale),
            'flow_data' => $cancelSubscription === null ? null : [
                'type' => 'subscription_cancel',
                'subscription_cancel' => ['subscription' => $cancelSubscription],
                'after_completion' => ['type' => 'redirect', 'redirect' => ['return_url' => $afterCancellationUrl ?? $returnUrl]],
            ],
        ]);

        return $this->address($call, $response);
    }

    /**
     * Reads the subscriptions of a customer, the canceled ones included, most recent first. A workspace has one at a time, but a customer
     * that was through many checkouts can have more than a page of them: the pages (a hundred each) are followed until Stripe says there
     * is no more, so that no live subscription is missed. Each is the subscription object of the API: `id`, `status`,
     * `cancel_at_period_end`, `created` and `items.data[]` with the `price` and the `current_period_end` of the item.
     *
     * @return list<array<string, mixed>>
     *
     * @throws StripeException Also, and not worth a new try, when there are still more after {@see self::MAX_PAGES} pages: a list that is
     *                         cut short would silently miss a subscription, so none is given.
     */
    public function subscriptionsOf(string $customerId): array
    {
        return $this->listAll('GET /v1/subscriptions', '/v1/subscriptions', ['customer' => $customerId, 'status' => 'all'], 'subscriptions');
    }

    /**
     * The latest invoice of a subscription that was paid (`GET /v1/invoices?subscription=…&status=paid&limit=1`, which Stripe sorts newest
     * first): what the customer was last charged for it, to work out what a withdrawal gives back. Null when none was paid.
     *
     * @return array{id: string, amount_paid: int, currency: string}|null The amount in the smallest unit of the currency, the currency in lowercase.
     *
     * @throws StripeException Also when the invoice that is answered has no usable amount or currency.
     */
    public function latestPaidInvoice(string $subscriptionId): ?array
    {
        $call = 'GET /v1/invoices';
        $response = $this->send($call, fn (PendingRequest $request): Response => $request
            ->get(Billing::baseUrl().'/v1/invoices', ['subscription' => $subscriptionId, 'status' => 'paid', 'limit' => 1]));
        $data = $response->json('data');

        if (! is_array($data) || ! array_is_list($data)) {
            throw StripeException::invalidResponse($call, 'no list of invoices');
        }

        if ($data === []) {
            return null;
        }

        $invoice = $data[0];
        $id = is_array($invoice) ? ($invoice['id'] ?? null) : null;
        $amount = is_array($invoice) ? ($invoice['amount_paid'] ?? null) : null;
        $currency = is_array($invoice) ? ($invoice['currency'] ?? null) : null;

        if (! is_string($id) || preg_match('/^[A-Za-z0-9_]{1,255}\z/', $id) !== 1 || ! is_int($amount) || $amount < 0 || ! is_string($currency) || preg_match('/^[A-Za-z]{3}\z/', $currency) !== 1) {
            throw StripeException::invalidResponse($call, 'an invoice with no usable id, amount or currency');
        }

        return ['id' => $id, 'amount_paid' => $amount, 'currency' => strtolower($currency)];
    }

    /**
     * The ids of the Checkout sessions of a customer that are still open: a page of Stripe that somebody may yet pay on. Read before a new
     * session is opened, to end those ({@see self::expireCheckoutSession()}) so that two tabs cannot both end in a subscription.
     *
     * @return list<string>
     *
     * @throws StripeException
     */
    public function openCheckoutSessionsOf(string $customerId): array
    {
        $call = 'GET /v1/checkout/sessions';
        $ids = [];

        foreach ($this->listAll($call, '/v1/checkout/sessions', ['customer' => $customerId, 'status' => 'open'], 'checkout sessions') as $session) {
            $id = $session['id'] ?? null;

            if (! is_string($id) || preg_match('/^[A-Za-z0-9_]{1,255}\z/', $id) !== 1) {
                throw StripeException::invalidResponse($call, 'a checkout session with no usable id');
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Ends a Checkout session that is open: its page says it has expired, and nobody can pay on it any more. Refused by Stripe (`400`)
     * when the session is not open any more, which is why the caller reads the subscriptions again if this fails: the payment may have
     * been made in the meantime.
     *
     * @throws StripeException
     */
    public function expireCheckoutSession(string $sessionId): void
    {
        $this->post('POST /v1/checkout/sessions/{id}/expire', '/v1/checkout/sessions/'.rawurlencode($sessionId).'/expire', []);
    }

    /**
     * Cancels a subscription now: the customer is not charged again, and gets no refund for the period that began.
     *
     * @throws StripeException With a status of 404 when the subscription is gone.
     */
    public function cancelSubscription(string $subscriptionId): void
    {
        $this->send('DELETE /v1/subscriptions/{id}', fn (PendingRequest $request): Response => $request
            ->delete(Billing::baseUrl().'/v1/subscriptions/'.rawurlencode($subscriptionId)));
    }

    /**
     * Has a subscription end by itself at the end of the period that is paid for: the customer keeps Pro until then and is not charged
     * again.
     *
     * @throws StripeException
     */
    public function cancelAtPeriodEnd(string $subscriptionId, ?string $idempotencyKey = null): void
    {
        $this->post('POST /v1/subscriptions/{id}', '/v1/subscriptions/'.rawurlencode($subscriptionId), ['cancel_at_period_end' => true], $idempotencyKey);
    }

    /**
     * Reads every object of a list of the API, a page at a time (a hundred), most recent first, following `starting_after` until Stripe
     * says (`has_more`) there is no more.
     *
     * @param  string  $call  What is asked, `METHOD /path`, for the messages.
     * @param  string  $path  The path of the endpoint.
     * @param  array<string, string>  $filters  The query of the first page.
     * @param  string  $what  What the list holds, for the messages.
     * @return list<array<string, mixed>>
     *
     * @throws StripeException When the answer is not a list, or when there are still more after {@see self::MAX_PAGES} pages (not worth
     *                         a new try): a list that is cut short would silently miss an object, so none is given.
     */
    private function listAll(string $call, string $path, array $filters, string $what): array
    {
        $objects = [];
        $after = null;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $query = $filters + ['limit' => self::PAGE_SIZE] + ($after === null ? [] : ['starting_after' => $after]);
            $response = $this->send($call, fn (PendingRequest $request): Response => $request->get(Billing::baseUrl().$path, $query));
            $data = $response->json('data');

            if (! is_array($data) || ! array_is_list($data) || array_filter($data, fn (mixed $one): bool => ! is_array($one)) !== []) {
                throw StripeException::invalidResponse($call, "no list of {$what}");
            }

            /** @var list<array<string, mixed>> $data */
            $objects = [...$objects, ...$data];

            if ($response->json('has_more') !== true) {
                return $objects;
            }

            $after = $data === [] ? null : ($data[array_key_last($data)]['id'] ?? null);

            if (! is_string($after) || preg_match('/^[A-Za-z0-9_]{1,255}\z/', $after) !== 1) {
                throw StripeException::invalidResponse($call, "a page of {$what} to continue from that has no usable id");
            }
        }

        throw StripeException::invalidResponse($call, 'more than '.(self::MAX_PAGES * self::PAGE_SIZE)." {$what}");
    }

    /**
     * Makes a call that creates or changes something, with its parameters form-encoded and its idempotency key.
     *
     * @param  string  $call  What is asked, `METHOD /path` with the ids written `{id}`, for the messages.
     * @param  string  $path  The path of the endpoint.
     * @param  array<string, mixed>  $parameters
     * @param  string|null  $idempotencyKey  A random one when null.
     *
     * @throws StripeException
     */
    private function post(string $call, string $path, array $parameters, ?string $idempotencyKey = null): Response
    {
        return $this->send($call, fn (PendingRequest $request): Response => $request
            ->asForm()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid()])
            ->post(Billing::baseUrl().$path, self::encodable($parameters)));
    }

    /**
     * Makes a call and holds its answer to success.
     *
     * @param  string  $call  What is asked, `METHOD /path`, for the messages.
     * @param  callable(PendingRequest): Response  $make  Makes the call with the request that is given.
     *
     * @throws StripeException When Stripe is not configured, cannot be reached or does not answer with a success.
     */
    private function send(string $call, callable $make): Response
    {
        $secret = Billing::secret();

        if ($secret === '') {
            throw StripeException::notConfigured();
        }

        $request = Http::withToken($secret)
            ->withHeaders(['Stripe-Version' => Billing::apiVersion()])
            ->acceptJson()
            ->timeout(self::TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->withoutRedirecting();

        try {
            $response = $make($request);
        } catch (ConnectionException $exception) {
            throw StripeException::unreachable($exception);
        }

        if (! $response->successful()) {
            throw StripeException::fromResponse($call, $response);
        }

        return $response;
    }

    /**
     * The `id` of the object Stripe answered with.
     *
     * @throws StripeException When there is none, or it is not made of the characters of an id.
     */
    private function identifier(string $call, Response $response): string
    {
        $id = $response->json('id');

        if (! is_string($id) || preg_match('/^[A-Za-z0-9_]{1,255}\z/', $id) !== 1) {
            throw StripeException::invalidResponse($call, 'no usable id');
        }

        return $id;
    }

    /**
     * The `url` of the page Stripe hosts (a Checkout or portal session), which the person is sent to.
     *
     * @throws StripeException When there is none, or it is not an https address on a host of Stripe (`stripe.com` or one of its
     *                         subdomains, `checkout.stripe.com` and `billing.stripe.com` in practice): the person is redirected to
     *                         whatever is returned, and the answer is not to be trusted with sending them anywhere else.
     */
    private function address(string $call, Response $response): string
    {
        $url = $response->json('url');

        if (! is_string($url) || ! self::isStripePage($url)) {
            throw StripeException::invalidResponse($call, 'no usable url');
        }

        return $url;
    }

    /**
     * Whether an address is an `https` page on `stripe.com` or one of its subdomains, with no credentials and no port, and nothing in it
     * that a browser could read differently from this check (control characters, spaces, backslashes).
     */
    private static function isStripePage(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');

        return strtolower($parts['scheme'] ?? '') === 'https' && preg_match('/^([a-z0-9-]+\.)*stripe\.com\z/', $host) === 1;
    }

    /**
     * The parameters as Stripe reads a form: no null, and the booleans written `true` and `false` (the query builder of PHP would write
     * `1` and `0`).
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private static function encodable(array $parameters): array
    {
        $encoded = [];

        foreach ($parameters as $key => $value) {
            if ($value === null) {
                continue;
            }

            $encoded[$key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_array($value) => self::encodable($value),
                default => $value,
            };
        }

        return $encoded;
    }
}
