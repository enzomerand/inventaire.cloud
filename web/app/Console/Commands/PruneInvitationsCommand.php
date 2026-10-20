<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Workspaces\Invitations;
use Illuminate\Console\Command;

/**
 * Deletes the invitations to join a workspace whose time is up.
 *
 * They are invisible and refused as soon as they lapse; this only clears the table. Scheduled every hour (`routes/console.php`); safe
 * to run at any time and as often as wanted.
 */
final class PruneInvitationsCommand extends Command
{
    protected $signature = 'inventaire:prune-invitations';

    protected $description = 'Delete the invitations to join a workspace that expired';

    /**
     * Prunes the invitations.
     */
    public function handle(Invitations $invitations): int
    {
        $this->components->info(sprintf('%d expired invitation(s) deleted.', $invitations->prune()));

        return self::SUCCESS;
    }
}
