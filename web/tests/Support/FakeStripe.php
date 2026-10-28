<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A Stripe for the tests, behind `Http::fake()`: it answers the calls of the API that the application makes (`/v1/customers`,
 * `/v1/checkout/sessions`, `/v1/billing_portal/sessions`, `/v1/subscriptions`) the way the real one does, with the state of
 * a few customers and subscriptions, and it records every call. Any other request is refused (`Http::preventStrayRequests()`).
 *
 * A test drives it: it gives a customer a subscription in a status, changes that status, price or period end the way the customer would in
 * the portal, makes the next calls of a kind fail (with a status, or as a connection that is lost), hooks what happens while a call is
 * being answered, and signs the events it posts to the webhook ({@see self::signedEvent()}).
 *
 * A request that repeats an `Idempotency-Key` gets the answer of the first one without doing anything again, and one that changes
 * the parameters under the same key is refused (`400`, `idempotency_error`), as Stripe does.
 */
final class FakeStripe
{
    /** The secret key the tests give the instance (see `billingOn()`): a call that carries another one is refused with `401`. */
    public const SECRET = 'sk_test_fake_secret_key';

    /** The signing secret of the webhook endpoint the tests give the instance. */
    public const WEBHOOK_SECRET = 'whsec_fake_signing_secret';

    /** The Stripe Price of the monthly plan the tests give the instance. */
    public const PRICE_MONTHLY = 'price_monthly_test';

    /** The Stripe Price of the yearly plan the tests give the instance. */
    public const PRICE_YEARLY = 'price_yearly_test';

    /** @var array<string, array<string, mixed>> The customers Stripe has, by id, as Stripe gives them. */
    public array $customers = [];

    /** @var array<string, array{id: string, customer: string, status: string, price: string, interval: string, current_period_end: int, cancel_at_period_end: bool, created: int, metadata: array<string, mixed>}> The subscriptions, by id. */
    public array $subscriptions = [];

    /** @var array<string, array<string, mixed>> The Checkout sessions that were created, by id, with the parameters they were created with. */
    public array $sessions = [];

    /** @var array<string, true> The customers that were deleted at Stripe ({@see self::loseCustomer()}), by id. */
    public array $lostCustomers = [];

    /** @var array<string, array<string, mixed>> The portal sessions that were created, by id, with the parameters they were created with. */
    public array $portals = [];

    /** @var list<Request> Every request, in order. */
    public array $requests = [];

    /** @var list<array<string, mixed>> The options each request was made with (`timeout`, `connect_timeout`...), in the same order. */
    public array $options = [];

    /** @var list<string> What each request was, in order (`POST /v1/customers`). */
    public array $calls = [];

    /** @var array<string, list<int|null>> The failures to give for the next calls of a kind: a status, or null for a lost connection. */
    private array $failures = [];

    /** @var array<string, Closure(Request): void> What runs while a call of a kind is being answered, before the answer. */
    private array $hooks = [];

    /** @var mixed What every call is answered with instead of the usual answers, once set (see {@see self::answerWith()}). */
    private mixed $override = null;

    /** @var array<string, array{fingerprint: string, status: int, body: array<string, mixed>}> The answers given to the idempotency keys seen. */
    private array $answered = [];

    /** How many objects were made, to give each an id of its own. */
    private int $counter = 0;

    /** The fake of the network this Stripe answers for: a test is a new application, and so a new one. */
    private static ?object $network = null;

    /** The Stripe installed on that network. */
    private static ?self $installed = null;

    /**
     * Fakes the network with a Stripe, and refuses every request that does not match. Installing it again in the same test gives the same
     * one back: the first fake of the network to match a request is the one that answers, so a second would never be asked.
     */
    public static function install(): self
    {
        $network = Http::getFacadeRoot();

        if (self::$installed !== null && self::$network === $network) {
            return self::$installed;
        }

        $fake = new self;
        Http::fake(fn (Request $request, array $options = []) => $fake->respond($request, $options));
        Http::preventStrayRequests();

        self::$network = $network;
        self::$installed = $fake;

        return $fake;
    }

    /**
     * Signs an event the way Stripe does when it posts it to the webhook: the payload is the JSON of the event, the header is
     * `t=<timestamp>,v1=<HMAC-SHA256 of "<timestamp>.<payload>">`.
     *
     * @param  array<string, mixed>  $event  See {@see self::event()}.
     * @param  string  $secret  The signing secret of the endpoint; a wrong one gives a signature the application must refuse.
     * @param  int|null  $timestamp  When it was signed, as a Unix timestamp; now when null.
     * @return array{0: string, 1: string} The raw body and the value of the `Stripe-Signature` header.
     */
    public static function signedEvent(array $event, string $secret = self::WEBHOOK_SECRET, ?int $timestamp = null): array
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp ??= time();

        return [$payload, "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret)];
    }

    /**
     * An event as Stripe posts it to the webhook: its id and type, and the object it is about.
     *
     * @param  array<string, mixed>  $object  The `data.object` of the event (a subscription, an invoice, a charge...).
     * @return array<string, mixed>
     */
    public static function event(string $type, array $object, ?string $id = null): array
    {
        return [
            'id' => $id ?? 'evt_'.bin2hex(random_bytes(8)),
            'object' => 'event',
            'api_version' => '2026-09-30.endive',
            'created' => time(),
            'type' => $type,
            'livemode' => false,
            'data' => ['object' => $object],
        ];
    }

    /**
     * Makes the next `$times` calls of a kind fail.
     *
     * @param  string  $call  `METHOD /path` with the ids written `{id}`: `POST /v1/customers`, `GET /v1/subscriptions`, `DELETE /v1/subscriptions/{id}`.
     * @param  int|null  $status  The HTTP status to answer with, or null for a connection that is lost.
     */
    public function failNext(string $call, ?int $status, int $times = 1): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures[$call][] = $status;
        }

        return $this;
    }

    /**
     * Answers every call from now on with something of one's own, as the raw network would (a redirect, a page that is not JSON, an odd
     * body), still recording it. The answer is anything `Http::response()` builds.
     */
    public function answerWith(mixed $answer): self
    {
        $this->override = $answer;

        return $this;
    }

    /**
     * Runs something while a call of a kind is answered (before the answer, and before a failure it would be given).
     *
     * @param  Closure(Request): void  $hook
     */
    public function onCall(string $call, Closure $hook): self
    {
        $this->hooks[$call] = $hook;

        return $this;
    }

    /**
     * Declares that Stripe has a customer, as a test that did not go through its creation needs it.
     */
    public function addCustomer(string $id, string $email = 'owner@example.test', string $name = 'Workspace', ?string $workspaceUlid = null): self
    {
        $this->customers[$id] = [
            'id' => $id,
            'object' => 'customer',
            'email' => $email,
            'name' => $name,
            'metadata' => $workspaceUlid === null ? [] : ['workspace' => $workspaceUlid],
            'preferred_locales' => [],
        ];

        return $this;
    }

    /**
     * Deletes a customer at Stripe, as it is when the keys of the instance are those of another mode or account: every call that names it
     * (updating it, opening a session or the portal for it, listing its subscriptions or its Checkout sessions) answers `resource_missing`.
     * Without this the fake lists no subscription for a customer it does not know, which is what a customer that exists without
     * subscriptions looks like.
     */
    public function loseCustomer(string $id): self
    {
        unset($this->customers[$id]);
        $this->lostCustomers[$id] = true;

        return $this;
    }

    /**
     * Gives a customer a subscription (the customer is declared if Stripe did not have it).
     *
     * @param  string  $status  Stripe's word: `active`, `trialing`, `past_due`, `unpaid`, `paused`, `incomplete`, `canceled`...
     * @param  string  $interval  `month` or `year`: it picks the Price.
     * @param  int|null  $periodEnd  When the period that is paid for ends, as a Unix timestamp; a month from now when null.
     * @return string The id of the subscription.
     */
    public function subscribe(string $customerId, string $status = 'active', string $interval = 'month', ?int $periodEnd = null, bool $cancelAtPeriodEnd = false, ?string $id = null): string
    {
        if (! isset($this->customers[$customerId])) {
            $this->addCustomer($customerId);
        }

        $id ??= 'sub_test'.++$this->counter;
        $this->subscriptions[$id] = [
            'id' => $id,
            'customer' => $customerId,
            'status' => $status,
            'price' => $interval === 'year' ? self::PRICE_YEARLY : self::PRICE_MONTHLY,
            'interval' => $interval,
            'current_period_end' => $periodEnd ?? now()->addMonth()->getTimestamp(),
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'created' => now()->getTimestamp() + $this->counter,
            'metadata' => [],
        ];

        return $id;
    }

    /**
     * Changes a subscription the way the customer or Stripe would: its `status`, `price`, `interval`, `current_period_end`,
     * `cancel_at_period_end`.
     *
     * @param  array<string, mixed>  $changes
     */
    public function change(string $subscriptionId, array $changes): self
    {
        $this->subscriptions[$subscriptionId] = $changes + $this->subscriptions[$subscriptionId];

        return $this;
    }

    /**
     * Gives a subscription another status.
     */
    public function setStatus(string $subscriptionId, string $status): self
    {
        return $this->change($subscriptionId, ['status' => $status]);
    }

    /**
     * Gives a subscription another end of period (a Unix timestamp).
     */
    public function setPeriodEnd(string $subscriptionId, int $periodEnd): self
    {
        return $this->change($subscriptionId, ['current_period_end' => $periodEnd]);
    }

    /**
     * Moves a subscription to the Price of an interval.
     */
    public function setInterval(string $subscriptionId, string $interval): self
    {
        return $this->change($subscriptionId, ['interval' => $interval, 'price' => $interval === 'year' ? self::PRICE_YEARLY : self::PRICE_MONTHLY]);
    }

    /**
     * The customer completes a Checkout session: it is not open any more (and the subscription it bought is up to the test to declare,
     * see {@see self::subscribe()}).
     */
    public function completeCheckout(string $sessionId): self
    {
        $this->sessions[$sessionId]['status'] = 'complete';

        return $this;
    }

    /**
     * The subscriptions of a customer, most recent first, in their own words (see {@see self::subscriptionObject()}).
     *
     * @return list<array<string, mixed>>
     */
    public function subscriptionsOf(string $customerId): array
    {
        $own = array_filter($this->subscriptions, fn (array $subscription): bool => $subscription['customer'] === $customerId);
        usort($own, fn (array $a, array $b): int => $b['created'] <=> $a['created']);

        return array_map(fn (array $subscription): array => $this->subscriptionObject($subscription), $own);
    }

    /**
     * The requests of a kind, in order.
     *
     * @return list<Request>
     */
    public function requestsOf(string $call): array
    {
        $found = [];

        foreach ($this->calls as $index => $made) {
            if ($made === $call) {
                $found[] = $this->requests[$index];
            }
        }

        return $found;
    }

    /**
     * How many requests of a kind were made.
     */
    public function count(string $call): int
    {
        return count($this->requestsOf($call));
    }

    /**
     * The seconds the last request of a kind was allowed to take (the `timeout` it was made with); null when there was none.
     */
    public function timeoutOf(string $call): ?int
    {
        $found = null;

        foreach ($this->calls as $index => $made) {
            if ($made === $call) {
                $found = $this->options[$index]['timeout'] ?? null;
            }
        }

        return $found === null ? null : (int) $found;
    }

    /**
     * Answers a request.
     */
    public function respond(Request $request, array $options = []): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (! str_starts_with($path, '/v1/')) {
            return $this->error(404, 'invalid_request_error', 'resource_missing');
        }

        $call = $request->method().' '.$this->generic($path);
        $this->requests[] = $request;
        $this->options[] = $options;
        $this->calls[] = $call;

        if ($this->override !== null) {
            return $this->override;
        }

        if (($request->header('Authorization')[0] ?? null) !== 'Bearer '.self::SECRET) {
            // The real one repeats the start of the key in its message: nothing of the application may repeat it in turn.
            return $this->error(401, 'invalid_request_error', null, 'Invalid API Key provided: '.substr((string) ($request->header('Authorization')[0] ?? ''), 7, 12).'****');
        }

        if (isset($this->hooks[$call])) {
            ($this->hooks[$call])($request);
        }

        if (($this->failures[$call] ?? []) !== []) {
            $status = array_shift($this->failures[$call]);

            return $status === null
                ? Http::failedConnection('cURL error 28: Operation timed out after 20001 milliseconds')
                : $this->failure($status);
        }

        $key = $request->method() === 'POST' ? ($request->header('Idempotency-Key')[0] ?? null) : null;
        $fingerprint = $call.'?'.$request->body();

        if (is_string($key) && isset($this->answered[$key])) {
            $before = $this->answered[$key];

            return $before['fingerprint'] === $fingerprint
                ? Http::response($before['body'], $before['status'], ['Idempotent-Replayed' => 'true'])
                : $this->error(400, 'idempotency_error', null, 'Keys for idempotent requests can only be used with the same parameters they were first used with.');
        }

        $answer = match ($call) {
            'POST /v1/customers' => $this->createCustomer($request),
            'POST /v1/customers/{id}' => $this->updateCustomer($request, $path),
            'POST /v1/checkout/sessions' => $this->createSession($request),
            'POST /v1/billing_portal/sessions' => $this->createPortal($request),
            'GET /v1/checkout/sessions' => $this->listSessions($request),
            'POST /v1/checkout/sessions/{id}/expire' => $this->expireSession($path),
            'GET /v1/subscriptions' => $this->listSubscriptions($request),
            'POST /v1/subscriptions/{id}' => $this->updateSubscription($request, $path),
            'DELETE /v1/subscriptions/{id}' => $this->deleteSubscription($path),
            default => ['status' => 404, 'body' => ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'Unrecognized request URL.']]],
        };

        if (is_string($key) && $answer['status'] < 300) {
            $this->answered[$key] = ['fingerprint' => $fingerprint] + $answer;
        }

        return Http::response($answer['body'], $answer['status'], ['Request-Id' => 'req_fake'.count($this->calls)]);
    }

    /**
     * The path with its ids written `{id}`.
     */
    private function generic(string $path): string
    {
        return (string) preg_replace(
            ['#^/v1/(customers|subscriptions)/[^/]+$#', '#^/v1/checkout/sessions/[^/]+/expire$#'],
            ['/v1/$1/{id}', '/v1/checkout/sessions/{id}/expire'],
            $path,
        );
    }

    /**
     * The last segment of a path: the id of the object it names.
     */
    private function idIn(string $path): string
    {
        return rawurldecode(basename($path));
    }

    /**
     * Creates a customer from the form.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function createCustomer(Request $request): array
    {
        $data = $request->data();
        $id = 'cus_test'.++$this->counter;
        $this->addCustomer($id, (string) ($data['email'] ?? ''), (string) ($data['name'] ?? ''), $data['metadata']['workspace'] ?? null);
        $this->customers[$id]['preferred_locales'] = array_values($data['preferred_locales'] ?? []);

        return ['status' => 200, 'body' => $this->customers[$id]];
    }

    /**
     * Changes the email of a customer.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function updateCustomer(Request $request, string $path): array
    {
        $id = $this->idIn($path);

        if (! isset($this->customers[$id])) {
            return $this->missing('customer', $id);
        }

        $this->customers[$id]['email'] = (string) ($request->data()['email'] ?? $this->customers[$id]['email']);

        return ['status' => 200, 'body' => $this->customers[$id]];
    }

    /**
     * Creates a Checkout session: it must name a customer Stripe has.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function createSession(Request $request): array
    {
        $data = $request->data();
        $customer = (string) ($data['customer'] ?? '');

        if (! isset($this->customers[$customer])) {
            return $this->missing('customer', $customer);
        }

        $id = 'cs_test_'.++$this->counter;
        $this->sessions[$id] = $data + ['id' => $id, 'status' => 'open'];

        return ['status' => 200, 'body' => ['id' => $id, 'object' => 'checkout.session', 'url' => "https://checkout.stripe.com/c/pay/{$id}", 'customer' => $customer, 'mode' => 'subscription', 'status' => 'open']];
    }

    /**
     * Creates a portal session: it must name a customer Stripe has.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function createPortal(Request $request): array
    {
        $data = $request->data();
        $customer = (string) ($data['customer'] ?? '');

        if (! isset($this->customers[$customer])) {
            return $this->missing('customer', $customer);
        }

        $id = 'bps_test_'.++$this->counter;
        $this->portals[$id] = $data + ['id' => $id];

        return ['status' => 200, 'body' => ['id' => $id, 'object' => 'billing_portal.session', 'url' => "https://billing.stripe.com/session/{$id}", 'customer' => $customer]];
    }

    /**
     * Lists the subscriptions of a customer, a page at a time (`limit`, `starting_after`, `has_more`): without `status=all` the canceled ones are left out, as Stripe does.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function listSubscriptions(Request $request): array
    {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $customer = (string) ($query['customer'] ?? '');

        if (isset($this->lostCustomers[$customer])) {
            return $this->missing('customer', $customer);
        }

        $subscriptions = $this->subscriptionsOf($customer);

        if (($query['status'] ?? null) !== 'all') {
            $wanted = $query['status'] ?? null;
            $subscriptions = array_values(array_filter(
                $subscriptions,
                fn (array $subscription): bool => $wanted === null ? $subscription['status'] !== 'canceled' : $subscription['status'] === $wanted,
            ));
        }

        return $this->page($subscriptions, $query, '/v1/subscriptions');
    }

    /**
     * Lists the Checkout sessions of a customer, a page at a time, the most recent first; `status` filters (`open`, `complete`, `expired`).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function listSessions(Request $request): array
    {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (isset($this->lostCustomers[(string) ($query['customer'] ?? '')])) {
            return $this->missing('customer', (string) $query['customer']);
        }

        $sessions = array_filter(
            array_reverse(array_values($this->sessions)),
            fn (array $session): bool => ($session['customer'] ?? null) === ($query['customer'] ?? null) && (! isset($query['status']) || $session['status'] === $query['status']),
        );
        $sessions = array_map(fn (array $session): array => [
            'id' => $session['id'],
            'object' => 'checkout.session',
            'customer' => $session['customer'],
            'status' => $session['status'],
            'mode' => 'subscription',
        ], array_values($sessions));

        return $this->page($sessions, $query, '/v1/checkout/sessions');
    }

    /**
     * Expires a Checkout session; Stripe refuses one that is not open any more (`400`, with no `code`).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function expireSession(string $path): array
    {
        $id = rawurldecode(basename(dirname($path)));

        if (! isset($this->sessions[$id])) {
            return $this->missing('checkout.session', $id);
        }

        if ($this->sessions[$id]['status'] !== 'open') {
            return ['status' => 400, 'body' => ['error' => ['type' => 'invalid_request_error', 'message' => 'Only Checkout Sessions with a status in (open) can be expired.']]];
        }

        $this->sessions[$id]['status'] = 'expired';

        return ['status' => 200, 'body' => ['id' => $id, 'object' => 'checkout.session', 'customer' => $this->sessions[$id]['customer'] ?? null, 'status' => 'expired']];
    }

    /**
     * A page of a list, the way Stripe pages: from the one after `starting_after` (an id), `limit` at a time (10 when not asked), and
     * whether more follow.
     *
     * @param  list<array<string, mixed>>  $objects  Everything the list holds, in order.
     * @param  array<string, mixed>  $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function page(array $objects, array $query, string $url): array
    {
        if (isset($query['starting_after'])) {
            $position = array_search($query['starting_after'], array_column($objects, 'id'), true);

            if ($position === false) {
                return $this->missing('object', (string) $query['starting_after']);
            }

            $objects = array_slice($objects, $position + 1);
        }

        $limit = (int) ($query['limit'] ?? 10);

        return ['status' => 200, 'body' => ['object' => 'list', 'url' => $url, 'has_more' => count($objects) > $limit, 'data' => array_slice($objects, 0, $limit)]];
    }

    /**
     * Updates a subscription: only `cancel_at_period_end` is understood.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function updateSubscription(Request $request, string $path): array
    {
        $id = $this->idIn($path);

        if (! isset($this->subscriptions[$id])) {
            return $this->missing('subscription', $id);
        }

        if (isset($request->data()['cancel_at_period_end'])) {
            $this->subscriptions[$id]['cancel_at_period_end'] = $request->data()['cancel_at_period_end'] === 'true';
        }

        return ['status' => 200, 'body' => $this->subscriptionObject($this->subscriptions[$id])];
    }

    /**
     * Cancels a subscription now: it stays, `canceled`; one that is not there, or already canceled, is a `404`.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function deleteSubscription(string $path): array
    {
        $id = $this->idIn($path);

        if (! isset($this->subscriptions[$id]) || $this->subscriptions[$id]['status'] === 'canceled') {
            return $this->missing('subscription', $id);
        }

        $this->subscriptions[$id]['status'] = 'canceled';

        return ['status' => 200, 'body' => $this->subscriptionObject($this->subscriptions[$id])];
    }

    /**
     * A subscription as Stripe gives it (since the version of the API of 2025: the period is on the item).
     *
     * @param  array{id: string, customer: string, status: string, price: string, interval: string, current_period_end: int, cancel_at_period_end: bool, created: int, metadata: array<string, mixed>}  $subscription
     * @return array<string, mixed>
     */
    private function subscriptionObject(array $subscription): array
    {
        return [
            'id' => $subscription['id'],
            'object' => 'subscription',
            'customer' => $subscription['customer'],
            'status' => $subscription['status'],
            'cancel_at_period_end' => $subscription['cancel_at_period_end'],
            'created' => $subscription['created'],
            'metadata' => $subscription['metadata'],
            'items' => [
                'object' => 'list',
                'has_more' => false,
                'data' => [[
                    'id' => 'si_'.substr($subscription['id'], 4),
                    'object' => 'subscription_item',
                    'price' => [
                        'id' => $subscription['price'],
                        'object' => 'price',
                        'recurring' => ['interval' => $subscription['interval'], 'interval_count' => 1],
                    ],
                    'quantity' => 1,
                    'current_period_start' => $subscription['current_period_end'] - 2_592_000,
                    'current_period_end' => $subscription['current_period_end'],
                ]],
            ],
        ];
    }

    /**
     * The answer to an object that is not there.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function missing(string $kind, string $id): array
    {
        return ['status' => 404, 'body' => ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => "No such {$kind}: '{$id}'", 'param' => $kind]]];
    }

    /**
     * An error answer.
     */
    private function error(int $status, string $type, ?string $code, string $message = 'Failed on purpose'): mixed
    {
        return Http::response(['error' => array_filter(['type' => $type, 'code' => $code, 'message' => $message])], $status);
    }

    /**
     * The answer to a call that is made to fail with a status.
     */
    private function failure(int $status): mixed
    {
        return match (true) {
            $status === 404 => $this->error(404, 'invalid_request_error', 'resource_missing'),
            $status === 429 => $this->error(429, 'invalid_request_error', 'rate_limit'),
            $status >= 500 => $this->error($status, 'api_error', null),
            default => $this->error($status, 'invalid_request_error', 'parameter_invalid_empty'),
        };
    }
}
