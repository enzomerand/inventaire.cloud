<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Inventories\Attachments\AttachmentCleanup;
use App\Inventories\Attachments\AttachmentStorage;
use App\Models\PhotoAnalysis;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * What becomes of the photos of the analyses when what holds them goes: the folder of an analysis is purged once the deletion that
 * removes it is committed, so that a deletion that fails purges nothing.
 *
 * An analysis that is deleted on its own ({@see PhotoAnalysis}, its `deleted` event) queues the purge of its folder at once. The ones
 * that go with their inventory or their workspace are in the folders those purge by prefix (an analysis is stored under the folder of
 * its inventory: see {@see AttachmentStorage::analysisDirectory()}). The ones that go with the account of the person who sent them are
 * anywhere in the workspaces they could change, so the folders are worked out when the account is about to be deleted (the rows that
 * say where they are are still there), and queued only once it really is (the database cascades the rows and fires no event for
 * them). {@see AttachmentCleanup} remembers the folders and queues them, as it does for an inventory or a workspace.
 */
final class PhotoAnalysisCleanup
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
     * Queues the purge of the folder of an analysis that has just been deleted, to be run once the transaction that deletes it is
     * committed (at once when there is none). Nothing is queued when its inventory is gone too (the folder of the inventory is purged
     * with it).
     *
     * It is queued for an analysis that is over as well: the job deletes the photos in a `finally`, but a worker that is killed leaves
     * them, and the folder named by the analysis is the only way left to them. Purging a folder that is empty costs one call to the
     * disk.
     */
    public function purge(PhotoAnalysis $analysis): void
    {
        $directory = $this->directory($analysis);

        if ($directory !== null) {
            $this->storage->purgeAfterCommit($analysis->disk, [], [$directory]);
        }
    }

    /**
     * Deletes the photos of an analysis now, which is what the job does when it ends, whatever became of it: the photos of a person must
     * not stay on the disk a minute longer than the analysis needs them. A disk that refuses is not a failure of the analysis: the
     * folder is queued for deletion instead ({@see AttachmentStorage::purgeAfterCommit()}, which tries again and says in the log what it
     * could not delete).
     */
    public function deleteNow(PhotoAnalysis $analysis): void
    {
        $directory = $this->directory($analysis);

        if ($directory === null) {
            return;
        }

        try {
            if ($this->storage->disk($analysis->disk)->deleteDirectory($directory)) {
                return;
            }
        } catch (Throwable) {
            // Queued below, with the retries of the purge job.
        }

        $this->storage->purgeAfterCommit($analysis->disk, [], [$directory]);
    }

    /**
     * The folder of the photos of an analysis, or null when its inventory is gone (the folder of the inventory is purged with it).
     */
    public function directory(PhotoAnalysis $analysis): ?string
    {
        $workspace = $this->db->table('inventories')
            ->join('workspaces', 'workspaces.id', '=', 'inventories.workspace_id')
            ->where('inventories.id', $analysis->inventory_id)
            ->select(['workspaces.ulid as workspace', 'inventories.ulid as inventory'])
            ->first();

        return $workspace === null ? null : AttachmentStorage::analysisDirectory((string) $workspace->workspace, (string) $workspace->inventory, $analysis->ulid);
    }

    /**
     * Works out the folders of the analyses of an account that is being deleted, to be queued by
     * {@see AttachmentCleanup::purgeCollected()} once the account is really gone. Called by the `deleting` event of {@see User}, so
     * that every way of deleting an account does it.
     */
    public function collectForUser(User $user): void
    {
        $rows = $this->db->table('photo_analyses')
            ->join('inventories', 'inventories.id', '=', 'photo_analyses.inventory_id')
            ->join('workspaces', 'workspaces.id', '=', 'inventories.workspace_id')
            ->where('photo_analyses.user_id', $user->getKey())
            ->select(['photo_analyses.disk', 'photo_analyses.ulid as analysis', 'inventories.ulid as inventory', 'workspaces.ulid as workspace'])
            ->get();

        $folders = [];

        foreach ($rows as $row) {
            $folders[] = ['disk' => (string) $row->disk, 'directory' => AttachmentStorage::analysisDirectory((string) $row->workspace, (string) $row->inventory, (string) $row->analysis)];
        }

        $this->attachments->collectMore($user, $folders);
    }
}
