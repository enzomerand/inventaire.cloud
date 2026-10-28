<?php

declare(strict_types=1);

namespace App\Billing;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A call to Stripe that did not give what it was asked for: the service could not be reached, answered with an error, or answered
 * with something that is not what the API promises.
 *
 * What a caller needs to know is whether trying again can help ({@see self::retryable()}): a connection that failed, a service that
 * is slowing clients down (`429`) or failing (`5xx`) may be better in a minute; a refusal (`4xx`: the key, a customer that is gone, a
 * parameter Stripe does not accept) will be the same each time. {@see self::code()} says which in a word, and {@see self::status()}
 * gives the HTTP status when there was an answer.
 *
 * The message names the call, the status and the `code` (or else the `type`) of the error Stripe wrote, and the id of the request when
 * Stripe gave one, which is what its support asks for. It never holds Stripe's own text of the error (it can repeat a part of the secret
 * key: "Invalid API Key provided: sk_live_****abcd"), the secret key, nor anything about the customer.
 */
final class StripeException extends RuntimeException
{
    /**
     * Remembers what failed: use the named constructors.
     */
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly ?int $httpStatus,
        private readonly bool $transient,
        private readonly ?string $request = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Stripe could not be reached (the connection failed, or timed out): worth trying again.
     *
     * @param  Throwable  $cause  What the HTTP client threw. It is not kept, only its class is named: its message holds the address that was
     *                            called, which carries the id of the customer (`/v1/customers/cus_…`), and a trace written in a log would carry it too.
     */
    public static function unreachable(Throwable $cause): self
    {
        return new self('Stripe could not be reached ('.class_basename($cause).').', 'unreachable', null, true);
    }

    /**
     * The instance has no Stripe secret key (any more).
     */
    public static function notConfigured(): self
    {
        return new self('Stripe is not configured.', 'not_configured', null, false);
    }

    /**
     * What Stripe answered when it did not succeed.
     *
     * @param  string  $call  What was asked, `METHOD /path` with the ids written `{id}`, for the message.
     */
    public static function fromResponse(string $call, Response $response): self
    {
        $status = $response->status();
        $reason = self::reasonOf($response) ?? 'http_'.$status;
        $request = $response->header('Request-Id');
        $request = preg_match('/^req_[A-Za-z0-9]{1,64}\z/', $request) === 1 ? $request : null;

        return new self(
            "Stripe answered {$status} to {$call} ({$reason})".($request === null ? '' : ", request {$request}").'.',
            $reason,
            $status,
            $status === 429 || $status >= 500,
            $request,
        );
    }

    /**
     * Stripe answered with a success that is not what the API promises (not JSON, no id, no url).
     *
     * @param  string  $call  What was asked, `METHOD /path`, for the message.
     * @param  string  $what  What is wrong with the answer.
     */
    public static function invalidResponse(string $call, string $what): self
    {
        return new self("Stripe answered {$call} with something unexpected: {$what}.", 'invalid_response', null, false);
    }

    /**
     * Whether the same call may work if it is made again later: no answer, `429`, or `5xx`.
     */
    public function retryable(): bool
    {
        return $this->transient;
    }

    /**
     * A word for what went wrong: the `code` Stripe gave (`resource_missing`, `api_key_expired`...) or else its error `type`
     * (`invalid_request_error`, `api_error`...) when it gave one that looks like one, else `http_<status>`, or `unreachable`,
     * `not_configured`, `invalid_response`.
     */
    public function code(): string
    {
        return $this->reason;
    }

    /**
     * Whether Stripe answered that what was asked for is not there: its error says `resource_missing`. A `404` without it is not an answer
     * of Stripe about the object (a proxy, a wrong address), and proves nothing: it is an error like any other, and must never, say, turn
     * every workspace Free.
     */
    public function missing(): bool
    {
        return $this->reason === 'resource_missing';
    }

    /**
     * The HTTP status Stripe answered with; null when it did not answer.
     */
    public function status(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * The id (`req_…`) Stripe gave to the request, to quote to its support; null when it did not answer or gave none.
     */
    public function requestId(): ?string
    {
        return $this->request;
    }

    /**
     * Whether Stripe refused the key (`401`, `403`): the configuration is wrong, which is not about the customer.
     */
    public function unauthorized(): bool
    {
        return $this->httpStatus === 401 || $this->httpStatus === 403;
    }

    /**
     * The `code` (or else the `type`) of the error Stripe wrote in its answer, if it wrote one in the shape of a word
     * (`resource_missing`); null otherwise. Nothing else of the body is read.
     */
    private static function reasonOf(Response $response): ?string
    {
        foreach (['error.code', 'error.type'] as $path) {
            $word = $response->json($path);

            if (is_string($word) && preg_match('/^[a-z][a-z0-9_]{2,63}\z/', $word) === 1) {
                return $word;
            }
        }

        return null;
    }
}
