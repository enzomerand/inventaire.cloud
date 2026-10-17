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
 * ({@see EnvelopeCleanup}).
 *
 * It does what it can: Documenso only cancels an envelope that is pending, so one that is no longer (signed, refused, cancelled, a
 * draft, gone) is refused with a `4xx` and left alone. What is worth another try (Documenso cannot be reached or is failing) makes the
 * job fail and run again, three times with a growing pause; the envelopes already cancelled are refused the second time, which is
 * harmless. What is still open after the last try is written in the log, for somebody to cancel in Documenso.
 */
final class CancelDocumensoEnvelopes implements ShouldQueue
{
    use Queueable;

    /** How many times the job is tried. */
    public int $tries = 3;

    /**
     * Remembers what to cancel.
     *
     * @param  list<string>  $envelopeIds  The ids of the envelopes at Documenso.
     * @param  string|null  $reason  What the signers are told; a general sentence when none is given.
     */
    public function __construct(public readonly array $envelopeIds, public readonly ?string $reason = null) {}

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
                $client->cancel($id, $reason);
            } catch (DocumensoException $exception) {
                if ($exception->retryable()) {
                    $again++;
                }

                Log::info('An envelope was not cancelled at Documenso.', ['envelope' => $id, 'status' => $exception->status(), 'reason' => $exception->code(), 'again' => $exception->retryable()]);
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
