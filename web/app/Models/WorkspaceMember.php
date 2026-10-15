<?php

declare(strict_types=1);

namespace App\Models;

use App\Workspaces\WorkspaceRole;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * The membership of a user in a workspace, with the role they hold there.
 *
 * A pivot of {@see Workspace::members()} and {@see User::workspaces()}, and a model of its own for the code that
 * needs to query memberships directly. One row per user and workspace.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property WorkspaceRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class WorkspaceMember extends Pivot
{
    protected $table = 'workspace_user';

    /** The table has an `id` of its own (a primary key every managed database server accepts). */
    public $incrementing = true;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
        ];
    }
}
