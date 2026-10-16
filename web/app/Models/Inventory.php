<?php

declare(strict_types=1);

namespace App\Models;

use App\Inventories\Attachments\AttachmentCleanup;
use App\Inventories\Items;
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
 * `items_count` is the number of its items, kept on the row so that nothing has to count them: it is moved only by
 * {@see Items}, with the inventory locked, in the transaction that adds or deletes the item.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property string $name
 * @property string|null $description
 * @property int $items_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description'])]
final class Inventory extends Model
{
    /** @use HasFactory<InventoryFactory> */
    use HasFactory, HasUlidRouteKey;

    /**
     * Has the files of the inventory deleted from the disk once its deletion is committed, whichever way the model is
     * deleted (the database cascades the rows, and fires no event for them: see {@see AttachmentCleanup}). What to delete is
     * worked out before the row goes and queued only after it has: a deletion that fails purges nothing.
     */
    protected static function booted(): void
    {
        self::deleting(function (self $inventory): void {
            app(AttachmentCleanup::class)->collectForInventory($inventory);
        });

        self::deleted(function (self $inventory): void {
            app(AttachmentCleanup::class)->purgeCollected($inventory);
        });
    }

    /**
     * The attributes a new inventory starts with: no item, as the column does.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['items_count' => 0];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'items_count' => 'integer',
        ];
    }

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
     * The exports asked for from the inventory, whoever asked. The route key of an export is its ULID, and an export is bound
     * through this relation (`/inventories/{inventory}/exports/{export}`), so an export of another inventory is a 404.
     *
     * @return HasMany<Export, $this>
     */
    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
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
