<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\Attachments\AttachmentCleanup;
use App\Inventories\Attachments\AttachmentStorage;
use App\Models\Export;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * What becomes of the files of the exports when what holds them goes: the folder of an export is purged once the deletion that
 * removes it is committed, so that a deletion that fails purges nothing.
 *
 * An export that is deleted on its own ({@see Export}, its `deleted` event) queues the purge of its folder at once. The ones that
 * go with their inventory or their workspace are in the folders those purge by prefix (an export is stored under the folder of its
 * inventory: see {@see AttachmentStorage::exportDirectory()}). The ones that go with the account of the person who asked for them
 * are anywhere in the workspaces they could read, so the folders are worked out when the account is about to be deleted (the rows
 * that say where they are are still there), and queued only once it really is (the database cascades the rows and fires no event
 * for them). {@see AttachmentCleanup} remembers the folders and queues them, as it does for an inventory or a workspace.
 */
final class ExportCleanup
{
    /**
     * Gets the database, the storage that purges files and the cleanup that remembers the folders of a deleted account.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AttachmentStorage $storage,
        private readonly AttachmentCleanup $attachments,
    ) {}

    /**
     * Queues the purge of the folder of an export that has just been deleted, to be run once the transaction that deletes it is
     * committed (at once when there is none). Nothing is queued when its inventory is gone too: the folder of the inventory is
     * purged with it.
     */
    public function purge(Export $export): void
    {
        $workspace = $this->db->table('inventories')
            ->join('workspaces', 'workspaces.id', '=', 'inventories.workspace_id')
            ->where('inventories.id', $export->inventory_id)
            ->select(['workspaces.ulid as workspace', 'inventories.ulid as inventory'])
            ->first();

        if ($workspace === null) {
            return;
        }

        $this->storage->purgeAfterCommit($export->disk, [], [AttachmentStorage::exportDirectory((string) $workspace->workspace, (string) $workspace->inventory, $export->ulid)]);
    }

    /**
     * Works out the folders of the exports of an account that is being deleted, to be queued by
     * {@see AttachmentCleanup::purgeCollected()} once the account is really gone. Called by the `deleting` event of {@see User}, so
     * that every way of deleting an account does it.
     */
    public function collectForUser(User $user): void
    {
        $rows = $this->db->table('exports')
            ->join('inventories', 'inventories.id', '=', 'exports.inventory_id')
            ->join('workspaces', 'workspaces.id', '=', 'inventories.workspace_id')
            ->where('exports.user_id', $user->getKey())
            ->select(['exports.disk', 'exports.ulid as export', 'inventories.ulid as inventory', 'workspaces.ulid as workspace'])
            ->get();

        $folders = [];

        foreach ($rows as $row) {
            $folders[] = ['disk' => (string) $row->disk, 'directory' => AttachmentStorage::exportDirectory((string) $row->workspace, (string) $row->inventory, (string) $row->export)];
        }

        $this->attachments->collect($user, $folders);
    }
}
