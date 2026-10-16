<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

use App\Models\Workspace;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * The number of bytes the files of a workspace use, kept on the workspace's row (`storage_used`) so that nothing has to
 * add up the attachments to judge a quota.
 *
 * The workspace is locked by whoever moves the counter ({@see self::lock()}, before the inventory: see {@see Locks}), so the
 * value read with the lock is the one to move, and the quota is judged on it by the same transaction that writes the file's
 * row. Only this class moves the counter, in the transaction that adds or removes the attachment.
 */
final class WorkspaceStorage
{
    /**
     * Gets the database and the locks.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
    ) {}

    /**
     * Locks the workspace and returns it as it is now.
     *
     * @throws ModelNotFoundException When it was deleted in the meantime.
     */
    public function lock(int|string $workspaceId): Workspace
    {
        return $this->locks->workspace($workspaceId);
    }

    /**
     * Moves the counter of a locked workspace by the bytes just added or removed.
     *
     * It is written with the query builder, which does not touch `updated_at` (storing a file is not a change of the
     * workspace), and the locked copy in memory gets the new value. The counter never goes below zero: the column is
     * unsigned on MySQL and MariaDB, which refuse a negative value with an error instead of clamping it, so a counter that
     * was already off (it should not be) must not turn a deletion into a failure. It is a fault all the same, so it is
     * written in the log (`inventaire:upgrade` adds the sizes up again and corrects the counters).
     *
     * @param  Workspace  $locked  The workspace as just read under the lock.
     * @param  int  $by  Bytes added (positive) or removed (negative).
     */
    public function move(Workspace $locked, int $by): void
    {
        if ($by === 0) {
            return;
        }

        $used = $locked->storage_used + $by;

        if ($used < 0) {
            Log::warning('The storage counter of a workspace would have gone below zero: it was kept at zero.', ['workspace_id' => $locked->getKey(), 'stored' => $locked->storage_used, 'by' => $by]);

            $used = 0;
        }

        $this->db->table('workspaces')->where('id', $locked->getKey())->update(['storage_used' => $used]);

        $locked->setAttribute('storage_used', $used);
        $locked->syncOriginalAttribute('storage_used');
    }
}
