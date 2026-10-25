<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PhotoAnalysis;
use App\PhotoEntry\PhotoAnalysisError;
use App\PhotoEntry\PhotoAnalysisRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Asks Claude to read the photos of a photo analysis and records what it proposes ({@see PhotoAnalysisRunner}).
 *
 * It is tried once: a failure is recorded on the analysis itself, with a code that tells the person what to do, and doing it again is for
 * the person to ask (they send the photos again), not for the queue to repeat in silence. It may run for five minutes (Claude is given
 * 90 seconds, once more after a failure the SDK judges temporary), well under the time the queue connection waits before it hands a
 * running job to another worker (`retry_after` of `config/queue.php`, 960 seconds). It goes on the default queue: it is a short wait on
 * the network, not the heavy work of the exports.
 *
 * The analysis is serialized by its key, not as a snapshot (`SerializesModels`) and read again when the job runs: one deleted before that
 * is no failure, the job is dropped (`deleteWhenMissingModels`). One that was killed (a timeout, a worker stopped) is recorded as failed
 * and its photos deleted; one that was lost altogether is given up on by `inventaire:prune-photo-analyses`.
 */
final class AnalyzePhotos implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** The job is tried once. */
    public int $tries = 1;

    /** The seconds the job may run. */
    public int $timeout = 300;

    /** An analysis that is deleted before its job runs is not an error. */
    public bool $deleteWhenMissingModels = true;

    /**
     * Remembers which analysis to do.
     */
    public function __construct(public readonly PhotoAnalysis $analysis) {}

    /**
     * Does the analysis.
     */
    public function handle(PhotoAnalysisRunner $runner): void
    {
        $runner->run($this->analysis);
    }

    /**
     * Records the failure of a job the queue gave up on (timeout, a worker that stopped), and deletes the photos.
     */
    public function failed(Throwable $exception): void
    {
        app(PhotoAnalysisRunner::class)->fail($this->analysis, PhotoAnalysisError::Failed);
    }
}
