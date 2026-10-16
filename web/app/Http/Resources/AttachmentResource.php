<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How the API describes a file attached to an item: its ULID, the file or photo field it fills (or none), its name, type
 * and size, the size of an image, whether it has a thumbnail, when it was uploaded and the addresses to fetch it from.
 *
 * The addresses (`url`, `thumbnail_url`) are paths of the API, relative to the host: a client signed in with the session or a
 * token calls them as it calls the rest of the API. The file is a download unless `?inline=1` is added (for the types that
 * may be shown); the thumbnail is null when there is none. Never the disk, the path, or who uploaded it.
 *
 * @mixin Attachment
 */
final class AttachmentResource extends JsonResource
{
    /**
     * Describes a file against the item and the inventory it is in.
     *
     * @param  Attachment  $resource
     * @param  string|null  $fieldUlid  The ULID of the field the file fills, when it fills one.
     * @param  string  $inventoryUlid  The ULID of the inventory.
     * @param  string  $itemUlid  The ULID of the item.
     */
    public function __construct($resource, private readonly ?string $fieldUlid, private readonly string $inventoryUlid, private readonly string $itemUlid)
    {
        parent::__construct($resource);
    }

    /**
     * The file, as the API gives it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $parameters = ['inventory' => $this->inventoryUlid, 'item' => $this->itemUlid, 'attachment' => $this->ulid];

        return [
            'id' => $this->ulid,
            'field' => $this->fieldUlid,
            'name' => $this->name,
            'mime' => $this->mime,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'has_thumbnail' => $this->resource->hasThumbnail(),
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'url' => route('api.v1.inventories.items.attachments.show', $parameters, false),
            'thumbnail_url' => $this->resource->hasThumbnail() ? route('api.v1.inventories.items.attachments.thumbnail', $parameters, false) : null,
        ];
    }
}
