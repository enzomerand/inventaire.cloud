<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Inventories\Sharing\ShareLinks;
use Illuminate\Console\Command;

/**
 * Deletes the share links that expired more than 30 days ago.
 *
 * A link is refused as soon as it expires; its owner still sees it, marked expired, for a month, and this clears it away. Scheduled every
 * day (`routes/console.php`); safe to run at any time and as often as wanted.
 */
final class PruneShareLinksCommand extends Command
{
    protected $signature = 'inventaire:prune-share-links';

    protected $description = 'Delete the share links that expired more than 30 days ago';

    /**
     * Prunes the links.
     */
    public function handle(ShareLinks $links): int
    {
        $this->components->info(sprintf('%d expired share link(s) deleted.', $links->prune()));

        return self::SUCCESS;
    }
}
