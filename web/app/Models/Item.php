<?php

declare(strict_types=1);

namespace App\Models;

use App\Http\Resources\ItemResource;
use App\Inventories\ItemValues;
use App\Models\Casts\JsonObject;
use App\Models\Concerns\HasUlidRouteKey;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing in an inventory, described by values for (some of) the fields of the inventory.
 *
 * `values` is a JSON object keyed by the ULID of the field, holding each value in the canonical form its field type
 * stores (numbers are decimal strings, never JSON floats, which MySQL would turn into doubles). A field without a
 * value has no key: empty values are never stored. What is stored is validated when it is written
 * ({@see ItemValues}) and cleaned up when it is read ({@see ItemResource}).
 *
 * @property int $id
 * @property string $ulid
 * @property int $inventory_id
 * @property array<string, mixed> $values
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['values'])]
final class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory, HasUlidRouteKey;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'values' => JsonObject::class,
        ];
    }

    /**
     * The inventory the item belongs to.
     *
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}
