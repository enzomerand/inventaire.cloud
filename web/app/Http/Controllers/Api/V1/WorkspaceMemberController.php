<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\ChangeMemberRoleRequest;
use App\Http\Resources\MemberResource;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Exceptions\MembershipRefused;
use App\Workspaces\MemberEntry;
use App\Workspaces\MemberIds;
use App\Workspaces\Members;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The members of a workspace: who they are, and for its owner, their roles and who stays.
 *
 * What may be done is decided by {@see Members}, which answers as if the workspace was not there to somebody who does not belong to
 * it (`404`), and refuses a member who is not its owner with the code `not_owner` (`403`, see {@see MembershipRefused}). A member is
 * named by the identifier {@see MemberIds} gives them in the workspace, never by the number of their account; one that is not (or no
 * longer) a member of the workspace is a `404`.
 */
final class WorkspaceMemberController extends Controller
{
    public function __construct(private readonly Members $members, private readonly MemberIds $ids) {}

    /**
     * The members of the workspace, the owner first, then in the order they joined. Any member may ask; the address of a member is
     * given to the owner and to that member only.
     */
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $entries = array_map(
            fn (MemberEntry $entry): MemberResource => new MemberResource($entry, $this->ids->of($workspace, $entry->id)),
            $this->members->list($this->user($request), $workspace),
        );

        return MemberResource::collection($entries);
    }

    /**
     * Changes the role of a member to editor or viewer (the request checks that the person is the owner). The owner's role cannot be
     * changed (`409`, `cannot_change_owner_role`).
     */
    public function update(ChangeMemberRoleRequest $request, Workspace $workspace, string $member): MemberResource
    {
        $entry = $this->members->changeRole($this->user($request), $workspace, $this->member($workspace, $member), $request->role());

        return new MemberResource($entry, $this->ids->of($workspace, $entry->id));
    }

    /**
     * Removes a member from the workspace (`204`): they lose access at once. The owner removes any member but themselves (`409`,
     * `cannot_remove_owner`); a member removes themselves, which is leaving the workspace (the owner cannot: `409`, `owner_cannot_leave`).
     */
    public function destroy(Request $request, Workspace $workspace, string $member): Response
    {
        $this->members->remove($this->user($request), $workspace, $this->member($workspace, $member));

        return response()->noContent();
    }

    /**
     * The account a member identifier stands for.
     *
     * @throws ModelNotFoundException When it stands for nobody who belongs to the workspace.
     */
    private function member(Workspace $workspace, string $member): User
    {
        return $this->ids->find($workspace, $member) ?? throw (new ModelNotFoundException)->setModel(User::class);
    }
}
