<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Signatures\SignatureSync;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Reads the envelope of a request for signatures at Documenso and brings the request up to date ({@see SignatureSync::sync()}): the
 * signers who signed, and the end of the request, with the signed PDF downloaded and stored when everybody has signed.
 *
 * Queued by the webhook of Documenso (whatever the event: its data is never believed, the envelope is read again), by the schedule
 * (`inventaire:sync-versions`, for the requests nothing has been heard of for half an hour) and when a cancellation is refused.
 * It is unique per version (and per envelope a webhook announces for a request that has none): while one is waiting in the queue, the
 * ones a burst of events asks for are dropped (the lock is released as soon as the job starts, so that an event that comes while it
 * runs queues another one, which reads the newer state).
 *
 * That one that comes while it runs must not run at the same time, though: two reads of one request could both download the signed
 * PDF, or write over one another. A job of the same version that finds another running is put back in the queue for half a minute
 * ({@see WithoutOverlapping}; the lock lives on the cache, which every store the application may use can hold, and is forgotten after
 * {@see self::LOCK_EXPIRES} seconds if the worker that held it was killed, which is more than the job may run).
 *
 * Putting a job back in the queue counts as a try for Laravel, so the job is not bounded by its tries (`$tries = 0`: a duplicate that waits
 * behind a long read, or every job of a version during the five minutes a killed worker's lock lasts, must not be failed for having
 * been put back; the event would be lost until the schedule). It is bounded in two other ways: by time, {@see self::retryUntil()} (the
 * pauses of the overlap included), and in failures, {@see self::$maxExceptions} real exceptions (Documenso failing, the disk), each
 * followed by a pause ({@see self::backoff()}), after which the job fails and the schedule picks the request up again later.
 */
final class SyncSignature implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** The seconds the job may run: a read, the download of the signed PDF (two minutes at most) and its storage. */
    public const TIMEOUT = 240;

    /** The seconds the lock that keeps two jobs of one version apart is held at the longest: more than the job may run. */
    public const LOCK_EXPIRES = 300;

    /** The seconds a job that finds another of the same version running waits before it tries again. */
    public const OVERLAP_RELEASE = 30;

    /** The minutes the job may be kept waiting or tried again, from the moment it is queued: the overlap releases are not limited otherwise. */
    public const RETRY_FOR_MINUTES = 30;

    /** How many times the job is tried: not limited (0), since a release for the overlap is a try; see {@see self::retryUntil()}. */
    public int $tries = 0;

    /** How many real exceptions (not a release for the overlap) the job may throw before it fails. */
    public int $maxExceptions = 3;

    /** The seconds the job may run. */
    public int $timeout = self::TIMEOUT;

    /** The seconds a job waiting in the queue keeps others for the same version away. */
    public int $uniqueFor = 60;

    /**
     * Remembers which version to look at (by its key: it is read again when the job runs).
     *
     * @param  string|null  $envelopeId  The envelope a webhook announced for a request that has none yet: it is recorded only after
     *                                   Documenso has said, by the API, that it is the envelope of this version.
     */
    public function __construct(public readonly int $versionId, public readonly ?string $envelopeId = null) {}

    /**
     * What makes two of these jobs the same: the version, and the envelope that is announced for it.
     */
    public function uniqueId(): string
    {
        return $this->versionId.($this->envelopeId === null ? '' : ':'.$this->envelopeId);
    }

    /**
     * What keeps two jobs of the same version from running at once: the second one waits.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->versionId))->releaseAfter(self::OVERLAP_RELEASE)->expireAfter(self::LOCK_EXPIRES)];
    }

    /**
     * When the job stops being tried: it is failed when it comes back after that, whatever released it.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_FOR_MINUTES);
    }

    /**
     * The seconds to wait before each new try after an exception (the last one is kept for the following ones).
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
        $sync->sync($this->versionId, $this->envelopeId);
    }
}
