<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUlidRouteKey;
use App\Policies\InventoryPolicy;
use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An inventory: a named table of items that share the same typed fields, kept in a workspace.
 *
 * Who may see or change it is decided by the role of the person in the workspace ({@see InventoryPolicy}).
 * Its fields say what an item can hold ({@see InventoryField}) and its items hold it ({@see Item}); both are deleted
 * with it by the database. `ulid` is the identifier the outside world uses ({@see HasUlidRouteKey}).
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description'])]
final class Inventory extends Model
{
    /** @use HasFactory<InventoryFactory> */
    use HasFactory, HasUlidRouteKey;

    /**
     * The workspace the inventory belongs to.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The fields of the inventory, in the order they are shown (a field created at the same moment comes after).
     *
     * The route key of a field is its ULID, and a field is bound through this relation (`/inventories/{inventory}/fields/{field}`),
     * so a field of another inventory is a 404.
     *
     * @return HasMany<InventoryField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(InventoryField::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The items of the inventory. Unordered: the caller says whether it wants the newest first or something else.
     *
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
