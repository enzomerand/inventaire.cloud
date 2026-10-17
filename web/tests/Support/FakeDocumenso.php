<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\InventoryVersion;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A Documenso for the tests, behind `Http::fake()`: it answers the calls of the API version 2 that the application makes
 * (`/envelope/create`, `/envelope/{id}`, `/envelope/distribute`, `/envelope/cancel`, `/envelope/item/{id}/download`) the way the real
 * one does, with the state of one envelope, and it records every call. Any other request is refused (`Http::preventStrayRequests()`).
 *
 * A test drives it: it makes people sign, refuses, cancels or completes the envelope, makes the next calls of one kind fail (with a
 * status, or as a connection that is lost), and hooks what happens while a call is being answered.
 */
final class FakeDocumenso
{
    /** The API token the tests give the instance (see `versionDocumenso()`): a call that carries another one is refused with `401`. */
    public const API_KEY = 'api_test_key';

    /** The id Documenso gives the envelope; the number of envelopes created so far is appended. */
    public string $envelopeId = 'envelope_test1';

    /** The status of the envelope: DRAFT, PENDING, COMPLETED, REJECTED or CANCELLED. */
    public string $status = 'DRAFT';

    /** Whether the envelope exists: false until it is created (or declared), and a test may make it vanish. */
    public bool $exists = false;

    /** Our identifier of the document, as it was sent when the envelope was created. */
    public ?string $externalId = null;

    /** The title of the document, as it was sent. */
    public ?string $title = null;

    /** The bytes the PDF to sign had when it was sent. */
    public ?string $pdf = null;

    /** The bytes Documenso gives for the signed PDF. */
    public string $signedPdf = "%PDF-1.7\n% signed by Documenso\n";

    /** How many envelopes were created. */
    public int $created = 0;

    /** @var list<array<string, mixed>> The recipients of the envelope, as Documenso gives them. */
    public array $recipients = [];

    /** @var list<Request> Every request, in order. */
    public array $requests = [];

    /** @var list<string> What each request was, in order (`POST /envelope/create`). */
    public array $calls = [];

    /** @var array<string, list<int|null>> The failures to give for the next calls of a kind: a status, or null for a lost connection. */
    private array $failures = [];

    /** @var array<string, Closure(Request): void> What runs while a call of a kind is being answered, before the answer. */
    private array $hooks = [];

    /** The fake of the network this Documenso answers for: a test is a new application, and so a new one. */
    private static ?object $network = null;

    /** The Documenso installed on that network. */
    private static ?self $installed = null;

    /**
     * Fakes the network with a Documenso, and refuses every request that does not match. Installing it again in the same test gives
     * the same one back: the first fake of the network to match a request is the one that answers, so a second would never be asked.
     */
    public static function install(): self
    {
        $network = Http::getFacadeRoot();

        if (self::$installed !== null && self::$network === $network) {
            return self::$installed;
        }

        $fake = new self;
        Http::fake(fn (Request $request) => $fake->respond($request));
        Http::preventStrayRequests();

        self::$network = $network;
        self::$installed = $fake;

        return $fake;
    }

    /**
     * Makes the next `$times` calls of a kind fail.
     *
     * @param  string  $call  `METHOD /path` with the ids written `{id}`: `POST /envelope/create`, `GET /envelope/{id}`, `GET /envelope/item/{id}/download`.
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
     * Declares that the envelope of a version exists, as a test that did not go through the creation needs it: with its id, in a status,
     * and a recipient for each signer.
     */
    public function envelopeFor(InventoryVersion $version, string $status = 'PENDING'): self
    {
        $this->exists = true;
        $this->envelopeId = (string) $version->documenso_envelope_id;
        $this->externalId = $version->ulid;
        $this->status = $status;
        $this->recipients = [];

        foreach (array_values($version->signers ?? []) as $index => $signer) {
            $this->recipients[] = $this->recipient(100 + $index, (string) $signer['email'], (string) $signer['name'], $status !== 'DRAFT');
        }

        return $this;
    }

    /**
     * A signer signs.
     */
    public function sign(string $email, string $signedAt = '2026-10-15T12:30:00.000Z'): self
    {
        return $this->change($email, ['signingStatus' => 'SIGNED', 'signedAt' => $signedAt]);
    }

    /**
     * A signer refuses to sign: the envelope is rejected.
     */
    public function reject(string $email, ?string $reason = 'Not mine'): self
    {
        $this->status = 'REJECTED';

        return $this->change($email, ['signingStatus' => 'REJECTED', 'rejectionReason' => $reason]);
    }

    /**
     * Every signer signs and the envelope is completed.
     */
    public function complete(string $signedAt = '2026-10-15T12:30:00.000Z'): self
    {
        foreach ($this->recipients as $index => $recipient) {
            $this->recipients[$index]['signingStatus'] = 'SIGNED';
            $this->recipients[$index]['signedAt'] = $signedAt;
        }

        $this->status = 'COMPLETED';

        return $this;
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
     * A part of a multipart request: its name, contents and file name.
     *
     * @return array{name: string, contents: string, filename?: string, headers?: array<string, string>}|null
     */
    public static function part(Request $request, string $name): ?array
    {
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) {
                return $part;
            }
        }

        return null;
    }

    /**
     * The JSON body of the `payload` part of a creation.
     *
     * @return array<string, mixed>
     */
    public static function payload(Request $request): array
    {
        $part = self::part($request, 'payload');
        $decoded = $part === null ? null : json_decode($part['contents'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Answers a request.
     */
    public function respond(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (! str_starts_with($path, '/api/v2/')) {
            return Http::response(['code' => 'NOT_FOUND'], 404);
        }

        $call = $request->method().' '.$this->generic(substr($path, strlen('/api/v2')));
        $this->requests[] = $request;
        $this->calls[] = $call;

        if (($request->header('Authorization')[0] ?? null) !== self::API_KEY) {
            return Http::response(['code' => 'UNAUTHORIZED', 'message' => 'Invalid token'], 401);
        }

        if (isset($this->hooks[$call])) {
            ($this->hooks[$call])($request);
        }

        if (($this->failures[$call] ?? []) !== []) {
            $status = array_shift($this->failures[$call]);

            return $status === null
                ? Http::failedConnection('cURL error 28: Operation timed out after 20001 milliseconds')
                : Http::response(['code' => $status === 404 ? 'NOT_FOUND' : ($status >= 500 ? 'INTERNAL_SERVER_ERROR' : 'INVALID_REQUEST'), 'message' => 'Failed on purpose'], $status);
        }

        return match ($call) {
            'POST /envelope/create' => $this->create($request),
            'GET /envelope/{id}' => $this->read($path),
            'POST /envelope/distribute' => $this->distribute(),
            'POST /envelope/cancel' => $this->cancel(),
            'GET /envelope/item/{id}/download' => $this->download(),
            default => Http::response(['code' => 'NOT_FOUND'], 404),
        };
    }

    /**
     * The path with its ids written `{id}`.
     */
    private function generic(string $path): string
    {
        return (string) preg_replace(['#^/envelope/item/[^/]+/download$#', '#^/envelope/(?!create$|distribute$|cancel$)[^/]+$#'], ['/envelope/item/{id}/download', '/envelope/{id}'], $path);
    }

    /**
     * Creates the envelope from the multipart request.
     */
    private function create(Request $request): mixed
    {
        $payload = self::payload($request);
        $this->created++;
        $this->envelopeId = 'envelope_test'.$this->created;
        $this->exists = true;
        $this->status = 'DRAFT';
        $this->externalId = is_string($payload['externalId'] ?? null) ? $payload['externalId'] : null;
        $this->title = is_string($payload['title'] ?? null) ? $payload['title'] : null;
        $this->pdf = self::part($request, 'files')['contents'] ?? null;
        $this->recipients = [];

        foreach (array_values($payload['recipients'] ?? []) as $index => $recipient) {
            $this->recipients[] = $this->recipient(100 + $index, (string) $recipient['email'], (string) $recipient['name'], false);
        }

        return Http::response(['id' => $this->envelopeId]);
    }

    /**
     * The envelope, or a 404.
     */
    private function read(string $path): mixed
    {
        if (! $this->exists || basename($path) !== $this->envelopeId) {
            return Http::response(['code' => 'NOT_FOUND', 'message' => 'Envelope not found'], 404);
        }

        return Http::response([
            'id' => $this->envelopeId,
            'status' => $this->status,
            'externalId' => $this->externalId,
            'title' => $this->title,
            'recipients' => $this->recipients,
            'envelopeItems' => [['id' => 'envelope_item_'.$this->envelopeId, 'order' => 1, 'title' => 'document.pdf']],
        ]);
    }

    /**
     * Sends the envelope: its signers are emailed.
     */
    private function distribute(): mixed
    {
        if ($this->status === 'DRAFT') {
            $this->status = 'PENDING';

            foreach ($this->recipients as $index => $recipient) {
                $this->recipients[$index]['sendStatus'] = 'SENT';
            }
        }

        return Http::response(['success' => true, 'id' => $this->envelopeId, 'recipients' => $this->recipients]);
    }

    /**
     * Cancels the envelope if it is pending.
     */
    private function cancel(): mixed
    {
        if ($this->status !== 'PENDING') {
            return Http::response(['code' => 'INVALID_REQUEST', 'message' => 'Only pending documents can be cancelled'], 400);
        }

        $this->status = 'CANCELLED';

        return Http::response(['success' => true]);
    }

    /**
     * The signed PDF of a completed envelope.
     */
    private function download(): mixed
    {
        if ($this->status !== 'COMPLETED') {
            return Http::response(['code' => 'INVALID_REQUEST', 'message' => 'The document is not completed'], 400);
        }

        return Http::response($this->signedPdf, 200, ['Content-Type' => 'application/pdf']);
    }

    /**
     * Changes the recipient with an address.
     *
     * @param  array<string, mixed>  $changes
     */
    private function change(string $email, array $changes): self
    {
        foreach ($this->recipients as $index => $recipient) {
            if ($recipient['email'] === $email) {
                $this->recipients[$index] = $changes + $recipient;
            }
        }

        return $this;
    }

    /**
     * A recipient as Documenso gives it.
     *
     * @return array<string, mixed>
     */
    private function recipient(int $id, string $email, string $name, bool $sent): array
    {
        return [
            'id' => $id,
            'email' => $email,
            'name' => $name,
            'role' => 'SIGNER',
            'signingStatus' => 'NOT_SIGNED',
            'signedAt' => null,
            'rejectionReason' => null,
            'sendStatus' => $sent ? 'SENT' : 'NOT_SENT',
            'readStatus' => 'NOT_OPENED',
        ];
    }
}
