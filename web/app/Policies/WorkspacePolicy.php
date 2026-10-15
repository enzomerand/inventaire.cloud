<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what with a workspace.
 *
 * Any member can see a workspace; only its owners can change it or manage it. Somebody who does not belong to a
 * workspace is answered as if it did not exist (a 404, not a 403): the answer must not reveal that a workspace
 * exists, or which of them the person could guess.
 *
 * The abilities of the roles live in {@see WorkspaceRole}; this policy only maps them to the actions on a workspace.
 */
final class WorkspacePolicy
{
    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * Anybody who is signed in may create a workspace: they become its owner.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Seeing the workspace: every member.
     */
    public function view(User $user, Workspace $workspace): Response
    {
        return $this->allowIf($user, $workspace, fn (WorkspaceRole $role): bool => $role->canView(), __('You are not allowed to see this workspace.'));
    }

    /**
     * Changing the workspace (its name): the owners.
     */
    public function update(User $user, Workspace $workspace): Response
    {
        return $this->allowIf($user, $workspace, fn (WorkspaceRole $role): bool => $role->canManage(), __('Only an owner of this workspace can change it.'));
    }

    /**
     * Deleting the workspace: the owners.
     */
    public function delete(User $user, Workspace $workspace): Response
    {
        return $this->allowIf($user, $workspace, fn (WorkspaceRole $role): bool => $role->canManage(), __('Only an owner of this workspace can delete it.'));
    }

    /**
     * Managing the workspace (who belongs to it, and with which role): the owners.
     */
    public function manage(User $user, Workspace $workspace): Response
    {
        return $this->allowIf($user, $workspace, fn (WorkspaceRole $role): bool => $role->canManage(), __('Only an owner of this workspace can manage it.'));
    }

    /**
     * Allows when the role of the user in the workspace satisfies the test; a non-member is told the workspace is not there.
     *
     * @param  callable(WorkspaceRole): bool  $allows
     * @param  string  $denial  What a member who lacks the right is told: it names the action that was refused.
     */
    private function allowIf(User $user, Workspace $workspace, callable $allows, string $denial): Response
    {
        $role = $this->workspaces->roleOf($user, $workspace);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return $allows($role) ? Response::allow() : Response::deny($denial);
    }
}
