<?php

declare(strict_types=1);

namespace App\Signatures;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The calls this application makes to Documenso, the e-signature service, through its REST API version 2 (`{url}/api/v2/envelope/*`;
 * version 1 and `/document/*` are deprecated and will be removed).
 *
 * The API token goes bare in the `Authorization` header (no `Bearer`). Calls wait 20 seconds for an answer and 5 to connect, and
 * follow no redirect: an address that redirects is a configuration to correct, and the token must not travel to another host. A call
 * that does not succeed throws a {@see DocumensoException}, which says whether trying again can help; the client itself never retries,
 * the jobs that call it do (with the pace of the queue). Neither the token nor the document is ever written in a message or a log.
 *
 * An envelope is Documenso's name for a document sent for signature: it is created as a draft with its PDF and its recipients (each
 * with the signature box to draw on the page), then distributed, which has Documenso email the signers; it ends `COMPLETED`
 * (everybody signed), `REJECTED` or `CANCELLED`. Positions are percentages of the page, 0 to 100, counted from the top left, on pages
 * numbered from 1.
 */
final class DocumensoClient
{
    /** Seconds Documenso may take to answer. */
    private const TIMEOUT = 20;

    /** Seconds to establish the connection. */
    private const CONNECT_TIMEOUT = 5;

    /**
     * Creates a draft envelope holding the PDF, with its signers and their signature boxes.
     *
     * @param  string  $title  The title of the document, as the signers see it.
     * @param  string  $externalId  Our own identifier of it (the ULID of the version), which Documenso gives back.
     * @param  string  $pdf  The bytes of the PDF.
     * @param  list<array{name: string, email: string, fields: list<array{page: int, positionX: float|int, positionY: float|int, width: float|int, height: float|int}>}>  $recipients  Each signer with their signature boxes.
     * @param  array<string, mixed>  $meta  What the emails say and how the document is signed (`subject`, `message`, `language`, `timezone`, `signingOrder`, `distributionMethod`).
     * @param  string  $fileName  The name the PDF is sent under.
     * @return string The id of the envelope.
     *
     * @throws DocumensoException
     */
    public function createEnvelope(string $title, string $externalId, string $pdf, array $recipients, array $meta, string $fileName = 'document.pdf'): string
    {
        $payload = [
            'type' => 'DOCUMENT',
            'title' => $title,
            'externalId' => $externalId,
            'recipients' => array_map(fn (array $recipient): array => [
                'email' => $recipient['email'],
                'name' => $recipient['name'],
                'role' => 'SIGNER',
                'fields' => array_map(fn (array $field): array => [
                    'type' => 'SIGNATURE',
                    'identifier' => 0,
                    'page' => $field['page'],
                    'positionX' => $field['positionX'],
                    'positionY' => $field['positionY'],
                    'width' => $field['width'],
                    'height' => $field['height'],
                    'fieldMeta' => ['type' => 'signature', 'required' => true],
                ], $recipient['fields']),
            ], $recipients),
            'meta' => $meta,
        ];

        $response = $this->send('POST /envelope/create', fn (PendingRequest $request): Response => $request
            ->attach('files', $pdf, $fileName, ['Content-Type' => 'application/pdf'])
            ->post($this->url('/envelope/create'), ['payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]));

        $id = $response->json('id');

        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
            throw DocumensoException::invalidResponse('POST /envelope/create', 'no usable envelope id');
        }

        return $id;
    }

    /**
     * Reads an envelope: its `status` (`DRAFT`, `PENDING`, `COMPLETED`, `REJECTED` or `CANCELLED`), its `externalId`, its `recipients`
     * (`id`, `email`, `signingStatus`, `signedAt`, `rejectionReason`) and its `envelopeItems` (`id`, `order`).
     *
     * @return array<string, mixed>
     *
     * @throws DocumensoException With a status of 404 when the envelope is gone.
     */
    public function getEnvelope(string $id): array
    {
        $call = 'GET /envelope/{id}';
        $response = $this->send($call, fn (PendingRequest $request): Response => $request->get($this->url('/envelope/'.rawurlencode($id))));
        $envelope = $response->json();

        if (! is_array($envelope) || ! is_string($envelope['status'] ?? null)) {
            throw DocumensoException::invalidResponse($call, 'no status');
        }

        return $envelope;
    }

    /**
     * Sends a draft envelope: Documenso emails the signers and the envelope becomes `PENDING`.
     *
     * @param  array<string, mixed>  $meta  Replaces what the emails say, when given.
     *
     * @throws DocumensoException
     */
    public function distribute(string $id, array $meta = []): void
    {
        $this->send('POST /envelope/distribute', fn (PendingRequest $request): Response => $request
            ->asJson()
            ->post($this->url('/envelope/distribute'), ['envelopeId' => $id] + ($meta === [] ? [] : ['meta' => $meta])));
    }

    /**
     * Cancels an envelope that is pending: its signers are told. Any other envelope is refused (`4xx`).
     *
     * @param  string|null  $reason  Why, as the signers are told; none when null.
     *
     * @throws DocumensoException
     */
    public function cancel(string $id, ?string $reason = null): void
    {
        $this->send('POST /envelope/cancel', fn (PendingRequest $request): Response => $request
            ->asJson()
            ->post($this->url('/envelope/cancel'), ['envelopeId' => $id] + ($reason === null || $reason === '' ? [] : ['reason' => $reason])));
    }

    /**
     * Downloads the signed PDF of an item of a completed envelope (signatures and certificate included).
     *
     * @param  int|string  $itemId  The `id` of the item, from {@see self::getEnvelope()}.
     * @return string The bytes of the PDF.
     *
     * @throws DocumensoException
     */
    public function downloadSigned(int|string $itemId): string
    {
        $call = 'GET /envelope/item/{id}/download';
        $response = $this->send($call, fn (PendingRequest $request): Response => $request
            ->replaceHeaders(['Accept' => 'application/pdf'])
            ->get($this->url('/envelope/item/'.rawurlencode((string) $itemId).'/download'), ['version' => 'signed']));
        $body = $response->body();

        if (! str_starts_with($body, '%PDF-')) {
            throw DocumensoException::invalidResponse($call, 'not a PDF');
        }

        return $body;
    }

    /**
     * Makes a call and holds its answer to success.
     *
     * @param  string  $call  What is asked, `METHOD /path`, for the messages.
     * @param  callable(PendingRequest): Response  $make  Makes the call with the request that is given.
     *
     * @throws DocumensoException When Documenso is not configured, cannot be reached or does not answer with a success.
     */
    private function send(string $call, callable $make): Response
    {
        if (! Documenso::configured()) {
            throw DocumensoException::notConfigured();
        }

        $request = Http::withHeaders(['Authorization' => Documenso::apiKey()])
            ->acceptJson()
            ->timeout(self::TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->withoutRedirecting();

        try {
            $response = $make($request);
        } catch (ConnectionException $exception) {
            throw DocumensoException::unreachable($exception);
        }

        if (! $response->successful()) {
            throw DocumensoException::fromResponse($call, $response);
        }

        return $response;
    }

    /**
     * The address of an endpoint of the API.
     */
    private function url(string $path): string
    {
        return Documenso::apiBaseUrl().$path;
    }
}
