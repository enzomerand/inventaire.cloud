<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\Workspace;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;

/**
 * What becomes of the files when what holds them is deleted: they are counted out of the storage of the workspace, in the
 * transaction of the deletion, and their deletion from the disk is queued for after the commit.
 *
 * The database cascades the rows of the attachments away when an item, a field, an inventory or a workspace goes, and it
 * fires no model event doing so; this is the part that remembers the files. Every method is called with the workspace
 * locked first, then the inventory (see {@see Locks}), so that the counter and the files that exist cannot change meanwhile,
 * and reads the attachments that are about to go before the deletion removes their rows.
 */
final class AttachmentCleanup
{
    /**
     * Gets the database, the storage that purges files and the counter of the workspace.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AttachmentStorage $storage,
        private readonly WorkspaceStorage $usage,
    ) {}

    /**
     * Counts the files of an item out of the storage of its workspace and queues the deletion of the item's folder (on every
     * disk the files were written to), to be run once the transaction that deletes the item is committed. Does nothing for an
     * item that holds no file.
     *
     * @param  Workspace  $workspace  The workspace of the item, locked.
     * @param  Inventory  $inventory  The inventory of the item.
     */
    public function releaseItem(Workspace $workspace, Inventory $inventory, Item $item): void
    {
        $files = $this->db->table('attachments')->where('item_id', $item->getKey());
        $disks = (clone $files)->distinct()->pluck('disk')->all();

        if ($disks === []) {
            return;
        }

        $this->usage->move($workspace, -(int) (clone $files)->sum('size'));

        $directory = AttachmentStorage::itemDirectory($workspace->ulid, $inventory->ulid, $item->ulid);

        foreach ($disks as $disk) {
            $this->storage->purgeAfterCommit((string) $disk, [], [$directory]);
        }
    }
}
