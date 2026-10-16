<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\Workspace;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

/**
 * What becomes of the files when what holds them is deleted: they are counted out of the storage of the workspace, in the
 * transaction of the deletion, and their deletion from the disk is queued for after the commit.
 *
 * The database cascades the rows of the attachments away when an inventory or a workspace goes (they hang from their
 * inventory), and it fires no model event doing so; the rows of an item and of a field are deleted by this class, which is
 * also the part that remembers the files. Every method is called with the workspace
 * locked first, then the inventory (see {@see Locks}), so that the counter and the files that exist cannot change meanwhile,
 * and reads the attachments that are about to go before the deletion removes their rows.
 */
final class AttachmentCleanup
{
    /** Rows read at a time when the files of a field are listed. */
    private const CHUNK = 500;

    /**
     * Gets the database, the storage that purges files and the counter of the workspace.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AttachmentStorage $storage,
        private readonly WorkspaceStorage $usage,
    ) {}

    /**
     * Counts the files of an item out of the storage of its workspace, deletes their rows and queues the deletion of the
     * item's folder (on every disk the files were written to), to be run once the transaction that deletes the item is
     * committed. Does nothing for an item that holds no file.
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
        $files->delete();

        $directory = AttachmentStorage::itemDirectory($workspace->ulid, $inventory->ulid, $item->ulid);

        foreach ($disks as $disk) {
            $this->storage->purgeAfterCommit((string) $disk, [], [$directory]);
        }
    }

    /**
     * Counts the files of a file or photo field out of the storage of its workspace, deletes their rows and queues the
     * deletion of the files and their thumbnails, to be run once the transaction that deletes the field is committed. Does
     * nothing for a field that holds no file.
     *
     * The paths are read before the rows go, a chunk at a time, and queued in jobs of a few hundred paths.
     *
     * @param  Workspace  $workspace  The workspace of the field, locked.
     */
    public function releaseField(Workspace $workspace, InventoryField $field): void
    {
        $files = $this->db->table('attachments')->where('field_id', $field->getKey());
        $paths = [];

        (clone $files)->select(['id', 'disk', 'path', 'thumbnail_path'])->chunkById(self::CHUNK, function (Collection $rows) use (&$paths): void {
            foreach ($rows as $row) {
                $paths[(string) $row->disk][] = (string) $row->path;

                if ($row->thumbnail_path !== null) {
                    $paths[(string) $row->disk][] = (string) $row->thumbnail_path;
                }
            }
        }, 'id');

        if ($paths === []) {
            return;
        }

        $this->usage->move($workspace, -(int) (clone $files)->sum('size'));
        $files->delete();

        foreach ($paths as $disk => $list) {
            $this->storage->purgeAfterCommit($disk, $list);
        }
    }
}
