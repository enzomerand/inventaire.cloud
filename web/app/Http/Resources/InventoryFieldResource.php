<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\InventoryField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * How the API describes a field of an inventory: the ULID that identifies it, its name, its type, the options of the
 * type in canonical form (an empty object when the type has none), whether it is required and where it stands in the
 * inventory.
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
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'type' => $this->type,
            'options' => $this->options === null || $this->options === [] ? new stdClass : $this->options,
            'required' => $this->required,
            'position' => $this->position,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
