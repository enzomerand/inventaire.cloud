<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

use App\Database\DeadlockRetries;
use App\Inventories\Exceptions\AttachmentLimitReachedException;
use App\Inventories\Exceptions\FieldFileLimitReachedException;
use App\Inventories\Exceptions\StorageLimitReachedException;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\UploadedFilesType;
use App\Inventories\ItemRevisions;
use App\Models\Attachment;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\ItemRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Attaching files to items and removing them.
 *
 * A file is a plain attachment of the item, or the content of one of its file or photo fields ({@see UploadedFilesType}): then
 * the ULID of the attachment is also appended to the value of the field (and taken out when the file is removed), in the
 * same transaction, under the lock of the item, so that the list and the files cannot disagree. A photo field takes images
 * only (JPEG, PNG, WebP or GIF, by their content).
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
        private readonly FieldTypes $types,
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
     * gets a thumbnail, when one can be made ({@see Thumbnails}); the file is kept without one when it cannot (an animated WebP
     * that GD cannot decode, an image too large to be decoded), in a photo field too: a photo field refuses what is not an
     * image of those types, not an image it cannot draw.
     *
     * @param  User  $uploader  The person who uploads it.
     * @param  string|null  $field  The ULID (in any case) of the file or photo field of the inventory the file fills, or null for a plain attachment.
     *
     * @throws AttachmentLimitReachedException When the item already holds as many files as allowed.
     * @throws FieldFileLimitReachedException When the field already holds as many files as its `max_files`.
     * @throws StorageLimitReachedException When the file would take the workspace beyond its storage.
     * @throws ValidationException When the field is not a file or photo field of the inventory, or the file is not what a photo field takes.
     * @throws ModelNotFoundException When the workspace, the inventory or the item was deleted in the meantime.
     */
    public function upload(Inventory $inventory, Item $item, UploadedFile $file, User $uploader, ?string $field = null): Attachment
    {
        $incoming = IncomingFile::from($file);
        $fieldUlid = $field === null ? null : strtolower($field);
        // Looked at before the transaction to know what to check the file against; judged again under the locks.
        $imagesOnly = $fieldUlid !== null && $this->fieldOf($inventory, $fieldUlid)->imagesOnly;
        $disk = $this->storage->diskName();
        $attachment = new Attachment;
        $attachment->ulid = strtolower((string) Str::ulid());
        $attachment->path = AttachmentStorage::itemDirectory($this->workspaceUlid($inventory), $inventory->ulid, $item->ulid).'/'.$attachment->ulid;

        $image = $incoming->isImage() ? $this->thumbnails->inspect($incoming->path, $incoming->mime) : null;
        $thumbnail = $this->thumbnailOf($incoming, $image, $imagesOnly);
        $written = [];

        try {
            // Noted before it is written: a disk that fails half way through leaves a part of the file behind, which the
            // cleanup below must delete too.
            $written[] = $attachment->path;
            $this->storage->writeFrom($disk, $attachment->path, $incoming->path);

            if ($thumbnail !== null) {
                $attachment->thumbnail_path = AttachmentStorage::thumbnailPath($attachment->path);
                $written[] = $attachment->thumbnail_path;
                $this->storage->write($disk, $attachment->thumbnail_path, $thumbnail);
            }

            $attachment->disk = $disk;
            $attachment->name = $incoming->name;
            $attachment->mime = $incoming->mime;
            $attachment->size = $incoming->size;
            $attachment->width = $image?->displayWidth();
            $attachment->height = $image?->displayHeight();

            return DeadlockRetries::run(fn (): Attachment => $this->db->transaction(fn (): Attachment => $this->record($inventory, $item, $uploader, $attachment, $fieldUlid)));
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
            $workspace = $this->locks->workspace($inventory->workspace_id);
            $locked = $this->locks->inventory($inventory->getKey());
            $lockedItem = $this->locks->itemOf($locked, $item);

            // Read last, once the item is locked: the file may have been removed by a request that held the item before this one.
            $current = Attachment::query()->whereKey($attachment->getKey())->where('item_id', $lockedItem->getKey())->first()
                ?? throw (new ModelNotFoundException)->setModel(Attachment::class, [$attachment->getKey()]);

            $fieldUlid = $current->field_id === null ? null : InventoryField::query()->whereKey($current->field_id)->value('ulid');
            $fieldUlid = is_string($fieldUlid) ? $fieldUlid : null;

            $this->usage->move($workspace, -$current->size);
            $current->delete();

            if ($fieldUlid !== null) {
                $this->takeOutOfValue($lockedItem, $fieldUlid, $current->ulid, $editor);
            }

            $this->revisions->attachment($lockedItem, $editor, ItemRevision::ATTACHMENT_REMOVED, $current->ulid, $current->name, $fieldUlid);
            $this->storage->purgeAfterCommit($current->disk, array_values(array_filter([$current->path, $current->thumbnail_path])));
        }));
    }

    /**
     * Writes the row of a file whose bytes are on the disk already, in the transaction that judges the limits.
     *
     * @param  string|null  $fieldUlid  The field the file fills, in lowercase.
     *
     * @throws AttachmentLimitReachedException
     * @throws FieldFileLimitReachedException
     * @throws StorageLimitReachedException
     * @throws ValidationException
     */
    private function record(Inventory $inventory, Item $item, User $uploader, Attachment $attachment, ?string $fieldUlid): Attachment
    {
        $this->locks->author($uploader);
        $workspace = $this->locks->workspace($inventory->workspace_id);
        $locked = $this->locks->inventory($inventory->getKey());
        $lockedItem = $this->locks->itemOf($locked, $item);

        // Read last, once every lock is taken: the first plain read of the transaction opens its snapshot.
        $field = null;
        $files = [];

        if ($fieldUlid !== null) {
            $field = $this->fieldOf($locked, $fieldUlid);
            $files = $lockedItem->values[$fieldUlid] ?? [];
            $files = is_array($files) ? array_values($files) : [];

            if (count($files) >= $field->maxFiles) {
                throw new FieldFileLimitReachedException($field->maxFiles);
            }
        }

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
        $row->inventory_id = $locked->getKey();
        $row->item_id = $lockedItem->getKey();
        $row->field_id = $field?->field->getKey();
        $row->uploaded_by = $uploader->getKey();
        $row->save();

        $this->usage->move($workspace, $row->size);

        if ($field !== null) {
            $values = $lockedItem->values;
            $values[$field->field->ulid] = [...$files, $row->ulid];
            $lockedItem->values = $values;
            $lockedItem->updated_by = $uploader->getKey();
            $lockedItem->save();
        }

        $this->revisions->attachment($lockedItem, $uploader, ItemRevision::ATTACHMENT_ADDED, $row->ulid, $row->name, $field?->field->ulid);

        return $row;
    }

    /**
     * Takes the ULID of a file out of the value of its field in the item, in the transaction that removes the file. The key
     * goes when the list is empty (nothing empty is stored).
     */
    private function takeOutOfValue(Item $item, string $fieldUlid, string $attachment, User $editor): void
    {
        $values = $item->values;
        $files = $values[$fieldUlid] ?? [];
        $kept = is_array($files) ? array_values(array_filter($files, fn (mixed $ulid): bool => $ulid !== $attachment)) : [];

        if ($kept === $files) {
            return;
        }

        if ($kept === []) {
            unset($values[$fieldUlid]);
        } else {
            $values[$fieldUlid] = $kept;
        }

        $item->values = $values;
        $item->updated_by = $editor->getKey();
        $item->save();
    }

    /**
     * The field of an inventory that a file is for, and what it takes.
     *
     * @param  string  $ulid  In lowercase.
     *
     * @throws ValidationException When the inventory has no such field, or it is not a file or photo field.
     */
    private function fieldOf(Inventory $inventory, string $ulid): TargetField
    {
        $field = $inventory->fields()->get()->first(fn (InventoryField $candidate): bool => $candidate->ulid === $ulid);

        if ($field === null) {
            throw ValidationException::withMessages(['field' => [__('This field does not exist in this inventory.')]]);
        }

        $type = $this->types->of($field);

        if (! $type instanceof UploadedFilesType) {
            throw ValidationException::withMessages(['field' => [__('This field does not take files.')]]);
        }

        return new TargetField($field, $type->maxFiles($field), $type->imagesOnly());
    }

    /**
     * The thumbnail of an image (the bytes of a JPEG), or null when none is made.
     *
     * A photo field (`$imagesOnly`) refuses a file whose content is not an image of a type that gets a thumbnail. An image of
     * one of those types is kept when it has no thumbnail, in a photo field as anywhere else: its header may give no size, GD
     * may not decode it (an animated WebP) or it may be too large to be decoded.
     *
     * @param  ImageInfo|null  $image  What the header says, null when the file is not an image of a supported type or its header is unreadable.
     *
     * @throws ValidationException When `$imagesOnly` and the file is not an image of one of the supported types.
     */
    private function thumbnailOf(IncomingFile $file, ?ImageInfo $image, bool $imagesOnly): ?string
    {
        if ($imagesOnly && ! $file->isImage()) {
            throw ValidationException::withMessages(['file' => [__('This field only takes images: JPEG, PNG, WebP or GIF.')]]);
        }

        if ($image === null) {
            return null;
        }

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
