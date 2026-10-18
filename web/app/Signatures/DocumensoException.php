<?php

declare(strict_types=1);

namespace App\Signatures;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A call to Documenso that did not give what it was asked for: the service could not be reached, answered with an error, or answered
 * with something that is not what the API promises.
 *
 * What a caller needs to know is whether trying again can help ({@see self::retryable()}): a connection that failed, a service that
 * is slowing clients down (`429`) or failing (`5xx`) may be better in a minute; a refusal (`4xx`: the token, the address, an envelope
 * that is gone or is not in the state the call needs) will be the same each time. {@see self::code()} says which in a word, and
 * {@see self::status()} gives the HTTP status when there was an answer.
 *
 * The message names the call and the status, never the body of the answer, the API token or the document: Documenso's error shape is
 * not part of what this application relies on, and the message ends up in logs.
 */
final class DocumensoException extends RuntimeException
{
    /**
     * Remembers what failed: use the named constructors.
     */
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly ?int $httpStatus,
        private readonly bool $transient,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The service could not be reached (the connection failed, or timed out): worth trying again.
     */
    public static function unreachable(Throwable $previous): self
    {
        // The message of the exception of the HTTP client names the address and the cURL error, never the token (it is a header).
        return new self('Documenso could not be reached.', 'unreachable', null, true, $previous);
    }

    /**
     * The instance has no Documenso configured (any more).
     */
    public static function notConfigured(): self
    {
        return new self('Documenso is not configured.', 'not_configured', null, false);
    }

    /**
     * What Documenso answered when it did not succeed.
     *
     * @param  string  $call  What was asked, `METHOD /path`, for the message.
     */
    public static function fromResponse(string $call, Response $response): self
    {
        $status = $response->status();
        $reason = self::reasonOf($response) ?? 'http_'.$status;

        return new self(
            "Documenso answered {$status} to {$call} ({$reason}).",
            $reason,
            $status,
            $status === 429 || $status >= 500,
        );
    }

    /**
     * Documenso answered with a success that is not what the API promises (not JSON, no id, not a PDF).
     *
     * @param  string  $call  What was asked, `METHOD /path`, for the message.
     * @param  string  $what  What is wrong with the answer.
     */
    public static function invalidResponse(string $call, string $what): self
    {
        return new self("Documenso answered {$call} with something unexpected: {$what}.", 'invalid_response', null, false);
    }

    /**
     * What was to be sent to Documenso cannot be built from what the application holds (a signer without a signature area, say).
     */
    public static function invalidRequest(string $what): self
    {
        return new self("The request for Documenso cannot be built: {$what}.", 'invalid_request', null, false);
    }

    /**
     * Whether the same call may work if it is made again later: no answer, `429`, or `5xx`.
     */
    public function retryable(): bool
    {
        return $this->transient;
    }

    /**
     * A word for what went wrong: the code Documenso gave (`NOT_FOUND`, `INVALID_REQUEST`, ...) when it gave one that looks like one,
     * else `http_<status>`, or `unreachable`, `not_configured`, `invalid_response`, `invalid_request`.
     */
    public function code(): string
    {
        return $this->reason;
    }

    /**
     * The HTTP status Documenso answered with; null when it did not answer.
     */
    public function status(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * Whether Documenso answered that what was asked for is not there (`404`).
     */
    public function notFound(): bool
    {
        return $this->httpStatus === 404;
    }

    /**
     * Whether Documenso refused the token (`401`, `403`): the configuration is wrong, which is not about the envelope.
     */
    public function unauthorized(): bool
    {
        return $this->httpStatus === 401 || $this->httpStatus === 403;
    }

    /**
     * The code of the error Documenso wrote in its answer, if it wrote one in the shape of a code (`INVALID_REQUEST`); null otherwise.
     * Nothing else of the body is read.
     */
    private static function reasonOf(Response $response): ?string
    {
        $code = $response->json('code');

        return is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $code) === 1 ? $code : null;
    }
}
