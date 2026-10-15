<?php

declare(strict_types=1);

namespace App\Models;

use App\Inventories\Fields\FieldTypes;
use App\Models\Concerns\HasUlidRouteKey;
use Database\Factories\InventoryFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A field of an inventory: a name, a type that says what the values look like, the options of that type, and whether
 * an item must have a value.
 *
 * The type is fixed when the field is created (changing it would leave the values of every item meaning something
 * else). The type is a word of the registry ({@see FieldTypes}); the options are whatever
 * that type keeps, already in canonical form. The values of the items are stored under the ULID of the field.
 *
 * @property int $id
 * @property string $ulid
 * @property int $inventory_id
 * @property string $name
 * @property string $type
 * @property array<string, mixed>|null $options
 * @property bool $required
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'options', 'required', 'position'])]
final class InventoryField extends Model
{
    /** @use HasFactory<InventoryFieldFactory> */
    use HasFactory, HasUlidRouteKey;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * The inventory the field belongs to.
     *
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /**
     * One option of the field, or the default when it holds none: a field created before an option existed has no
     * entry for it.
     */
    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }
}
