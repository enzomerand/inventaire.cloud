<?php

declare(strict_types=1);

namespace App\Workspaces;

/**
 * What a member may do in a workspace.
 *
 * The roles are cumulative: an editor can do what a viewer does, an owner what an editor does. The abilities are
 * asked through the helpers below (never by comparing roles), so that a rule only has to change in one place.
 * `canView()` and `canEdit()` are for the content of a workspace (its inventories, from lot 4 on); `canManage()` is
 * for the workspace itself (rename, delete, and later its members).
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Viewer = 'viewer';

    /**
     * Whether the member can read the workspace and its content: every member can.
     */
    public function canView(): bool
    {
        return true;
    }

    /**
     * Whether the member can change the content of the workspace.
     */
    public function canEdit(): bool
    {
        return $this !== self::Viewer;
    }

    /**
     * Whether the member can manage the workspace itself: rename it, delete it, and decide who belongs to it.
     */
    public function canManage(): bool
    {
        return $this === self::Owner;
    }
}
