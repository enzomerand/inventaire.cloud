<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Inventories\ItemValues;
use App\Models\Attachment;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use stdClass;

/**
 * How the API describes an item: the ULID that identifies it, its values as an object keyed by the ULID of the field,
 * the files attached to it ({@see AttachmentResource}) and its dates.
 *
 * The values are built from the fields of the inventory, not from the keys the item holds: a value whose field no
 * longer exists is never given back, and each value is cleaned up by the type of its field (a choice that was deleted
 * from a select is gone). Nothing says who wrote the item: authors are not exposed.
 *
 * @mixin Item
 */
final class ItemResource extends JsonResource
{
    /**
     * Describes an item against the fields of its inventory.
     *
     * @param  Item  $resource
     * @param  Collection<int, InventoryField>  $fields  The fields of the item's inventory, in order.
     * @param  Inventory  $inventory  The inventory of the item: its ULID is part of the addresses of the files.
     */
    public function __construct($resource, private readonly Collection $fields, private readonly Inventory $inventory)
    {
        parent::__construct($resource);
    }

    /**
     * The item, as the API gives it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('attachments.uploader:id,name');
        $values = app(ItemValues::class)->present($this->fields, $this->resource->values);

        return [
            'id' => $this->ulid,
            // An empty object, not an empty list: the same shape whether the item holds something or not.
            'values' => $values === [] ? new stdClass : $values,
            'attachments' => $this->resource->attachments
                ->map(fn (Attachment $attachment): array => (new AttachmentResource($attachment, $this->fieldUlidOf($attachment), $this->inventory->ulid, $this->ulid))->resolve($request))
                ->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The ULID of the field a file fills, or null for a plain attachment.
     */
    private function fieldUlidOf(Attachment $attachment): ?string
    {
        return $attachment->field_id === null ? null : $this->fields->firstWhere('id', $attachment->field_id)?->ulid;
    }
}
