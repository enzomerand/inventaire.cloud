<?php

declare(strict_types=1);

namespace App\Models;

use App\Inventories\Attachments\AttachmentCleanup;
use App\Inventories\Attachments\AttachmentStorage;
use App\Models\Concerns\HasUlidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file attached to an item, with or without a file or photo field to sit in.
 *
 * The row is the source of truth for the file: where it is stored (`disk`, `path`, and `thumbnail_path` for the small
 * JPEG made from an image, see {@see AttachmentStorage}), what it is (`mime` is detected from the content when it is
 * uploaded, never taken from what the client says; `size` in bytes; `width` and `height` of an image, in the orientation
 * it is shown in) and the name it had when it was uploaded (`name`, cleaned up). The name is for display and for the
 * download only: it is never part of a path. `ulid` is the identifier the outside world uses ({@see HasUlidRouteKey}).
 *
 * The files of a workspace add up to the `storage_used` of the workspace, which only the code that adds and removes
 * attachments moves. A row goes with its inventory (the foreign key cascades); with its item or its field, it is the code
 * that deletes either that deletes the row ({@see AttachmentCleanup}), since the database holds no foreign key to them.
 *
 * @property int $id
 * @property string $ulid
 * @property int $inventory_id
 * @property int $item_id
 * @property int|null $field_id
 * @property string $disk
 * @property string $path
 * @property string|null $thumbnail_path
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Attachment extends Model
{
    use HasUlidRouteKey;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * The item the file is attached to.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The file or photo field the file fills, or none for a plain attachment.
     *
     * @return BelongsTo<InventoryField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(InventoryField::class, 'field_id');
    }

    /**
     * The person who uploaded it, or none when their account was deleted since.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Whether a thumbnail was made for the file.
     */
    public function hasThumbnail(): bool
    {
        return $this->thumbnail_path !== null;
    }
}
