<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Inventories\Versions\SignatureError;
use App\Models\InventoryVersion;
use App\Signatures\SignatureRequests;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends the request for signatures of a completed version to Documenso, which emails the signers ({@see SignatureRequests::send()}).
 *
 * Queued when the PDF is written, and when a person sends a failed request again. It is tried five times, with a pause that grows
 * (10 seconds, a minute, five minutes, a quarter of an hour), and every try resumes where the last stopped: an envelope that was
 * created is recorded at once and never created twice. A refusal by Documenso ends the request without waiting for the tries; when the
 * tries run out (Documenso cannot be reached, keeps failing, or the job outlives its 2 minutes) the request is `failed` with
 * `documenso_unavailable`, and the person can send it again.
 *
 * The version is serialized by its key and read again when the job runs: one deleted before that is no failure, the job is dropped.
 */
final class RequestSignatures implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** The seconds the job may run. */
    public const TIMEOUT = 120;

    /** How many times the job is tried. */
    public int $tries = 5;

    /** The seconds the job may run. */
    public int $timeout = self::TIMEOUT;

    /** A version that is deleted before its job runs is not an error. */
    public bool $deleteWhenMissingModels = true;

    /**
     * Remembers which version to send.
     */
    public function __construct(public readonly InventoryVersion $version) {}

    /**
     * The seconds to wait before each new try.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    /**
     * Sends the request.
     */
    public function handle(SignatureRequests $requests): void
    {
        $requests->send($this->version);
    }

    /**
     * Records the failure of a job the queue gave up on (too many tries, a timeout): the service was not available.
     */
    public function failed(Throwable $exception): void
    {
        app(SignatureRequests::class)->fail($this->version, SignatureError::DocumensoUnavailable);
    }
}
