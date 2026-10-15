<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\WorkspaceNameRequest;
use App\Http\Resources\WorkspaceResource;
use App\Http\Responses\ApiError;
use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use App\Workspaces\Exceptions\LastWorkspaceException;
use App\Workspaces\Membership;
use App\Workspaces\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The workspaces of the signed-in user: list them, create one, rename or delete one they own.
 *
 * What the user may do with a workspace is decided by {@see WorkspacePolicy}: a workspace they do
 * not belong to is a 404, one they belong to without the right a 403.
 */
final class WorkspaceController extends Controller
{
    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * Every workspace the user belongs to, with their role in each and which one is current.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return WorkspaceResource::collection($this->workspaces->memberships($this->user($request)));
    }

    /**
     * Creates a workspace owned by the user, who starts working in it (`201`).
     */
    public function store(WorkspaceNameRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $workspace = $this->workspaces->create($user, $request->name());

        return $this->respond($this->workspaces->membership($user, $workspace))->setStatusCode(201);
    }

    /**
     * Renames a workspace (the request checks that the user owns it).
     */
    public function update(WorkspaceNameRequest $request, Workspace $workspace): JsonResponse
    {
        $user = $this->user($request);
        $this->workspaces->rename($workspace, $request->name());

        return $this->respond($this->workspaces->membership($user, $workspace));
    }

    /**
     * Deletes a workspace of which the user is an owner, and everything in it. The user's last workspace cannot be deleted
     * (`409`, `last_workspace`); when it was the one they were working in, another takes its place.
     */
    public function destroy(Request $request, Workspace $workspace): JsonResponse|Response
    {
        Gate::authorize('delete', $workspace);

        try {
            $this->workspaces->delete($this->user($request), $workspace);
        } catch (LastWorkspaceException) {
            return ApiError::conflict(__('You cannot delete your last workspace: create another one first.'), LastWorkspaceException::CODE);
        }

        return response()->noContent();
    }

    /**
     * The answer that describes one workspace.
     */
    private function respond(?Membership $membership): JsonResponse
    {
        abort_if($membership === null, 404);

        return (new WorkspaceResource($membership))->response();
    }
}
