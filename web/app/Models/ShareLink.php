<?php

declare(strict_types=1);

namespace App\Models;

use App\Inventories\Sharing\ShareLinks;
use App\Models\Concerns\HasUlidRouteKey;
use Database\Factories\ShareLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A share link: a secret address that shows an inventory, read-only, to anybody who holds it.
 *
 * @see ShareLinks
 *
 * @property int $id
 * @property string $ulid
 * @property int $inventory_id
 * @property int|null $created_by_id The account that made it; null once that account was deleted.
 * @property string|null $label
 * @property bool $include_files
 * @property string $token_hash
 * @property string $token_encrypted
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Inventory $inventory
 * @property-read User|null $creator
 */
#[Hidden(['token_hash', 'token_encrypted'])]
final class ShareLink extends Model
{
    /** @use HasFactory<ShareLinkFactory> */
    use HasFactory, HasUlidRouteKey;

    /**
     * The attributes that can be filled in bulk: everything but the ULID, which the model gives itself.
     *
     * @var list<string>
     */
    protected $fillable = ['inventory_id', 'created_by_id', 'label', 'include_files', 'token_hash', 'token_encrypted', 'expires_at'];

    /**
     * The attributes a new link starts with: files included, as the column does.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['include_files' => true];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'include_files' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * The inventory the link shows.
     *
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /**
     * The account that made the link, when it still exists.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The links that still work: those that never expire and those whose time is not up.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $active): Builder => $active->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
