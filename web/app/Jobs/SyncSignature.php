<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Signatures\SignatureSync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads the envelope of a request for signatures at Documenso and brings the request up to date ({@see SignatureSync::sync()}): the
 * signers who signed, and the end of the request, with the signed PDF downloaded and stored when everybody has signed.
 *
 * Queued by the webhook of Documenso (whatever the event: its data is never believed, the envelope is read again), by the schedule
 * (`inventaire:sync-versions`, for the requests nothing has been heard of for half an hour) and when a cancellation is refused.
 * It is unique per version: while one is waiting in the queue, the ones a burst of events asks for are dropped (the lock is
 * released as soon as the job starts, so that an event that comes while it runs queues another one, which reads the newer state).
 * It is tried three times.
 */
final class SyncSignature implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** How many times the job is tried. */
    public int $tries = 3;

    /** The seconds a job waiting in the queue keeps others for the same version away. */
    public int $uniqueFor = 60;

    /**
     * Remembers which version to look at (by its key: it is read again when the job runs).
     */
    public function __construct(public readonly int $versionId) {}

    /**
     * What makes two of these jobs the same: the version.
     */
    public function uniqueId(): string
    {
        return (string) $this->versionId;
    }

    /**
     * The seconds to wait before each new try.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Brings the request up to date.
     */
    public function handle(SignatureSync $sync): void
    {
        $sync->sync($this->versionId);
    }
}
