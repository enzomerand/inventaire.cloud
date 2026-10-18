<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Inventories\Versions\VersionMaintenance;
use Illuminate\Console\Command;

/**
 * Keeps the official versions moving: gives up on the versions and the requests for signatures whose worker was lost, and looks again at the requests that await their signers.
 *
 * Scheduled every ten minutes (`routes/console.php`); safe to run at any time and as often as wanted.
 */
final class SyncVersionsCommand extends Command
{
    protected $signature = 'inventaire:sync-versions';

    protected $description = 'Give up on the official versions and signature requests stuck for too long, and sync those awaiting their signers';

    /**
     * Runs the maintenance of the versions.
     */
    public function handle(VersionMaintenance $maintenance): int
    {
        $result = $maintenance->run();

        $this->components->info(sprintf(
            '%d stuck version(s) given up on, %d unsent signature request(s) given up on, %d signature request(s) queued to be looked at again.',
            $result['generation'],
            $result['requests'],
            $result['synced'],
        ));

        return self::SUCCESS;
    }
}
