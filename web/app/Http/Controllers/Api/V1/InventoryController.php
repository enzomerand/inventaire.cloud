<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventories\SaveInventoryRequest;
use App\Http\Resources\InventoryResource;
use App\Http\Responses\ApiError;
use App\Inventories\Exceptions\InventoryLimitReachedException;
use App\Inventories\Inventories;
use App\Models\Inventory;
use App\Models\Workspace;
use App\Policies\InventoryPolicy;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The inventories of a workspace: list them, create one, and read, rename, describe or delete one.
 *
 * What the user may do is decided by {@see InventoryPolicy}: an inventory of a workspace they do not belong to is a
 * 404, one they may not change a 403. A workspace holds at most `inventories.max_per_workspace` inventories
 * (`409`, `inventory_limit_reached`).
 */
final class InventoryController extends Controller
{
    /**
     * Gets the rules of inventories and of workspaces.
     */
    public function __construct(
        private readonly Inventories $inventories,
        private readonly Workspaces $workspaces,
    ) {}

    /**
     * Every inventory of the workspace, by name, with its number of fields and items and what the user may do with it.
     */
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('view', $workspace);

        $role = $this->roleIn($request, $workspace->getKey());

        return InventoryResource::collection(
            $this->inventories->list($workspace)->map(fn (Inventory $inventory): InventoryResource => new InventoryResource($inventory, $role, $workspace->ulid)),
        );
    }

    /**
     * Creates an inventory in the workspace (`201`): editors and owners.
     */
    public function store(SaveInventoryRequest $request, Workspace $workspace): JsonResponse
    {
        try {
            $inventory = $this->inventories->create($workspace, $request->changes()['name'] ?? '', $request->changes()['description'] ?? null);
        } catch (InventoryLimitReachedException $limit) {
            return ApiError::conflict(
                trans_choice('A workspace cannot hold more than :count inventory.|A workspace cannot hold more than :count inventories.', $limit->limit),
                InventoryLimitReachedException::CODE,
                ['limit' => $limit->limit],
            );
        }

        $inventory->loadCount(['fields', 'items']);

        return (new InventoryResource($inventory, $this->roleIn($request, $workspace->getKey()), $workspace->ulid))->response()->setStatusCode(201);
    }

    /**
     * One inventory, with its fields in order and what the user may do with it.
     */
    public function show(Request $request, Inventory $inventory): InventoryResource
    {
        Gate::authorize('view', $inventory);

        return $this->describe($request, $inventory);
    }

    /**
     * Changes the name or the description of an inventory: editors and owners.
     */
    public function update(SaveInventoryRequest $request, Inventory $inventory): InventoryResource
    {
        $updated = $this->inventories->update($inventory, $request->changes());

        return $this->describe($request, $updated);
    }

    /**
     * Deletes an inventory and everything in it (`204`): owners only.
     */
    public function destroy(Inventory $inventory): Response
    {
        Gate::authorize('delete', $inventory);

        $this->inventories->delete($inventory);

        return response()->noContent();
    }

    /**
     * The answer that describes one inventory: its fields, its counts and the abilities of the user.
     */
    private function describe(Request $request, Inventory $inventory): InventoryResource
    {
        $inventory->load(['workspace', 'fields'])->loadCount(['fields', 'items']);

        return new InventoryResource($inventory, $this->roleIn($request, $inventory->workspace_id));
    }

    /**
     * The role of the user in a workspace they were just let into by the policy.
     */
    private function roleIn(Request $request, int $workspaceId): WorkspaceRole
    {
        return $this->workspaces->roleIn($this->user($request), $workspaceId) ?? WorkspaceRole::Viewer;
    }
}
