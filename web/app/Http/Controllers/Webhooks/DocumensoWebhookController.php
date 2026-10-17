<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Inventories\Versions\SignatureStatus;
use App\Jobs\SyncSignature;
use App\Models\InventoryVersion;
use App\Signatures\Documenso;
use App\Signatures\SignatureSync;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * `POST /webhooks/documenso`: Documenso telling the application that something happened to an envelope (it was sent, opened, signed,
 * completed, refused, cancelled).
 *
 * **What it says is never believed.** The call only wakes up a synchronisation: the envelope is looked up by the ids it carries and a
 * {@see SyncSignature} job reads the envelope again through the API, which is where the state of the request comes from. So the
 * worst a forged call (it needs the secret) can do is make the server read an envelope it already follows. An envelope id the call
 * announces for a request that has none is not recorded here at all: it travels to the job, which reads that envelope, checks that it
 * is the version's by its `externalId`, and only then records it ({@see SignatureSync::sync()}).
 *
 * The call is authenticated by the secret the administrator typed in Documenso and in `DOCUMENSO_WEBHOOK_SECRET`, sent as it is in the
 * `X-Documenso-Secret` header (Documenso signs nothing), compared in constant time. The route has no session and no CSRF check.
 * The answers (the messages are in the language the call asks for, the application's by default):
 *
 * - `404` when the instance has no secret: the webhook does not exist (the schedule resynchronises the requests anyway);
 * - `429` when the network address has failed to authenticate {@see self::FAILURES_PER_MINUTE} times in the last minute, whatever it
 *   sends next (the check comes first, so that nobody can go on guessing): only the calls that are refused with a `401` are counted,
 *   so that the many calls of a busy instance never use up what is allowed to the calls that fail;
 * - `401` when the secret is absent or wrong (counted);
 * - `429` when an address that does authenticate makes more than {@see self::CALLS_PER_MINUTE} calls in a minute: a pace nothing but a
 *   loop reaches, which a burst of events for a few envelopes does not come near;
 * - `413` when the body is larger than {@see self::MAX_BYTES} (an envelope event is a few kilobytes); it is not read beyond that;
 * - `200 {"received": true}` otherwise, always, and quickly: for an event that is not about a document, an envelope nobody knows, a
 *   body that is not JSON, even a queue that is down (logged; the schedule catches up). Documenso gives a call ten seconds and
 *   follows no redirect.
 *
 * Documenso does not number its events, and sends several for one envelope in a burst; the synchronisation job is unique per version,
 * so they cost one read. The envelope is found by its id (`payload.envelopeId`) or, for a request that is being sent and whose id was
 * not recorded yet (the webhook of the creation can beat the answer of the creation), by our own identifier of it
 * (`payload.externalId`, the ULID of the version).
 *
 * The answers carry a `message` for people; the `429` has a `code` (`too_many_requests`) like those of the API, the others none: this
 * is not part of the API clients use.
 */
final class DocumensoWebhookController extends Controller
{
    /** The largest body that is read, in bytes. */
    public const MAX_BYTES = 1_048_576;

    /** The calls that may fail to authenticate in a minute, per network address, before the address is refused for the rest of it. */
    public const FAILURES_PER_MINUTE = 120;

    /** The calls that do authenticate that an address may make in a minute: generous, for what is a loop and not Documenso. */
    public const CALLS_PER_MINUTE = 1200;

    /** The events about a document, which all mean "read it again". Those about templates and recipients are not. */
    private const EVENTS = [
        'DOCUMENT_CREATED', 'DOCUMENT_SENT', 'DOCUMENT_OPENED', 'DOCUMENT_SIGNED', 'DOCUMENT_RECIPIENT_COMPLETED',
        'DOCUMENT_COMPLETED', 'DOCUMENT_REJECTED', 'DOCUMENT_CANCELLED', 'DOCUMENT_REMINDER_SENT',
    ];

    /**
     * Receives an event.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (! Documenso::webhookEnabled()) {
            return new JsonResponse(['message' => __('Not found.')], 404);
        }

        $failures = 'documenso-webhook-failures:'.$request->ip();

        // Before the secret is looked at: an address that has failed too often is not told whether its next guess was right.
        $this->refuseBeyond($failures, self::FAILURES_PER_MINUTE);

        $secret = $request->header('X-Documenso-Secret');

        if (! is_string($secret) || ! hash_equals(Documenso::webhookSecret(), $secret)) {
            RateLimiter::hit($failures, 60);

            return new JsonResponse(['message' => __('The secret is missing or wrong.')], 401);
        }

        $calls = 'documenso-webhook-calls:'.$request->ip();

        $this->refuseBeyond($calls, self::CALLS_PER_MINUTE);
        RateLimiter::hit($calls, 60);

        $body = $this->body($request);

        if ($body === null) {
            return new JsonResponse(['message' => __('The body is too large.')], 413);
        }

        $event = json_decode($body, true);

        if (is_array($event)) {
            try {
                $this->follow($event);
            } catch (Throwable $exception) {
                // The answer does not depend on it: Documenso would only send it again, and the schedule reads the request anyway.
                Log::error('A webhook of Documenso could not be followed.', ['exception' => $exception]);
            }
        }

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

        throw new ThrottleRequestsException('Too many calls to the webhook.', null, [
            'X-RateLimit-Limit' => $limit,
            'X-RateLimit-Remaining' => 0,
            'Retry-After' => $retryAfter,
            'X-RateLimit-Reset' => time() + $retryAfter,
        ]);
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
     * Queues the synchronisation of the version an event is about, if there is one.
     *
     * By the id of the envelope when a request has it. Otherwise by the identifier of the version the event carries, for a request that
     * is being sent and has no envelope yet: the job is then given the id the event announces, which it reads, checks and records
     * (nothing is recorded here).
     *
     * @param  array<array-key, mixed>  $event
     */
    private function follow(array $event): void
    {
        if (! is_string($event['event'] ?? null) || ! in_array($event['event'], self::EVENTS, true)) {
            return;
        }

        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $envelopeId = $this->shaped($payload['envelopeId'] ?? null, '/^[A-Za-z0-9_-]{1,64}$/');
        $externalId = $this->shaped($payload['externalId'] ?? null, '/^[0-9A-Za-z]{26}$/');
        $versionId = $envelopeId === null ? null : InventoryVersion::query()->where('documenso_envelope_id', $envelopeId)->value('id');

        if ($versionId !== null) {
            SyncSignature::dispatch((int) $versionId);

            return;
        }

        if ($envelopeId === null || $externalId === null) {
            return;
        }

        $candidate = InventoryVersion::query()
            ->where('ulid', strtolower($externalId))
            ->where('signature_status', SignatureStatus::Sending->value)
            ->whereNull('documenso_envelope_id')
            ->value('id');

        if ($candidate !== null) {
            SyncSignature::dispatch((int) $candidate, $envelopeId);
        }
    }

    /**
     * A value of the payload that is a text of the expected shape; null otherwise.
     */
    private function shaped(mixed $value, string $pattern): ?string
    {
        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }
}
