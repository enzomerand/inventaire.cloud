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
    public function updateCustomer(string $customerId, string $email, ?string $idempotencyKey = null): void
    {
        $this->post('POST /v1/customers/{id}', '/v1/customers/'.rawurlencode($customerId), ['email' => $email], $idempotencyKey);
    }

    /**
     * Creates a Checkout session. The parameters are built by {@see CheckoutParameters::make()}.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{id: string, url: string} The id of the session and the address of the page Stripe hosts, to send the person to.
     *
     * @throws StripeException
     */
    public function createCheckoutSession(array $parameters, ?string $idempotencyKey = null): array
    {
        $call = 'POST /v1/checkout/sessions';
        $response = $this->post($call, '/v1/checkout/sessions', $parameters, $idempotencyKey);

        return ['id' => $this->identifier($call, $response), 'url' => $this->address($call, $response)];
    }

    /**
     * Creates a session of the customer portal, where the customer changes their card, reads their invoices or cancels.
     *
     * @param  string  $returnUrl  Where the portal's "back" link leads.
     * @param  string  $locale  The language of the person.
     * @return string The address of the portal page, to send the person to.
     *
     * @throws StripeException
     */
    public function createPortalSession(string $customerId, string $returnUrl, string $locale, ?string $idempotencyKey = null): string
    {
        $call = 'POST /v1/billing_portal/sessions';
        $response = $this->post($call, '/v1/billing_portal/sessions', [
            'customer' => $customerId,
            'return_url' => $returnUrl,
            'locale' => CheckoutParameters::locale($locale),
        ], $idempotencyKey);

        return $this->address($call, $response);
    }

    /**
     * Reads the subscriptions of a customer, the canceled ones included, most recent first (at most ten: a workspace has one at a time).
     * Each is the subscription object of the API: `id`, `status`, `cancel_at_period_end`, `created` and `items.data[]` with the `price`
     * and the `current_period_end` of the item.
     *
     * @return list<array<string, mixed>>
     *
     * @throws StripeException
     */
    public function subscriptionsOf(string $customerId): array
    {
        $call = 'GET /v1/subscriptions';
        $response = $this->send($call, fn (PendingRequest $request): Response => $request
            ->get(Billing::baseUrl().'/v1/subscriptions', ['customer' => $customerId, 'status' => 'all', 'limit' => 10]));
        $subscriptions = $response->json('data');

        if (! is_array($subscriptions) || ! array_is_list($subscriptions) || array_filter($subscriptions, fn (mixed $one): bool => ! is_array($one)) !== []) {
            throw StripeException::invalidResponse($call, 'no list of subscriptions');
        }

        /** @var list<array<string, mixed>> $subscriptions */
        return $subscriptions;
    }

    /**
     * Reads a charge (what a customer paid), to tell who and what a refund or a dispute is about.
     *
     * @return array<string, mixed>
     *
     * @throws StripeException With a status of 404 when the charge is not there.
     */
    public function retrieveCharge(string $chargeId): array
    {
        $call = 'GET /v1/charges/{id}';
        $response = $this->send($call, fn (PendingRequest $request): Response => $request
            ->get(Billing::baseUrl().'/v1/charges/'.rawurlencode($chargeId)));
        $charge = $response->json();

        if (! is_array($charge) || ! is_string($charge['id'] ?? null)) {
            throw StripeException::invalidResponse($call, 'no charge');
        }

        return $charge;
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
     * Makes a call that creates or changes something, with its parameters form-encoded and its idempotency key.
     *
     * @param  string  $call  What is asked, `METHOD /path` with the ids written `{id}`, for the messages.
     * @param  string  $path  The path of the endpoint.
     * @param  array<string, mixed>  $parameters
     *
     * @throws StripeException
     */
    private function post(string $call, string $path, array $parameters, ?string $idempotencyKey): Response
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

        if (! is_string($id) || preg_match('/^[A-Za-z0-9_]{1,255}$/', $id) !== 1) {
            throw StripeException::invalidResponse($call, 'no usable id');
        }

        return $id;
    }

    /**
     * The `url` of the page Stripe hosts (a Checkout or portal session), which the person is sent to.
     *
     * @throws StripeException When there is none, or it is not an https address.
     */
    private function address(string $call, Response $response): string
    {
        $url = $response->json('url');

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw StripeException::invalidResponse($call, 'no usable url');
        }

        return $url;
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
