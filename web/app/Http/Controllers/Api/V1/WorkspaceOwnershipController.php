<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\TransferOwnershipRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use App\Workspaces\MemberIds;
use App\Workspaces\Members;
use App\Workspaces\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Hands the ownership of a workspace over to another member.
 */
final class WorkspaceOwnershipController extends Controller
{
    public function __construct(private readonly Members $members, private readonly Workspaces $workspaces, private readonly MemberIds $ids) {}

    /**
     * Makes a member the owner of the workspace, and the person who asks (the request checks that they are the owner) an editor; both
     * at once. The answer is the workspace as the person sees it now, with their new role.
     *
     * `422` when the identifier is not one of a member of the workspace (the way a workspace the person does not belong to is refused
     * by `me.current-workspace`: a thing named in the body that is not there is a mistake in the body); `409` (`already_owner`) when
     * the member is the owner already.
     *
     * @throws ValidationException
     */
    public function __invoke(TransferOwnershipRequest $request, Workspace $workspace): JsonResponse
    {
        $user = $this->user($request);
        $target = $this->ids->find($workspace, $request->memberId())
            ?? throw ValidationException::withMessages(['user_id' => [__('This person is not a member of the workspace.')]]);

        $this->members->transferOwnership($user, $workspace, $target);

        $membership = $this->workspaces->membership($user, $workspace);
        abort_if($membership === null, 404);

        return (new WorkspaceResource($membership))->response();
    }
}
