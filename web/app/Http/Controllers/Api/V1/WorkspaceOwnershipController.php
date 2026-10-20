<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\TransferOwnershipRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Members;
use App\Workspaces\Workspaces;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * Hands the ownership of a workspace over to another member. A person at their browser does it, never a script: the route refuses an API
 * token (see the routes), as the ones of the account and its tokens do.
 */
final class WorkspaceOwnershipController extends Controller
{
    public function __construct(private readonly Members $members, private readonly Workspaces $workspaces) {}

    /**
     * Makes a member the owner of the workspace, and the person who asks (the request checks that they are the owner) an editor; both
     * at once. The answer is the workspace as the person sees it now, with their new role.
     *
     * `404` when `member_id` is not the `id` of a member of the workspace, as for a member named in a path; `409` (`already_owner`) when
     * the member is the owner already.
     *
     * @throws ModelNotFoundException
     */
    public function __invoke(TransferOwnershipRequest $request, Workspace $workspace): JsonResponse
    {
        $user = $this->user($request);
        $target = $this->members->find($workspace, $request->memberId()) ?? throw (new ModelNotFoundException)->setModel(User::class);

        $this->members->transferOwnership($user, $workspace, $target);

        $membership = $this->workspaces->membership($user, $workspace);
        abort_if($membership === null, 404);

        return (new WorkspaceResource($membership))->response();
    }
}
