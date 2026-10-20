<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReceivedInvitationResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\WorkspaceInvitation;
use App\Workspaces\Invitations;
use App\Workspaces\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The invitations addressed to the signed-in person's address, and what they do with them: accept or decline.
 *
 * An invitation is the address's alone: one that is addressed to another address, has lapsed or was withdrawn is a `404`, like one
 * that does not exist.
 */
final class MyInvitationController extends Controller
{
    public function __construct(private readonly Invitations $invitations, private readonly Workspaces $workspaces) {}

    /**
     * The invitations that wait for an answer, the newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ReceivedInvitationResource::collection($this->invitations->pendingFor($this->user($request)));
    }

    /**
     * Accepts an invitation: the person joins the workspace with the invited role, and the invitation is spent. The answer is the
     * workspace as they see it now. It does not become the one they work in: that is their choice, made at their browser. Accepting
     * again an invitation to a workspace they belong to already changes nothing (`409` for a workspace that is full, `member_limit_reached`,
     * or for a person who belongs to as many workspaces as they may, `membership_limit_reached`).
     */
    public function accept(Request $request, WorkspaceInvitation $invitation): JsonResponse
    {
        $user = $this->user($request);
        $workspace = $this->invitations->accept($user, $invitation);

        $membership = $this->workspaces->membership($user, $workspace);
        abort_if($membership === null, 404);

        return (new WorkspaceResource($membership))->response();
    }

    /**
     * Declines an invitation (`204`): it is deleted.
     */
    public function destroy(Request $request, WorkspaceInvitation $invitation): Response
    {
        $this->invitations->decline($this->user($request), $invitation);

        return response()->noContent();
    }
}
