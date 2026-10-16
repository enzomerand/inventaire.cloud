<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

use App\Database\DeadlockRetries;
use App\Inventories\Exceptions\AttachmentLimitReachedException;
use App\Inventories\Exceptions\StorageLimitReachedException;
use App\Inventories\ItemRevisions;
use App\Models\Attachment;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\ItemRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

/**
 * Attaching files to items and removing them.
 *
 * A file is written to the disk **before** the transaction that records it, so that the transaction stays short and holds its
 * locks for no longer than the database needs: if that transaction fails (the quota is reached, the item was deleted
 * meanwhile, the database loses a deadlock for good), the files that were written are deleted before the failure goes on. A
 * file removed is deleted from the disk after the commit, by a queued job (see {@see AttachmentStorage::purgeAfterCommit()}).
 *
 * What is judged is judged under locks taken in the order of {@see Locks}: the account of the person who writes, the
 * workspace (its storage counter and the quota), the inventory, the item (how many files it holds). Two uploads made at the
 * same moment therefore cannot both take the last bytes of a workspace or the last place of an item.
 */
final class Attachments
{
    /**
     * Gets what the rules of attachments need.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly AttachmentStorage $storage,
        private readonly WorkspaceStorage $usage,
        private readonly Thumbnails $thumbnails,
        private readonly ItemRevisions $revisions,
    ) {}

    /**
     * What the API tells a client about the limits of attachments, so that it can check a file before it sends it.
     *
     * @return array{attachment_size: int, attachments_per_item: int}
     */
    public static function limits(): array
    {
        return [
            'attachment_size' => config()->integer('inventories.max_attachment_size'),
            'attachments_per_item' => config()->integer('inventories.max_attachments_per_item'),
        ];
    }

    /**
     * Attaches an uploaded file to an item.
     *
     * The type is detected from the content and the name is cleaned up ({@see IncomingFile}). A JPEG, PNG, WebP or GIF image
     * gets a thumbnail, when one can be made ({@see Thumbnails}); the file is kept without one when it cannot.
     *
     * @param  User  $uploader  The person who uploads it.
     *
     * @throws AttachmentLimitReachedException When the item already holds as many files as allowed.
     * @throws StorageLimitReachedException When the file would take the workspace beyond its storage.
     * @throws ModelNotFoundException When the workspace, the inventory or the item was deleted in the meantime.
     */
    public function upload(Inventory $inventory, Item $item, UploadedFile $file, User $uploader): Attachment
    {
        $incoming = IncomingFile::from($file);
        $disk = $this->storage->diskName();
        $attachment = new Attachment;
        $attachment->ulid = strtolower((string) Str::ulid());
        $attachment->path = AttachmentStorage::itemDirectory($this->workspaceUlid($inventory), $inventory->ulid, $item->ulid).'/'.$attachment->ulid;

        $image = $incoming->isImage() ? $this->thumbnails->inspect($incoming->path, $incoming->mime) : null;
        $thumbnail = $image === null ? null : $this->thumbnailOf($incoming, $image);
        $written = [];

        try {
            $this->storage->writeFrom($disk, $attachment->path, $incoming->path);
            $written[] = $attachment->path;

            if ($thumbnail !== null) {
                $attachment->thumbnail_path = AttachmentStorage::thumbnailPath($attachment->path);
                $written[] = $attachment->thumbnail_path;
                $this->storage->write($disk, $attachment->thumbnail_path, $thumbnail->jpeg);
            }

            $attachment->disk = $disk;
            $attachment->name = $incoming->name;
            $attachment->mime = $incoming->mime;
            $attachment->size = $incoming->size;
            $attachment->width = $image?->displayWidth();
            $attachment->height = $image?->displayHeight();

            return DeadlockRetries::run(fn (): Attachment => $this->db->transaction(fn (): Attachment => $this->record($inventory, $item, $uploader, $attachment)));
        } catch (Throwable $failure) {
            $this->storage->forgetNow($disk, $written);

            throw $failure;
        }
    }

    /**
     * Removes a file from an item: its row goes now, its files from the disk once the deletion is committed.
     *
     * @param  User  $editor  The person who removes it.
     *
     * @throws ModelNotFoundException When the workspace, the inventory, the item or the file was deleted in the meantime.
     */
    public function delete(Inventory $inventory, Item $item, Attachment $attachment, User $editor): void
    {
        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($inventory, $item, $attachment, $editor): void {
            $this->locks->author($editor);
            $workspace = $this->usage->lock($inventory->workspace_id);
            $locked = $this->locks->inventory($inventory->getKey());
            $lockedItem = $this->locks->itemOf($locked, $item);

            // Read last, once the item is locked: the file may have been removed by a request that held the item before this one.
            $current = Attachment::query()->whereKey($attachment->getKey())->where('item_id', $lockedItem->getKey())->first()
                ?? throw (new ModelNotFoundException)->setModel(Attachment::class, [$attachment->getKey()]);

            $this->usage->move($workspace, -$current->size);
            $current->delete();
            $this->revisions->attachment($lockedItem, $editor, ItemRevision::ATTACHMENT_REMOVED, $current->ulid, $current->name, null);
            $this->storage->purgeAfterCommit($current->disk, array_values(array_filter([$current->path, $current->thumbnail_path])));
        }));
    }

    /**
     * Writes the row of a file whose bytes are on the disk already, in the transaction that judges the limits.
     *
     * @throws AttachmentLimitReachedException
     * @throws StorageLimitReachedException
     */
    private function record(Inventory $inventory, Item $item, User $uploader, Attachment $attachment): Attachment
    {
        $this->locks->author($uploader);
        $workspace = $this->usage->lock($inventory->workspace_id);
        $locked = $this->locks->inventory($inventory->getKey());
        $lockedItem = $this->locks->itemOf($locked, $item);

        $perItem = config()->integer('inventories.max_attachments_per_item');

        if ($this->db->table('attachments')->where('item_id', $lockedItem->getKey())->count() >= $perItem) {
            throw new AttachmentLimitReachedException($perItem);
        }

        $quota = config()->integer('inventories.storage_per_workspace');

        if ($workspace->storage_used + $attachment->size > $quota) {
            throw new StorageLimitReachedException($quota);
        }

        // A fresh instance each time the transaction is tried: the one that was prepared may have been saved by a try that was rolled back.
        $row = new Attachment;
        $row->forceFill($attachment->getAttributes());
        $row->item_id = $lockedItem->getKey();
        $row->uploaded_by = $uploader->getKey();
        $row->save();

        $this->usage->move($workspace, $row->size);
        $this->revisions->attachment($lockedItem, $uploader, ItemRevision::ATTACHMENT_ADDED, $row->ulid, $row->name, null);

        return $row;
    }

    /**
     * The thumbnail of an image, or null when none is made (the image is too large to decode, or cannot be decoded).
     */
    private function thumbnailOf(IncomingFile $file, ImageInfo $image): ?Thumbnail
    {
        try {
            return $this->thumbnails->make($file->path, $file->mime, $image);
        } catch (UnreadableImageException) {
            return null;
        }
    }

    /**
     * The ULID of the workspace of an inventory, which the folder of its files is named after.
     *
     * @throws ModelNotFoundException When the workspace no longer exists.
     */
    private function workspaceUlid(Inventory $inventory): string
    {
        $ulid = Workspace::query()->whereKey($inventory->workspace_id)->value('ulid');

        return is_string($ulid) ? $ulid : throw (new ModelNotFoundException)->setModel(Workspace::class, [$inventory->workspace_id]);
    }
}
