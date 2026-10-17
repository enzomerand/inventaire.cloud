<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Signatures\Documenso;
use App\Signatures\DocumensoClient;
use App\Signatures\DocumensoException;
use App\Signatures\EnvelopeCleanup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Cancels envelopes at Documenso, so that signers are not left with a document to sign that the application no longer keeps: queued,
 * once the deletion is committed, for the open requests of a version, an inventory or a workspace that is deleted
 * ({@see EnvelopeCleanup}), and for the requests the schedule gave up on.
 *
 * It does what it can: Documenso only cancels an envelope that is pending, so one that is no longer (signed, refused, cancelled, a
 * draft, gone) is refused with a `4xx` and left alone, which is nothing to worry about (written in the log as information). What is
 * worth another try (Documenso cannot be reached or is failing) makes the job fail and run again, three times with a growing pause; the
 * envelopes already cancelled are refused the second time, which is harmless. What is still open after the last try is written in the
 * log, for somebody to cancel in Documenso, and so is what Documenso refuses for a reason that is the configuration's, not the
 * envelope's (the token is refused, `401` or `403`; any other error) or does not look like a refusal to cancel an envelope that is not
 * pending (`400`, `404`, `409`, `422`): the application is not doing what it should, and somebody has to be told. The token is never
 * written.
 *
 * The envelopes come a few at a time ({@see EnvelopeCleanup::perJob()}: ten, or five with `$deleteDrafts`, which reads each envelope
 * before it cancels or deletes it: two calls), so that the job, which waits for each call for 20 seconds at the longest, ends within
 * its {@see self::$timeout} seconds (200 seconds in the worst case, either way). With `$deleteDrafts`, an envelope that is still a
 * draft (nobody was emailed, and Documenso cannot cancel it) is deleted instead.
 */
final class CancelDocumensoEnvelopes implements ShouldQueue
{
    use Queueable;

    /** What Documenso answers to the cancellation of an envelope that is not pending, or that it does not have: nothing is wrong. */
    private const NOT_PENDING_STATUSES = [400, 404, 409, 422];

    /** How many times the job is tried. */
    public int $tries = 3;

    /** The seconds the job may run: 200 seconds of calls at the longest ({@see EnvelopeCleanup::perJob()} envelopes, 20 seconds a call), with room. */
    public int $timeout = 300;

    /**
     * Remembers what to cancel.
     *
     * @param  list<string>  $envelopeIds  The ids of the envelopes at Documenso, at most {@see EnvelopeCleanup::perJob()}.
     * @param  string|null  $reason  What the signers are told; a general sentence when none is given.
     * @param  bool  $deleteDrafts  Whether an envelope that is a draft is deleted (it cannot be cancelled), after it is read to know.
     */
    public function __construct(public readonly array $envelopeIds, public readonly ?string $reason = null, public readonly bool $deleteDrafts = false) {}

    /**
     * The seconds to wait before each new try.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Cancels each envelope, going on after a failure so that one that resists does not keep the others.
     *
     * @throws RuntimeException When some could not be cancelled for a reason worth another try.
     */
    public function handle(DocumensoClient $client): void
    {
        if (! Documenso::configured()) {
            Log::warning('Signature requests could not be cancelled: this instance has no Documenso configured any more.', ['envelopes' => $this->envelopeIds]);

            return;
        }

        $reason = $this->reason ?? __('This signature request was cancelled.');
        $again = 0;

        foreach ($this->envelopeIds as $id) {
            try {
                if ($this->deleteDrafts && strtoupper((string) $client->getEnvelope($id)['status']) === 'DRAFT') {
                    $client->deleteEnvelope($id);

                    continue;
                }

                $client->cancel($id, $reason);
            } catch (DocumensoException $exception) {
                if ($exception->retryable()) {
                    $again++;
                }

                $context = ['envelope' => $id, 'status' => $exception->status(), 'reason' => $exception->code(), 'again' => $exception->retryable()];

                if ($exception->retryable() || in_array($exception->status(), self::NOT_PENDING_STATUSES, true)) {
                    Log::info('An envelope was not cancelled at Documenso.', $context);
                } else {
                    // The token is refused, or Documenso answered something this application does not expect from it: not about the envelope.
                    Log::error('An envelope could not be cancelled at Documenso: check its configuration, and cancel the request there by hand if it is still open.', $context);
                }
            }
        }

        if ($again > 0) {
            throw new RuntimeException("{$again} envelope(s) could not be cancelled at Documenso yet.");
        }
    }

    /**
     * Writes in the log what could not be cancelled once the last try failed.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Signature requests could not be cancelled at Documenso: cancel them there by hand.', ['envelopes' => $this->envelopeIds, 'exception' => $exception]);
    }
}
