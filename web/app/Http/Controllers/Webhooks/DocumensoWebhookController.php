<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Inventories\Versions\SignatureStatus;
use App\Jobs\SyncSignature;
use App\Models\InventoryVersion;
use App\Signatures\Documenso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `POST /webhooks/documenso`: Documenso telling the application that something happened to an envelope (it was sent, opened, signed,
 * completed, refused, cancelled).
 *
 * **What it says is never believed.** The call only wakes up a synchronisation: the envelope is looked up by the ids it carries and a
 * {@see SyncSignature} job reads the envelope again through the API, which is where the state of the request comes from. So the
 * worst a forged call (it needs the secret) can do is make the server read an envelope it already follows.
 *
 * The call is authenticated by the secret the administrator typed in Documenso and in `DOCUMENSO_WEBHOOK_SECRET`, sent as it is in the
 * `X-Documenso-Secret` header (Documenso signs nothing), compared in constant time. The route has no session and no CSRF check.
 * The answers:
 *
 * - `404` when the instance has no secret: the webhook does not exist (the schedule resynchronises the requests anyway);
 * - `401` when the secret is absent or wrong;
 * - `413` when the body is larger than {@see self::MAX_BYTES} (an envelope event is a few kilobytes); it is not read beyond that;
 * - `200 {"received": true}` otherwise, always, and quickly: for an event that is not about a document, an envelope nobody knows, a
 *   body that is not JSON, even a queue that is down (logged; the schedule catches up). Documenso gives a call ten seconds and
 *   follows no redirect.
 *
 * Documenso does not number its events, and sends several for one envelope in a burst; the synchronisation job is unique per version,
 * so they cost one read. The envelope is found by its id (`payload.envelopeId`) or, for a request that is being sent and whose id was
 * not recorded yet (the webhook of the creation can beat the answer of the creation), by our own identifier of it
 * (`payload.externalId`, the ULID of the version), and the id is recorded then.
 *
 * The answers carry a `message` for people but no `code`: this is not part of the API clients use.
 */
final class DocumensoWebhookController extends Controller
{
    /** The largest body that is read, in bytes. */
    public const MAX_BYTES = 1_048_576;

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
            return new JsonResponse(['message' => 'Not found.'], 404);
        }

        $secret = $request->header('X-Documenso-Secret');

        if (! is_string($secret) || ! hash_equals(Documenso::webhookSecret(), $secret)) {
            return new JsonResponse(['message' => 'The secret is missing or wrong.'], 401);
        }

        $body = $this->body($request);

        if ($body === null) {
            return new JsonResponse(['message' => 'The body is too large.'], 413);
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

        if ($versionId === null && $envelopeId !== null && $externalId !== null) {
            $versionId = $this->adopt(strtolower($externalId), $envelopeId);
        }

        if ($versionId !== null) {
            SyncSignature::dispatch((int) $versionId);
        }
    }

    /**
     * Records the envelope for the request that is being sent and has none yet, found by our identifier of the document.
     *
     * @return int|string|null The key of the version, or null when there is none to adopt the envelope.
     */
    private function adopt(string $ulid, string $envelopeId): int|string|null
    {
        $versionId = InventoryVersion::query()
            ->where('ulid', $ulid)
            ->where('signature_status', SignatureStatus::Sending->value)
            ->whereNull('documenso_envelope_id')
            ->value('id');

        if ($versionId === null) {
            return null;
        }

        InventoryVersion::query()
            ->whereKey($versionId)
            ->where('signature_status', SignatureStatus::Sending->value)
            ->whereNull('documenso_envelope_id')
            ->update(['documenso_envelope_id' => $envelopeId]);

        // Whoever recorded an id first wins: the job reads the version again and follows that one.
        return $versionId;
    }

    /**
     * A value of the payload that is a text of the expected shape; null otherwise.
     */
    private function shaped(mixed $value, string $pattern): ?string
    {
        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }
}
