<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\QueryKind;
use App\Models\InventoryField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * How the API describes a field of an inventory: the ULID that identifies it, its name, its type, the options of the
 * type in canonical form (an empty object when the type has none), whether it is required and where it stands in the
 * inventory.
 *
 * It also says how the list of items treats the field, so that a client does not have to know the types: `query_kind`
 * (how it is sorted and filtered, see {@see QueryKind}: `text`, `number`, `temporal`, `boolean`, `choice`, `choices` or
 * `tags`) and `summable` (whether the totals of the list can add its values up). A field whose type this version does not
 * know (after a rollback) has no `query_kind` (null) and does not add up.
 *
 * @mixin InventoryField
 */
final class InventoryFieldResource extends JsonResource
{
    /**
     * The field, as the API gives it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = app(FieldTypes::class)->of($this->resource);

        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'type' => $this->type,
            'options' => $this->options === null || $this->options === [] ? new stdClass : $this->options,
            'required' => $this->required,
            'position' => $this->position,
            'query_kind' => $type?->queryKind()->value,
            'summable' => $type?->summable() ?? false,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
