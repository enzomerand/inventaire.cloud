<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Models\PhotoAnalysis;

/**
 * Ends the photo analyses: the one that failed and the photos nobody needs any more.
 *
 * It is where a failure is recorded, whatever gave up on the analysis: the job (the analyzer refused, the queue killed it) and the
 * pruner (its worker was lost, see {@see PhotoAnalysisPruner}).
 */
final class PhotoAnalysisRunner
{
    /**
     * Gets the cleanup that deletes the photos.
     */
    public function __construct(private readonly PhotoAnalysisCleanup $cleanup) {}

    /**
     * Marks an analysis that is not over `failed` with the reason, and deletes its photos. One that is over already (completed, or failed
     * before) is left as it is: the update wants the analysis to be waiting or running, so a failure reported late never undoes the
     * proposals of an analysis that did end.
     */
    public function fail(PhotoAnalysis $analysis, PhotoAnalysisError $error): void
    {
        PhotoAnalysis::query()
            ->whereKey($analysis->getKey())
            ->whereIn('status', [PhotoAnalysisStatus::Pending->value, PhotoAnalysisStatus::Running->value])
            ->update([
                'status' => PhotoAnalysisStatus::Failed->value,
                'error' => $error->value,
                'completed_at' => now(),
            ]);

        $this->cleanup->deleteNow($analysis);
    }
}
