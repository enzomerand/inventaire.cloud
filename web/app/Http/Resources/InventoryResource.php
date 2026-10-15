<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Inventory;
use App\Policies\InventoryPolicy;
use App\Workspaces\WorkspaceRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How the API describes an inventory to a member of its workspace: the ULID that identifies it, its name and
 * description, the number of its fields and items when they were counted (`withCount`), its fields in order when they
 * were loaded, and what the member may do with it, so the interface offers only that.
 *
 * Never the numeric keys: the workspace is named by its ULID.
 *
 * @mixin Inventory
 */
final class InventoryResource extends JsonResource
{
    /**
     * @param  Inventory  $resource
     * @param  WorkspaceRole  $role  The role of the person who asks in the workspace of the inventory.
     * @param  string|null  $workspaceUlid  The ULID of the workspace, when the caller has it at hand.
     */
    public function __construct($resource, private readonly WorkspaceRole $role, private readonly ?string $workspaceUlid = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $abilities = InventoryPolicy::abilities($this->role);

        return [
            'id' => $this->ulid,
            'workspace_id' => $this->workspaceUlid ?? $this->whenLoaded('workspace', fn (): string => $this->resource->workspace->ulid),
            'name' => $this->name,
            'description' => $this->description,
            'fields_count' => $this->whenCounted('fields'),
            'items_count' => $this->whenCounted('items'),
            'fields' => InventoryFieldResource::collection($this->whenLoaded('fields')),
            'abilities' => [
                'update' => $abilities['update'],
                'delete' => $abilities['delete'],
                'manage_fields' => $abilities['manage_fields'],
                'edit_items' => $abilities['edit_items'],
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
