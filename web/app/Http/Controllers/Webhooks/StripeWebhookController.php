<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Billing\Billing;
use App\Billing\BillingAlerts;
use App\Billing\BillingSync;
use App\Billing\WebhookSignature;
use App\Http\Controllers\Controller;
use App\Http\Responses\TooManyRequests;
use App\Jobs\CancelStripeSubscription;
use App\Jobs\SyncBillingAccount;
use App\Mail\BillingAlertMail;
use App\Models\BillingAccount;
use App\Models\Workspace;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * `POST /webhooks/stripe`: Stripe telling the application that something happened to a customer, a subscription, an invoice or a payment.
 *
 * **What it says is never believed.** The call only wakes up a synchronisation: the customer an event is about is looked up among the
 * accounts, and a {@see SyncBillingAccount} job reads the subscriptions of that customer again through the API ({@see BillingSync}),
 * which is where the plan of a workspace comes from. So the worst a forged call (it needs the signing secret) can do is make the server
 * read a customer it already follows (the one exception, a payment for a workspace that is gone, is described below). The two events
 * that warn of a risk (a dispute, an early fraud warning) are told to the administrators of the instance by mail, with a link to the
 * dashboard of Stripe where they are answered; nothing else is done with them. The mode of the event (`livemode`) does not decide what is
 * done: a test event on a live instance changes nothing, since the API is read again (it only picks the side of the dashboard a mail
 * links to).
 *
 * The call is authenticated by the signature Stripe puts in the `Stripe-Signature` header, made with the signing secret of the endpoint
 * (`STRIPE_WEBHOOK_SECRET`) over the exact body and a timestamp ({@see WebhookSignature}). The route has no session and no CSRF check.
 * The answers (the messages are in the language the call asks for, the application's by default):
 *
 * - `404` when billing is off or the instance has no signing secret: the webhook does not exist (the schedule resynchronises the
 *   subscriptions anyway, `inventaire:sync-billing`);
 * - `429` when the network address has failed {@see self::FAILURES_PER_MINUTE} times in the last minute, whatever it sends next (the
 *   check comes first, so that nobody can go on guessing): only the calls that are refused (a body that is too large, a signature that
 *   is wrong) are counted, so that the many calls of a busy shop never use up what is allowed to the calls that fail;
 * - `413` when the body is larger than {@see self::MAX_BYTES} (an event is a few kilobytes); it is not read beyond that;
 * - `400` with the code `invalid_signature` when the signature is missing, wrong or too old (counted);
 * - `429` when an address that does authenticate makes more than {@see self::CALLS_PER_MINUTE} calls in a minute: a pace nothing but a
 *   loop reaches;
 * - `400` with the code `invalid_body` when the signed body is not a JSON object;
 * - `200 {"received": true}` otherwise, and quickly: for an event that is not one of those followed, for a customer nobody knows. The
 *   exceptions are a synchronisation or a cancellation that could not be queued, and the alert of a risk (or of a payment for a deleted
 *   workspace) that could not be queued: they are answered with a `500`, so that Stripe sends the event again, since the schedule would
 *   only catch up on the first and nothing would tell the administrators of the others. Stripe sends the event again for any answer that
 *   is not a success, for days.
 *
 * **A customer nobody knows** is usually a customer of the same Stripe account that is not a workspace of this instance (a test, another
 * site): nothing is done. But a payment can reach the instance after the workspace it was made for was deleted (a Checkout page left
 * open, paid after the deletion cancelled the subscriptions; sessions now expire within the half hour, and are expired when the
 * subscriptions are cancelled, which leaves a short window). The session (`client_reference_id`) and the subscription (`metadata.workspace`)
 * carry the ULID of the workspace they were opened for, and the marker of the instance that opened them (`metadata.instance`, see
 * {@see Billing::instanceMarker()}): when the ULID names a workspace that no longer exists **and the marker is this instance's**, the
 * subscriptions of that customer are cancelled at once ({@see CancelStripeSubscription}), the error is written in the log and the
 * administrators are told by mail, once a day for each customer, to refund the payment. A ULID that names no workspace but comes without
 * the marker, or with another one, is not this instance's to cancel (another site or instance that shares the Stripe account looks the
 * same): the administrators are told (`checkout_unmarked`) and nothing is cancelled. No ULID, or a workspace that exists, is ignored:
 * the ULID is only a trace here, never a way to find an account.
 *
 * Stripe may send an event twice; the synchronisation is unique per account while it waits in the queue, and the mail of an alert is sent
 * once per event (the id of the event is remembered for a day). The one case where an administrator gets the same alert twice is a
 * failure half-way: the mails are queued one administrator after the other, so when queueing fails at administrator N (the answer is a
 * `500`), Stripe's new delivery mails administrators 1 to N-1 again as well as the rest. A repeated alert was judged better than one that
 * never reaches somebody, and the number of administrators of an instance is small.
 *
 * The answers carry a `message` for people; the refusals have a `code` like those of the API, the success none: this is not part of the
 * API clients use.
 */
final class StripeWebhookController extends Controller
{
    /** The largest body that is read, in bytes. */
    public const MAX_BYTES = 1_048_576;

    /** The calls that may be refused in a minute, per network address, before the address is refused for the rest of it. */
    public const FAILURES_PER_MINUTE = 120;

    /** The calls that do authenticate that an address may make in a minute: generous, for what is a loop and not Stripe. */
    public const CALLS_PER_MINUTE = 1200;

    /** The events that mean "read the subscriptions of that customer again". */
    private const SYNC_EVENTS = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.paused',
        'customer.subscription.resumed',
        'invoice.paid',
        'invoice.payment_failed',
        'charge.refunded',
    ];

    /** The events that can tell of a payment for a workspace that is gone: the object of each carries the ULID of the workspace. */
    private const PAYMENT_EVENTS = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
    ];

    /** The shape of an id at Stripe (`cus_…`, `du_…`, `evt_…`): letters, digits and underscores. */
    private const ID_PATTERN = '/^[A-Za-z0-9_]{1,255}\z/';

    /**
     * Gets the way to tell the administrators.
     */
    public function __construct(private readonly BillingAlerts $alerts) {}

    /**
     * Receives an event.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (! Billing::webhookEnabled()) {
            return new JsonResponse(['message' => __('Not found.')], 404);
        }

        $failures = 'stripe-webhook-failures:'.$request->ip();

        // Before the signature is looked at: an address that has failed too often is not told whether its next guess was right.
        $this->refuseBeyond($failures, self::FAILURES_PER_MINUTE);

        $body = $this->body($request);

        if ($body === null) {
            RateLimiter::hit($failures, 60);

            return new JsonResponse(['message' => __('The body is too large.')], 413);
        }

        $signature = $request->header('Stripe-Signature');

        if (! WebhookSignature::verify($body, is_string($signature) ? $signature : null, Billing::webhookSecret())) {
            RateLimiter::hit($failures, 60);

            return new JsonResponse(['message' => __('The signature is missing or wrong.'), 'code' => 'invalid_signature'], 400);
        }

        $calls = 'stripe-webhook-calls:'.$request->ip();

        $this->refuseBeyond($calls, self::CALLS_PER_MINUTE);
        RateLimiter::hit($calls, 60);

        $event = json_decode($body, true);

        // An event is a JSON object: a list (`[]`, `[1]`) is not one, and `{}` has nothing in it.
        if (! is_array($event) || array_is_list($event)) {
            return new JsonResponse(['message' => __('The body is not valid JSON.'), 'code' => 'invalid_body'], 400);
        }

        $this->follow($event);

        return new JsonResponse(['received' => true]);
    }

    /**
     * Refuses the call, as every other pace of the application does (the exception is rendered as a `429` in JSON with `Retry-After`),
     * when the address has used up what it is allowed in the minute. Nothing is counted here.
     *
     * @throws ThrottleRequestsException
     */
    private function refuseBeyond(string $key, int $limit): void
    {
        if (! RateLimiter::tooManyAttempts($key, $limit)) {
            return;
        }

        $retryAfter = RateLimiter::availableIn($key);

        throw TooManyRequests::exception('Too many calls to the webhook.', $limit, $retryAfter);
    }

    /**
     * The body of the request, read up to {@see self::MAX_BYTES}; null when it is larger.
     */
    private function body(Request $request): ?string
    {
        if ((int) $request->header('Content-Length', '0') > self::MAX_BYTES) {
            return null;
        }

        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, self::MAX_BYTES + 1);

        return $body === false || strlen($body) > self::MAX_BYTES ? null : $body;
    }

    /**
     * Does what an event asks for: queues the synchronisation of the customer it is about, or tells the administrators of a risk. Any
     * other event is ignored.
     *
     * Nothing is caught: a synchronisation, a cancellation or an alert that could not be queued is answered with a failure, and Stripe
     * sends the event again.
     *
     * @param  array<array-key, mixed>  $event
     *
     * @throws Throwable When a job or a mail could not be queued.
     */
    private function follow(array $event): void
    {
        $type = $event['type'] ?? null;
        $object = data_get($event, 'data.object');

        if (! is_string($type) || ! is_array($object)) {
            return;
        }

        if (in_array($type, self::SYNC_EVENTS, true)) {
            $this->synchronise($type, $object, ($event['livemode'] ?? true) !== false);
        } elseif (BillingAlertMail::handles($type)) {
            $this->alert($type, $event, $object);
        }
    }

    /**
     * Queues the synchronisation of the account of the customer an event is about, if the instance has one; for a customer it does not
     * know, cancels what it bought if that was for a workspace that is gone ({@see self::cancelForDeletedWorkspace()}).
     *
     * @param  array<array-key, mixed>  $object  The object the event is about (a subscription, an invoice, a charge, a session).
     * @param  bool  $livemode  Whether the event comes from the live mode of Stripe.
     *
     * @throws Throwable When the job or the mail could not be queued.
     */
    private function synchronise(string $type, array $object, bool $livemode): void
    {
        $customer = $this->shaped($object['customer'] ?? null);
        // The ids of Stripe are case-sensitive: the column has a binary collation on MySQL and MariaDB, so the match is exact there too.
        $account = $customer === null ? null : BillingAccount::query()->where('stripe_customer_id', $customer)->value('id');

        if ($account === null) {
            if ($customer !== null && $this->cancelForDeletedWorkspace($type, $object, $customer, $livemode)) {
                return;
            }

            // A customer of the same Stripe account that is not a workspace of this instance (a test, another site): nothing to read.
            Log::info('An event of Stripe is about a customer this instance does not know: ignored.', ['type' => $type]);

            return;
        }

        SyncBillingAccount::dispatch((int) $account);
    }

    /**
     * Cancels the subscriptions of a customer nobody knows when the event shows they were bought for a workspace that no longer exists: a
     * payment that reached the instance after the deletion of the workspace. The session carries the ULID of the workspace as
     * `client_reference_id`, the subscription as `metadata.workspace` (both set by {@see CheckoutParameters}, with the marker of the
     * instance in their `metadata.instance`). When the marker is this instance's the cancellation is queued at once, the error is written
     * in the log, and the administrators are told to refund the payment. Without the marker, or with another one, nothing is cancelled: the
     * administrators are only told.
     *
     * @param  array<array-key, mixed>  $object  A Checkout session or a subscription.
     * @return bool Whether it is such a payment (for a workspace that does not exist), cancelled or not.
     *
     * @throws Throwable When the job or the mail could not be queued.
     */
    private function cancelForDeletedWorkspace(string $type, array $object, string $customer, bool $livemode): bool
    {
        if (! in_array($type, self::PAYMENT_EVENTS, true)) {
            return false;
        }

        $named = $type === 'checkout.session.completed' ? ($object['client_reference_id'] ?? null) : data_get($object, 'metadata.workspace');

        if (! is_string($named) || ! Str::isUlid($named)) {
            return false;
        }

        // Stored in lowercase (see the model); PostgreSQL compares exactly.
        $ulid = strtolower($named);

        if (Workspace::query()->where('ulid', $ulid)->exists()) {
            return false;
        }

        $marker = data_get($object, 'metadata.instance');

        if (! is_string($marker) || ! hash_equals(Billing::instanceMarker(), $marker)) {
            Log::warning('Stripe reports a payment for a workspace this instance does not know, that is not marked as made through it: nothing is cancelled (does another site share the Stripe account?).', [
                'type' => $type,
                'customer' => $customer,
                'workspace' => $ulid,
            ]);
            $this->alerts->tellProblem('checkout_unmarked', $customer, $ulid, $livemode);

            return true;
        }

        Log::error('Stripe reports a payment for a workspace that no longer exists: its subscriptions are being cancelled, the payment is to be refunded.', [
            'type' => $type,
            'customer' => $customer,
            'workspace' => $ulid,
        ]);
        CancelStripeSubscription::dispatch($customer, false, null, $ulid);
        $this->alerts->tellProblem('checkout_orphaned', $customer, $ulid, $livemode);

        return true;
    }

    /**
     * Tells every administrator of the instance that Stripe reported a risk, once per event.
     *
     * The event is marked as told before the mails are queued, so that two deliveries at the same moment tell once, and the mark is taken
     * back if queueing fails: the failure goes up (the answer is a `500`, Stripe sends the event again) and the next delivery must not find
     * the alert already told.
     *
     * @param  array<array-key, mixed>  $event
     * @param  array<array-key, mixed>  $object  The dispute or the early fraud warning.
     *
     * @throws Throwable When a mail could not be queued.
     */
    private function alert(string $type, array $event, array $object): void
    {
        $id = $this->shaped($event['id'] ?? null);

        $told = $id === null ? null : 'stripe-webhook-alert:'.$id;

        // Stripe may send an event twice: the administrators are told once.
        if ($told !== null && ! Cache::add($told, true, now()->addDay())) {
            return;
        }

        $amount = $object['amount'] ?? null;
        $currency = $object['currency'] ?? null;
        $objectId = $this->shaped($object['id'] ?? null, 64);
        $amount = is_int($amount) && $amount >= 0 ? $amount : null;
        $currency = is_string($currency) && preg_match('/^[a-z]{3}\z/', $currency) === 1 ? $currency : null;
        $livemode = ($event['livemode'] ?? true) !== false;

        try {
            // One mail for each, in their language: a mailable keeps the recipients it was sent to.
            $this->alerts->toAdministrators(fn (): BillingAlertMail => new BillingAlertMail($type, $objectId, $amount, $currency, $livemode));
        } catch (Throwable $exception) {
            if ($told !== null) {
                Cache::forget($told);
            }

            throw $exception;
        }
    }

    /**
     * A value of the payload that is the id of an object at Stripe; null otherwise.
     */
    private function shaped(mixed $value, int $max = 255): ?string
    {
        return is_string($value) && strlen($value) <= $max && preg_match(self::ID_PATTERN, $value) === 1 ? $value : null;
    }
}
