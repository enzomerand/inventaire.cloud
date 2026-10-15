<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Inventories\ItemValues;
use App\Models\InventoryField;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use stdClass;

/**
 * How the API describes an item: the ULID that identifies it, its values as an object keyed by the ULID of the field,
 * and its dates.
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
     */
    public function __construct($resource, private readonly Collection $fields)
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
        $values = app(ItemValues::class)->present($this->fields, $this->resource->values);

        return [
            'id' => $this->ulid,
            // An empty object, not an empty list: the same shape whether the item holds something or not.
            'values' => $values === [] ? new stdClass : $values,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
