<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Inventory;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what with an inventory, its fields and its items: it all follows the role of the person in the workspace
 * the inventory belongs to.
 *
 * Any member can see an inventory and what is in it. Editors and owners change it, its fields and its items; deleting
 * an inventory is for owners (it takes every item with it), and so is sharing it through a secret read-only link. Somebody
 * who does not belong to the workspace is answered as if the inventory did not exist (a 404, not a 403), like the workspace
 * itself ({@see WorkspacePolicy}).
 *
 * The abilities of the roles live in {@see WorkspaceRole}; {@see self::abilities()} is the one place that maps them to
 * the actions on an inventory, for this policy and for the API, which tells the interface what to offer.
 */
final class InventoryPolicy
{
    /**
     * Gets the service that knows the role of a person in a workspace.
     */
    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * What a role may do with the inventories of a workspace, by action.
     *
     * - `view`: see the inventory, its fields and its items;
     * - `create`: add an inventory to the workspace;
     * - `update`: change the name and the description;
     * - `delete`: delete the inventory and everything in it;
     * - `manage_fields`: add, change, delete and order its fields;
     * - `edit_items`: add, change and delete its items;
     * - `share`: make, list and revoke its share links.
     *
     * @return array{view: bool, create: bool, update: bool, delete: bool, manage_fields: bool, edit_items: bool, share: bool}
     */
    public static function abilities(WorkspaceRole $role): array
    {
        return [
            'view' => $role->canView(),
            'create' => $role->canEdit(),
            'update' => $role->canEdit(),
            'delete' => $role->canManage(),
            'manage_fields' => $role->canEdit(),
            'edit_items' => $role->canEdit(),
            'share' => $role->canManage(),
        ];
    }

    /**
     * Seeing an inventory and what is in it: every member of its workspace.
     */
    public function view(User $user, Inventory $inventory): Response
    {
        return $this->allow($user, $inventory->workspace_id, 'view', __('You are not allowed to see this inventory.'));
    }

    /**
     * Adding an inventory to a workspace: editors and owners.
     */
    public function create(User $user, Workspace $workspace): Response
    {
        return $this->allow($user, $workspace->getKey(), 'create', __('You are not allowed to add inventories to this workspace.'));
    }

    /**
     * Changing the name and the description of an inventory: editors and owners.
     */
    public function update(User $user, Inventory $inventory): Response
    {
        return $this->allow($user, $inventory->workspace_id, 'update', __('You are not allowed to change this inventory.'));
    }

    /**
     * Deleting an inventory: the owners of its workspace.
     */
    public function delete(User $user, Inventory $inventory): Response
    {
        return $this->allow($user, $inventory->workspace_id, 'delete', __('Only an owner of this workspace can delete an inventory.'));
    }

    /**
     * Adding, changing, deleting and ordering the fields of an inventory: editors and owners.
     */
    public function manageFields(User $user, Inventory $inventory): Response
    {
        return $this->allow($user, $inventory->workspace_id, 'manage_fields', __('You are not allowed to change the fields of this inventory.'));
    }

    /**
     * Adding, changing and deleting the items of an inventory: editors and owners.
     */
    public function editItems(User $user, Inventory $inventory): Response
    {
        return $this->allow($user, $inventory->workspace_id, 'edit_items', __('You are not allowed to change the items of this inventory.'));
    }

    /**
     * Making, listing and revoking the share links of an inventory: the owners of its workspace. A link shows the whole inventory to
     * anybody who holds it, which is a decision about who sees the workspace's data, not an edit.
     */
    public function share(User $user, Inventory $inventory): Response
    {
        return $this->allow($user, $inventory->workspace_id, 'share', __('Only an owner of this workspace can share an inventory.'));
    }

    /**
     * Allows when the role of the user in the workspace has the ability; a non-member is told the inventory is not there.
     *
     * @param  int  $workspaceId  The workspace the inventory belongs to (or is to be added to).
     * @param  'view'|'create'|'update'|'delete'|'manage_fields'|'edit_items'|'share'  $ability
     * @param  string  $denial  What a member who lacks the right is told: it names the action that was refused.
     */
    private function allow(User $user, int $workspaceId, string $ability, string $denial): Response
    {
        $role = $this->workspaces->roleIn($user, $workspaceId);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return self::abilities($role)[$ability] ? Response::allow() : Response::deny($denial);
    }
}
