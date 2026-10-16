<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Inventories\Exports\ExportPruner;
use Illuminate\Console\Command;

/**
 * Deletes the exports that expired (their rows and their files) and gives up on the ones that never ended.
 *
 * Scheduled every hour (`routes/console.php`); safe to run at any time and as often as wanted.
 */
final class PruneExportsCommand extends Command
{
    protected $signature = 'inventaire:prune-exports';

    protected $description = 'Delete the exports that expired and give up on the ones stuck for too long';

    /**
     * Prunes the exports.
     */
    public function handle(ExportPruner $pruner): int
    {
        $result = $pruner->prune();

        $this->components->info(sprintf(
            '%d expired export(s) deleted, %d stuck export(s) given up on, %d scratch file(s) deleted.',
            $result['expired'],
            $result['stale'],
            $result['scratch'],
        ));

        return self::SUCCESS;
    }
}
