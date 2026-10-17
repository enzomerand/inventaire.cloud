<?php

declare(strict_types=1);

namespace App\Signatures;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The calls this application makes to Documenso, the e-signature service, through its REST API version 2 (`{url}/api/v2/envelope/*`;
 * version 1 and `/document/*` are deprecated and will be removed).
 *
 * The API token goes bare in the `Authorization` header (no `Bearer`). Calls wait 20 seconds for an answer and 5 to connect (two minutes
 * for the two that carry a whole PDF: sending one, and downloading the signed one), and follow no redirect: an address that redirects is a configuration to correct, and the token must not travel to another host. A call
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
    public const TIMEOUT = 20;

    /** Seconds it may take for the calls that move a whole PDF: the creation of an envelope and the download of the signed document. */
    private const FILE_TIMEOUT = 120;

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
            ->post($this->url('/envelope/create'), ['payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]), self::FILE_TIMEOUT);

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
     * @throws DocumensoException
     */
    public function distribute(string $id): void
    {
        $this->send('POST /envelope/distribute', fn (PendingRequest $request): Response => $request
            ->asJson()
            ->post($this->url('/envelope/distribute'), ['envelopeId' => $id]));
    }

    /**
     * Cancels an envelope that is pending: its signers are told. Any other envelope is refused (`4xx`).
     *
     * @param  string  $reason  Why, as the signers are told.
     *
     * @throws DocumensoException
     */
    public function cancel(string $id, string $reason): void
    {
        $this->send('POST /envelope/cancel', fn (PendingRequest $request): Response => $request
            ->asJson()
            ->post($this->url('/envelope/cancel'), ['envelopeId' => $id, 'reason' => $reason]));
    }

    /**
     * Deletes an envelope: a draft that is not wanted (nobody was ever emailed), or any envelope the application no longer keeps.
     *
     * @throws DocumensoException
     */
    public function deleteEnvelope(string $id): void
    {
        $this->send('POST /envelope/delete', fn (PendingRequest $request): Response => $request
            ->asJson()
            ->post($this->url('/envelope/delete'), ['envelopeId' => $id]));
    }

    /**
     * Downloads the signed PDF of an item of a completed envelope (signatures and certificate included) into a file, without holding
     * it in memory.
     *
     * The document is refused when it weighs more than `inventories.max_signed_pdf_bytes`: by the `Content-Length` Documenso announces
     * (the transfer is then cut at the headers, before a byte of it is written), while it comes (the transfer is cut when it has
     * brought more than the limit, whatever was announced) and by the size it really has, which is what counts.
     * The file is only ever the document or, when the call fails, what Documenso answered; whoever gave the path deletes it.
     *
     * @param  int|string  $itemId  The `id` of the item, from {@see self::getEnvelope()}.
     * @param  string  $into  The path of the file to write, on this machine.
     * @return int The size of the PDF, in bytes.
     *
     * @throws DocumensoException When the call fails, or what came is not a PDF or is bigger than the limit.
     */
    public function downloadSigned(int|string $itemId, string $into): int
    {
        $call = 'GET /envelope/item/{id}/download';
        $limit = config()->integer('inventories.max_signed_pdf_bytes');
        try {
            $response = $this->send($call, fn (PendingRequest $request): Response => $request
                ->replaceHeaders(['Accept' => 'application/pdf'])
                ->withOptions([
                    'sink' => $into,
                    // As soon as the headers are in, when Documenso announces a size...
                    'on_headers' => function (ResponseInterface $answer) use ($limit): void {
                        $length = $answer->getHeaderLine('Content-Length');

                        if ($answer->getStatusCode() < 300 && ctype_digit($length) && (int) $length > $limit) {
                            throw new SignedPdfTooLargeException('The signed PDF is bigger than the limit.');
                        }
                    },
                    // ...and while the body comes, for the one that does not (or lies): the transfer stops at the limit.
                    'progress' => function (int $total, int $downloaded) use ($limit): void {
                        if ($downloaded > $limit) {
                            throw new SignedPdfTooLargeException('The signed PDF is bigger than the limit.');
                        }
                    },
                ])
                ->get($this->url('/envelope/item/'.rawurlencode((string) $itemId).'/download'), ['version' => 'signed']), self::FILE_TIMEOUT);
        } catch (Throwable $exception) {
            // The HTTP client wraps what its callbacks throw: the one that stopped the transfer is somewhere in the chain of causes.
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof SignedPdfTooLargeException) {
                    throw DocumensoException::invalidResponse($call, 'the PDF is bigger than the limit');
                }
            }

            throw $exception;
        }

        clearstatcache(true, $into);
        $size = (int) filesize($into);
        $length = $response->header('Content-Length');

        if ($size > $limit || (ctype_digit($length) && (int) $length > $limit)) {
            throw DocumensoException::invalidResponse($call, 'the PDF is bigger than the limit');
        }

        if (file_get_contents($into, false, null, 0, 5) !== '%PDF-') {
            throw DocumensoException::invalidResponse($call, 'not a PDF');
        }

        return $size;
    }

    /**
     * Makes a call and holds its answer to success.
     *
     * @param  string  $call  What is asked, `METHOD /path`, for the messages.
     * @param  callable(PendingRequest): Response  $make  Makes the call with the request that is given.
     * @param  int  $timeout  The seconds Documenso may take to answer.
     *
     * @throws DocumensoException When Documenso is not configured, cannot be reached or does not answer with a success.
     */
    private function send(string $call, callable $make, int $timeout = self::TIMEOUT): Response
    {
        if (! Documenso::configured()) {
            throw DocumensoException::notConfigured();
        }

        $request = Http::withHeaders(['Authorization' => Documenso::apiKey()])
            ->acceptJson()
            ->timeout($timeout)
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
