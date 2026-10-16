<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Inventories\Imports\ImportPruner;
use Illuminate\Console\Command;

/**
 * Deletes the imports that expired (their rows and their files) and gives up on the ones that never ended.
 *
 * Scheduled every hour (`routes/console.php`); safe to run at any time and as often as wanted.
 */
final class PruneImportsCommand extends Command
{
    protected $signature = 'inventaire:prune-imports';

    protected $description = 'Delete the imports that expired and give up on the ones stuck for too long';

    /**
     * Prunes the imports.
     */
    public function handle(ImportPruner $pruner): int
    {
        $result = $pruner->prune();

        $this->components->info(sprintf(
            '%d expired import(s) deleted, %d stuck import(s) given up on, %d scratch file(s) deleted.',
            $result['expired'],
            $result['stale'],
            $result['scratch'],
        ));

        return self::SUCCESS;
    }
}
