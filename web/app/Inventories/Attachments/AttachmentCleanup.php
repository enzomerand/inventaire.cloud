<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

use App\Inventories\Exports\ExportCleanup;
use App\Inventories\Imports\ImportCleanup;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\Workspace;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use WeakMap;

/**
 * What becomes of the files when what holds them is deleted: they are counted out of the storage of the workspace, in the
 * transaction of the deletion, and their deletion from the disk is queued for after the commit.
 *
 * The database cascades the rows of the attachments away when an inventory or a workspace goes (they hang from their
 * inventory), and it fires no model event doing so; the rows of an item and of a field are deleted by this class, which is
 * also the part that remembers the files. Every method is called with the workspace
 * locked first, then the inventory (see {@see Locks}), so that the counter and the files that exist cannot change meanwhile,
 * and reads the attachments that are about to go before the deletion removes their rows.
 *
 * The folder of an inventory or of a workspace is worked out in the `deleting` event of its model (the rows that say which
 * disks hold files are still there) but queued only in the `deleted` event, once the `DELETE` has gone through: a deletion
 * that fails must not purge anything, and outside a transaction (a bare `$workspace->delete()`) "after the commit" is
 * immediately, which in `deleting` would be before the row is deleted. The folders of the exports of an account that is deleted
 * go through the same two steps ({@see self::collect()}, worked out by {@see ExportCleanup}), and so do those of the imports
 * it sent ({@see ImportCleanup}).
 */
final class AttachmentCleanup
{
    /** Rows read at a time when the files of a field are listed. */
    private const CHUNK = 500;

    /**
     * The folders to purge for each inventory, workspace and account that is being deleted, between its `deleting` and its
     * `deleted` event. Dropped with the model if the deletion fails.
     *
     * @var WeakMap<Model, list<array{disk: string, directory: string}>>|null
     */
    private static ?WeakMap $collected = null;

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
     * Counts the files of an inventory that is about to be deleted out of the storage of its workspace. Their rows go with
     * the inventory (the foreign key cascades) and their deletion from the disk is queued by {@see self::purgeCollected()}
     * (the `deleted` event of the inventory), after {@see self::collectForInventory()} (its `deleting` event) worked it out.
     *
     * @param  Workspace  $workspace  The workspace of the inventory, locked.
     */
    public function releaseInventory(Workspace $workspace, Inventory $inventory): void
    {
        $this->usage->move($workspace, -(int) $this->db->table('attachments')->where('inventory_id', $inventory->getKey())->sum('size'));
    }

    /**
     * Works out the folder of an inventory that is being deleted, on every disk its files were written to, to be queued by
     * {@see self::purgeCollected()} once the inventory is really gone. Called by the `deleting` event of {@see Inventory}, so
     * that every way of deleting an inventory through its model does it. Collects nothing for an inventory that holds no file and no
     * export.
     */
    public function collectForInventory(Inventory $inventory): void
    {
        // The exports and the imports of the inventory are stored under its folder too (see AttachmentStorage::exportDirectory()
        // and AttachmentStorage::importDirectory()).
        $disks = [
            ...$this->db->table('attachments')->where('inventory_id', $inventory->getKey())->distinct()->pluck('disk')->all(),
            ...$this->db->table('exports')->where('inventory_id', $inventory->getKey())->distinct()->pluck('disk')->all(),
            ...$this->db->table('imports')->where('inventory_id', $inventory->getKey())->distinct()->pluck('disk')->all(),
        ];

        if ($disks === []) {
            $this->drop($inventory);

            return;
        }

        $workspace = (string) $this->db->table('workspaces')->where('id', $inventory->workspace_id)->value('ulid');

        $this->remember($inventory, array_map(strval(...), $disks), AttachmentStorage::inventoryDirectory($workspace, $inventory->ulid));
    }

    /**
     * Works out the folder of a workspace that is being deleted, to be queued by {@see self::purgeCollected()} once the
     * workspace is really gone. Called by the `deleting` event of {@see Workspace}, so that deleting a workspace, an account
     * that owned one alone, or an account through the administration all do it. The rows go with the workspace (the foreign
     * keys cascade); there is no counter to move.
     *
     * The disks are the ones the files of its inventories were written to, and always the one new files are written to:
     * an upload writes its file before its row is committed, so a file may be on the disk with no row to say which one.
     * Deleting a folder that does not exist is harmless.
     */
    public function collectForWorkspace(Workspace $workspace): void
    {
        $disks = [
            ...$this->db->table('attachments')
                ->join('inventories', 'inventories.id', '=', 'attachments.inventory_id')
                ->where('inventories.workspace_id', $workspace->getKey())
                ->distinct()
                ->pluck('attachments.disk')
                ->all(),
            // The exports and the imports are stored under the folder of their inventory too.
            ...$this->db->table('exports')
                ->join('inventories', 'inventories.id', '=', 'exports.inventory_id')
                ->where('inventories.workspace_id', $workspace->getKey())
                ->distinct()
                ->pluck('exports.disk')
                ->all(),
            ...$this->db->table('imports')
                ->join('inventories', 'inventories.id', '=', 'imports.inventory_id')
                ->where('inventories.workspace_id', $workspace->getKey())
                ->distinct()
                ->pluck('imports.disk')
                ->all(),
        ];

        $this->remember($workspace, [...array_map(strval(...), $disks), $this->storage->diskName()], AttachmentStorage::workspaceDirectory($workspace->ulid));
    }

    /**
     * Queues the deletion of the folders collected for a model that has just been deleted (an inventory, a workspace or an
     * account), to be run once the transaction that deletes it is committed (at once when there is none). Called by the
     * `deleted` event of those models.
     */
    public function purgeCollected(Model $model): void
    {
        $collected = self::collected();
        $folders = $collected[$model] ?? [];
        $this->drop($model);

        foreach ($folders as $folder) {
            $this->storage->purgeAfterCommit($folder['disk'], [], [$folder['directory']]);
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

    /**
     * Remembers the folders to purge for a model that is being deleted (replacing what an earlier try collected), to be queued
     * by {@see self::purgeCollected()} once it is really gone. Dropped with the model if the deletion fails.
     *
     * @param  list<array{disk: string, directory: string}>  $folders  The disk and the directory of each folder.
     */
    public function collect(Model $model, array $folders): void
    {
        $collected = self::collected();
        $collected[$model] = $folders;
    }

    /**
     * Adds folders to purge to what was collected for a model that is being deleted, once a first collection replaced the earlier
     * try's (see {@see self::collect()}): the account is the model whose folders come from two places, its exports and its imports,
     * and the imports are added after the exports have been collected.
     *
     * @param  list<array{disk: string, directory: string}>  $folders  The disk and the directory of each folder.
     */
    public function collectMore(Model $model, array $folders): void
    {
        $collected = self::collected();
        $collected[$model] = [...($collected[$model] ?? []), ...$folders];
    }

    /**
     * Remembers the folder to purge on each disk, for a model that is being deleted (replacing what an earlier try collected).
     *
     * @param  array<array-key, string>  $disks  The names of the disks, possibly repeated.
     */
    private function remember(Model $model, array $disks, string $directory): void
    {
        $this->collect($model, array_map(fn (string $disk): array => ['disk' => $disk, 'directory' => $directory], array_values(array_unique($disks))));
    }

    /**
     * Forgets what was collected for a model.
     */
    private function drop(Model $model): void
    {
        $collected = self::collected();
        unset($collected[$model]);
    }

    /**
     * What was collected, by model.
     *
     * @return WeakMap<Model, list<array{disk: string, directory: string}>>
     */
    private static function collected(): WeakMap
    {
        return self::$collected ??= new WeakMap;
    }
}
