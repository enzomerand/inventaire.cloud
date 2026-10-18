<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Models\User;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what with the official versions of an inventory: it follows the role of the person in the workspace the inventory
 * belongs to, as the policy of the inventory does ({@see InventoryPolicy}).
 *
 * Any member can read the versions and download their files. Editors and owners freeze the inventory and ask for (or cancel, or
 * send again) a signature; deleting a version, which takes its files and its signature request with it, is for owners. Somebody who
 * does not belong to the workspace is answered as if the inventory did not exist (a 404, not a 403).
 *
 * A version is always judged together with the inventory of the path it was reached through: the scoped bindings of the routes make
 * the two belong together, and a pair that does not is answered as a version that does not exist. {@see self::abilities()} is the one
 * place that maps the roles to the actions, for this policy and for the API, which tells the interface what to offer.
 */
final class InventoryVersionPolicy
{
    /**
     * Gets the service that knows the role of a person in a workspace.
     */
    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * What a role may do with the versions of an inventory, by action. The state of a version (is a signature pending? did it
     * fail?) is a matter of the version, not of the role: the resource of a version combines the two.
     *
     * - `view`: list the versions, read one and download its files;
     * - `create`: freeze the inventory as a new version;
     * - `delete`: delete a version;
     * - `cancel_signature`: cancel a request for signatures that is in progress;
     * - `retry_signature`: send a request for signatures that failed again.
     *
     * @return array{view: bool, create: bool, delete: bool, cancel_signature: bool, retry_signature: bool}
     */
    public static function abilities(WorkspaceRole $role): array
    {
        return [
            'view' => $role->canView(),
            'create' => $role->canEdit(),
            'delete' => $role->canManage(),
            'cancel_signature' => $role->canEdit(),
            'retry_signature' => $role->canEdit(),
        ];
    }

    /**
     * Listing the versions of an inventory: every member of its workspace.
     */
    public function viewAny(User $user, Inventory $inventory): Response
    {
        return $this->allow($user, $inventory, 'view', __('You are not allowed to see the official versions of this inventory.'));
    }

    /**
     * Reading a version and downloading its files: every member of the workspace.
     */
    public function view(User $user, InventoryVersion $version, Inventory $inventory): Response
    {
        return $this->allow($user, $inventory, 'view', __('You are not allowed to see the official versions of this inventory.'), $version);
    }

    /**
     * Freezing the inventory as a new version: editors and owners.
     */
    public function create(User $user, Inventory $inventory): Response
    {
        return $this->allow($user, $inventory, 'create', __('You are not allowed to freeze this inventory as an official version.'));
    }

    /**
     * Deleting a version: the owners of the workspace.
     */
    public function delete(User $user, InventoryVersion $version, Inventory $inventory): Response
    {
        return $this->allow($user, $inventory, 'delete', __('Only an owner of this workspace can delete an official version.'), $version);
    }

    /**
     * Cancelling the request for signatures of a version: editors and owners.
     */
    public function cancelSignature(User $user, InventoryVersion $version, Inventory $inventory): Response
    {
        return $this->allow($user, $inventory, 'cancel_signature', __('You are not allowed to cancel the signature request of this version.'), $version);
    }

    /**
     * Sending the request for signatures of a version again: editors and owners.
     */
    public function retrySignature(User $user, InventoryVersion $version, Inventory $inventory): Response
    {
        return $this->allow($user, $inventory, 'retry_signature', __('You are not allowed to send the signature request of this version again.'), $version);
    }

    /**
     * Allows when the role of the user in the workspace has the ability; a non-member, and a version that is not the inventory's, are
     * told it is not there.
     *
     * @param  'view'|'create'|'delete'|'cancel_signature'|'retry_signature'  $ability
     * @param  string  $denial  What a member who lacks the right is told: it names the action that was refused.
     * @param  InventoryVersion|null  $version  The version the action is about, when there is one.
     */
    private function allow(User $user, Inventory $inventory, string $ability, string $denial, ?InventoryVersion $version = null): Response
    {
        $role = $this->workspaces->roleIn($user, $inventory->workspace_id);

        if ($role === null || ($version !== null && $version->inventory_id !== $inventory->getKey())) {
            return Response::denyAsNotFound();
        }

        return self::abilities($role)[$ability] ? Response::allow() : Response::deny($denial);
    }
}
