<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Models\PhotoAnalysis;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Clears out the photo analyses that are over: the ones that expired are deleted (with their folders when they may still have one), and
 * the ones that never ended are given up on. Run every hour by `inventaire:prune-photo-analyses`.
 *
 * An analysis that expired is a set of proposals nobody looked at for a day (`inventories.photo_analysis_ttl_hours`). One that never ended
 * has lost its worker (the queue was not running, the process was killed): it is marked `failed` (`stale`), its photos deleted, so that
 * the person is told, and kept until it expires in turn. A job takes a few minutes at most (Claude is given two minutes at most, once more
 * after a failure), so an analysis that waits or runs for {@see self::STALE_MINUTES} is lost, not slow. `pending` is judged on the day it
 * was received, `running` on the day it began.
 *
 * Giving up on an analysis is one update that repeats the test of "lost" in its `WHERE`, not only the read that found it: a worker that
 * takes the analysis between the two (it was `pending`, it is `running` since a second) is never failed under its feet.
 *
 * The photos of an analysis that is `completed` or `failed` are gone already (the job deletes them when it ends, and the pruner when it
 * gives up), so those rows are deleted in bulk, with no job to purge a folder. Only a row that is still `pending` or `running` when it
 * expires may have a folder: it is deleted as a model, which queues the purge of its folder.
 */
final class PhotoAnalysisPruner
{
    /** Minutes after which an analysis that has not ended is given up on. */
    public const STALE_MINUTES = 15;

    /**
     * Gets the cleanup that deletes the photos of an analysis.
     */
    public function __construct(private readonly PhotoAnalysisCleanup $cleanup) {}

    /**
     * Prunes.
     *
     * @return array{expired: int, stale: int} How many analyses were deleted and how many were given up on.
     */
    public function prune(): array
    {
        $stale = 0;
        $expired = 0;
        $since = now()->subMinutes(self::STALE_MINUTES);

        PhotoAnalysis::query()
            ->where(fn (Builder $query): Builder => $this->stuck($query, $since))
            ->chunkById(100, function ($analyses) use (&$stale, $since): void {
                foreach ($analyses as $analysis) {
                    if ($this->giveUp($analysis, $since)) {
                        $stale++;
                    }
                }
            });

        PhotoAnalysis::query()
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($analyses) use (&$expired): void {
                $over = [];

                foreach ($analyses as $analysis) {
                    if ($analysis->status === PhotoAnalysisStatus::Completed || $analysis->status === PhotoAnalysisStatus::Failed) {
                        $over[] = $analysis->getKey();

                        continue;
                    }

                    // Still waiting or running: its folder may be there, and purging it is the job of the model's deletion.
                    $analysis->delete();
                    $expired++;
                }

                if ($over !== []) {
                    $expired += PhotoAnalysis::query()->whereIn('id', $over)->delete();
                }
            });

        return ['expired' => $expired, 'stale' => $stale];
    }

    /**
     * Gives up on an analysis, if it is still stuck: failed `stale`, its photos deleted. Whether it was.
     */
    private function giveUp(PhotoAnalysis $analysis, CarbonInterface $since): bool
    {
        $given = PhotoAnalysis::query()
            ->whereKey($analysis->getKey())
            ->where(fn (Builder $query): Builder => $this->stuck($query, $since))
            ->update([
                'status' => PhotoAnalysisStatus::Failed->value,
                'error' => PhotoAnalysisError::Stale->value,
                'completed_at' => now(),
            ]);

        if ($given !== 1) {
            return false;
        }

        try {
            $this->cleanup->deleteNow($analysis);
        } catch (Throwable $exception) {
            // The analysis is over: the photos that stay are for the administrator to know, and must not stop the others from being pruned.
            report($exception);
        }

        return true;
    }

    /**
     * Narrows a query to the analyses that never ended: waiting since before a moment, or running since before it (a running row without
     * a start, written by hand, is judged on the day it was received).
     *
     * @param  Builder<PhotoAnalysis>  $query
     * @return Builder<PhotoAnalysis>
     */
    private function stuck(Builder $query, CarbonInterface $since): Builder
    {
        return $query
            ->where(fn (Builder $pending): Builder => $pending
                ->where('status', PhotoAnalysisStatus::Pending->value)
                ->where('created_at', '<', $since))
            ->orWhere(fn (Builder $running): Builder => $running
                ->where('status', PhotoAnalysisStatus::Running->value)
                ->where(fn (Builder $started): Builder => $started
                    ->where('started_at', '<', $since)
                    ->orWhere(fn (Builder $unknown): Builder => $unknown->whereNull('started_at')->where('created_at', '<', $since))));
    }
}
