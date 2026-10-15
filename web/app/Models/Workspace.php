<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
final class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasUlidRouteKey;

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
