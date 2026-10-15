<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\SwitchWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use App\Workspaces\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Chooses the workspace the signed-in user works in.
 */
final class CurrentWorkspaceController extends Controller
{
    /**
     * Makes one of the user's workspaces the current one, and describes it. A workspace the user does not belong
     * to is refused as if it did not exist (`422`; `404` should the membership end between the validation and here:
     * the policy is asked before anything is written).
     */
    public function __invoke(SwitchWorkspaceRequest $request, Workspaces $workspaces): JsonResponse
    {
        $user = $this->user($request);
        $workspace = Workspace::query()->where('ulid', $request->ulid())->firstOrFail();

        Gate::authorize('view', $workspace);

        $workspaces->makeCurrent($user, $workspace);

        $membership = $workspaces->membership($user, $workspace);
        abort_if($membership === null, 404);

        return (new WorkspaceResource($membership))->response();
    }
}
