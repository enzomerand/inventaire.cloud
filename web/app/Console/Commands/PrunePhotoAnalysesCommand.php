<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\PhotoEntry\PhotoAnalysisPruner;
use Illuminate\Console\Command;

/**
 * Deletes the photo analyses that expired (their rows and their folders) and gives up on the ones that never ended.
 *
 * Scheduled every hour (`routes/console.php`); safe to run at any time and as often as wanted.
 */
final class PrunePhotoAnalysesCommand extends Command
{
    protected $signature = 'inventaire:prune-photo-analyses';

    protected $description = 'Delete the photo analyses that expired and give up on the ones stuck for too long';

    /**
     * Prunes the photo analyses.
     */
    public function handle(PhotoAnalysisPruner $pruner): int
    {
        $result = $pruner->prune();

        $this->components->info(sprintf(
            '%d expired photo analysis(es) deleted, %d stuck photo analysis(es) given up on.',
            $result['expired'],
            $result['stale'],
        ));

        return self::SUCCESS;
    }
}
