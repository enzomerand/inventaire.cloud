<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Attachments\AttachmentCleanup;
use App\Inventories\Attachments\AttachmentStorage;
use App\Models\Import;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * What becomes of the source files of the imports when what holds them goes: the folder of an import is purged once the deletion that
 * removes it is committed, so that a deletion that fails purges nothing.
 *
 * An import that is deleted on its own ({@see Import}, its `deleted` event) queues the purge of its folder at once. The ones that go
 * with their inventory or their workspace are in the folders those purge by prefix (an import is stored under the folder of its
 * inventory: see {@see AttachmentStorage::importDirectory()}). The ones that go with the account of the person who sent them are
 * anywhere in the workspaces they could change, so the folders are worked out when the account is about to be deleted (the rows that
 * say where they are are still there), and queued only once it really is (the database cascades the rows and fires no event for
 * them). {@see AttachmentCleanup} remembers the folders and queues them, as it does for an inventory or a workspace.
 */
final class ImportCleanup
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
     * Queues the purge of the folder of an import that has just been deleted, to be run once the transaction that deletes it is
     * committed (at once when there is none). Nothing is queued when its inventory is gone too (the folder of the inventory is
     * purged with it).
     *
     * It is queued for an import that is over and has no `path` as well: the commit that ends an import empties `path` and the source
     * is deleted afterwards, so a worker that stops between the two leaves a file that no row points to any more, and the folder named
     * by the import is the only way left to it. Purging a folder that is empty costs one call to the disk.
     */
    public function purge(Import $import): void
    {
        $workspace = $this->db->table('inventories')
            ->join('workspaces', 'workspaces.id', '=', 'inventories.workspace_id')
            ->where('inventories.id', $import->inventory_id)
            ->select(['workspaces.ulid as workspace', 'inventories.ulid as inventory'])
            ->first();

        if ($workspace === null) {
            return;
        }

        $this->storage->purgeAfterCommit($import->disk, [], [AttachmentStorage::importDirectory((string) $workspace->workspace, (string) $workspace->inventory, $import->ulid)]);
    }

    /**
     * Works out the folders of the imports of an account that is being deleted, to be queued by
     * {@see AttachmentCleanup::purgeCollected()} once the account is really gone. Called by the `deleting` event of {@see User}, so
     * that every way of deleting an account does it.
     */
    public function collectForUser(User $user): void
    {
        $rows = $this->db->table('imports')
            ->join('inventories', 'inventories.id', '=', 'imports.inventory_id')
            ->join('workspaces', 'workspaces.id', '=', 'inventories.workspace_id')
            ->where('imports.user_id', $user->getKey())
            ->select(['imports.disk', 'imports.ulid as import', 'inventories.ulid as inventory', 'workspaces.ulid as workspace'])
            ->get();

        $folders = [];

        foreach ($rows as $row) {
            $folders[] = ['disk' => (string) $row->disk, 'directory' => AttachmentStorage::importDirectory((string) $row->workspace, (string) $row->inventory, (string) $row->import)];
        }

        $this->attachments->collectMore($user, $folders);
    }
}
