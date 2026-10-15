<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Inventories\Fields\FieldType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How the API describes a type of field: its key (the `type` of a field) and its name in the language of the request.
 *
 * @mixin FieldType
 */
final class FieldTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->key(),
            'label' => $this->label(),
        ];
    }
}
