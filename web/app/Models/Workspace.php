<?php

declare(strict_types=1);

namespace App\Models;

use App\Inventories\Attachments\AttachmentCleanup;
use App\Inventories\Attachments\WorkspaceStorage;
use App\Models\Concerns\HasUlidRouteKey;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A workspace: the place where a person, a team or a company keeps their inventories.
 *
 * Every account has at least one (a personal workspace is created with it) and can belong to several, with a role
 * in each ({@see WorkspaceMember}). The numeric `id` is internal; `ulid` is the identifier the outside world uses
 * (URLs, API), so that nothing reveals how many workspaces exist or lets one be guessed ({@see HasUlidRouteKey}).
 *
 * `storage_used` is the number of bytes its files use, kept on the row so that nothing has to add them up: it is moved
 * only by {@see WorkspaceStorage}, with the workspace locked, in the transaction that adds or removes the file.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property int $storage_used
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
final class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasUlidRouteKey;

    /**
     * Has the files of the workspace deleted from the disk once its deletion is committed, whichever way the model is
     * deleted (the owner deleting it, an account that owned it alone being deleted, an administrator deleting that
     * account): the database cascades the rows, and fires no event for them (see {@see AttachmentCleanup}).
     */
    protected static function booted(): void
    {
        self::deleting(function (self $workspace): void {
            app(AttachmentCleanup::class)->forgetWorkspace($workspace);
        });
    }

    /**
     * The attributes a new workspace starts with: no file, as the column does.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['storage_used' => 0];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'storage_used' => 'integer',
        ];
    }

    /**
     * The users who belong to the workspace, each with a `pivot` carrying their role.
     *
     * @return BelongsToMany<User, $this, WorkspaceMember>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->using(WorkspaceMember::class)
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    /**
     * The inventories of the workspace. They go with it when it is deleted (the foreign key cascades).
     *
     * @return HasMany<Inventory, $this>
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }
}
