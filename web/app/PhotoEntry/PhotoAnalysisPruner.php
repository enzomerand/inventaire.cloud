<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Models\PhotoAnalysis;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Clears out the photo analyses that are over: the ones that expired are deleted with their folders, and the ones that never ended are
 * given up on. Run every hour by `inventaire:prune-photo-analyses`.
 *
 * An analysis that expired is a set of proposals nobody looked at for a day (`inventories.photo_analysis_ttl_hours`). One that never ended
 * has lost its worker (the queue was not running, the process was killed): it is marked `failed` (`stale`), its photos deleted, so that
 * the person is told, and kept until it expires in turn. A job takes a few minutes at most (Claude is given 90 seconds, once more after
 * a failure), so an analysis that waits or runs for {@see self::STALE_MINUTES} is lost, not slow. `pending` is judged on the day it was
 * received, `running` on the day it began.
 */
final class PhotoAnalysisPruner
{
    /** Minutes after which an analysis that has not ended is given up on. */
    public const STALE_MINUTES = 15;

    /**
     * Gets the runner that gives up on an analysis.
     */
    public function __construct(private readonly PhotoAnalysisRunner $runner) {}

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
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $pending): Builder => $pending
                    ->where('status', PhotoAnalysisStatus::Pending->value)
                    ->where('created_at', '<', $since))
                ->orWhere(fn (Builder $running): Builder => $running
                    ->where('status', PhotoAnalysisStatus::Running->value)
                    ->where(fn (Builder $started): Builder => $this->startedBefore($started, $since))))
            ->chunkById(100, function ($analyses) use (&$stale): void {
                foreach ($analyses as $analysis) {
                    $this->runner->fail($analysis, PhotoAnalysisError::Stale);
                    $stale++;
                }
            });

        PhotoAnalysis::query()
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($analyses) use (&$expired): void {
                foreach ($analyses as $analysis) {
                    $analysis->delete();
                    $expired++;
                }
            });

        return ['expired' => $expired, 'stale' => $stale];
    }

    /**
     * Narrows a query to the analyses started before a moment; a row without a start (written by hand) is judged on the day it was
     * received.
     *
     * @param  Builder<PhotoAnalysis>  $query
     * @return Builder<PhotoAnalysis>
     */
    private function startedBefore(Builder $query, CarbonInterface $moment): Builder
    {
        return $query
            ->where('started_at', '<', $moment)
            ->orWhere(fn (Builder $unknown): Builder => $unknown->whereNull('started_at')->where('created_at', '<', $moment));
    }
}
