<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\ChangeMemberRoleRequest;
use App\Http\Resources\MemberResource;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Exceptions\MembershipRefused;
use App\Workspaces\Members;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The members of a workspace: who they are, and for its owner, their roles and who stays.
 *
 * What may be done is decided by {@see Members}, whose refusals are {@see MembershipRefused}. A member is named in the path by the
 * `ulid` of their account, never by its number (`{member:ulid}`, bound among the members of the workspace: see the routes); one that
 * is not (or no longer) a member of the workspace is a `404`.
 */
final class WorkspaceMemberController extends Controller
{
    public function __construct(private readonly Members $members) {}

    /**
     * The members of the workspace, the owner first, then in the order they joined. Any member may ask; the address of a member is
     * given to the owner and to that member only.
     */
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        return MemberResource::collection($this->members->list($this->user($request), $workspace));
    }

    /**
     * Changes the role of a member to editor or viewer (the request checks that the person is the owner). The owner's role cannot be
     * changed (`409`, `cannot_change_owner_role`).
     */
    public function update(ChangeMemberRoleRequest $request, Workspace $workspace, User $member): MemberResource
    {
        return new MemberResource($this->members->changeRole($this->user($request), $workspace, $member, $request->role()));
    }

    /**
     * Removes a member from the workspace (`204`): they lose access at once. The owner removes any member but themselves, who is
     * the one who cannot be removed (they hand the ownership over first); a member removes themselves, which is leaving the workspace
     * (the owner cannot: `409`, `owner_cannot_leave`).
     */
    public function destroy(Request $request, Workspace $workspace, User $member): Response
    {
        $this->members->remove($this->user($request), $workspace, $member);

        return response()->noContent();
    }
}
