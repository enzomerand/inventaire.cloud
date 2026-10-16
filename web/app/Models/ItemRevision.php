<?php

declare(strict_types=1);

namespace App\Models;

use App\Inventories\ItemRevisions;
use App\Inventories\Items;
use App\Models\Casts\JsonObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the history of an item: who changed what, and when.
 *
 * It is written by {@see ItemRevisions}, in the transaction of the change it describes, and never changed afterwards. It
 * goes with its item (deleted by {@see Items::delete()}) and with its inventory (the foreign key cascades); when the account
 * that wrote it is deleted the revision stays and forgets the person (`user_id` is null). `changes` is a JSON object whose shape depends on the `action`:
 *
 * - `created` and `updated`: the stored values that changed, keyed by the ULID of the field, each as
 *   `{"before": <stored value or null>, "after": <stored value or null>}`. For `created` that is every value the item was
 *   given (`before` is null). The values are the raw stored ones, so that the history does not depend on the options the
 *   field has when it is read; it is presented with the current definition of the field when it is given out;
 * - `attachment_added` and `attachment_removed`: `{"attachment": "<ULID>", "name": "<name of the file>", "field": "<ULID
 *   of the file or photo field>|null"}`.
 *
 * @property int $id
 * @property int $inventory_id
 * @property int $item_id
 * @property int|null $user_id
 * @property string $action
 * @property array<string, mixed> $changes
 * @property Carbon|null $created_at
 */
final class ItemRevision extends Model
{
    /** The item was added. */
    public const CREATED = 'created';

    /** Values of the item were changed. */
    public const UPDATED = 'updated';

    /** A file was attached to the item. */
    public const ATTACHMENT_ADDED = 'attachment_added';

    /** A file was removed from the item. */
    public const ATTACHMENT_REMOVED = 'attachment_removed';

    /** A revision has a date of creation and is never updated. */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'changes' => JsonObject::class,
        ];
    }

    /**
     * The item the revision is about.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The person who made the change, or none when their account was deleted since.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
