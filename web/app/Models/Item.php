<?php

declare(strict_types=1);

namespace App\Models;

use App\Http\Resources\ItemResource;
use App\Inventories\ItemSearchText;
use App\Inventories\ItemValues;
use App\Models\Casts\JsonObject;
use App\Models\Concerns\HasUlidRouteKey;
use Database\Factories\ItemFactory;
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
 * `search_text` is what the item is searched by, built from its values ({@see ItemSearchText}) in a form that ignores
 * the case and the accents. It is a derivative of `values`, only for the database to look into: it is never given out.
 * It is empty when the item holds nothing to search for, and null only when it was never built (an item that existed before
 * the column did).
 *
 * @property int $id
 * @property string $ulid
 * @property int $inventory_id
 * @property array<string, mixed> $values
 * @property string|null $search_text
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory, HasUlidRouteKey;

    /**
     * Left out of every array and JSON form of the model.
     *
     * @var list<string>
     */
    protected $hidden = ['search_text'];

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
