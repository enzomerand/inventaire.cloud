<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\InviteMemberRequest;
use App\Http\Resources\SentInvitationResource;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Workspaces\Exceptions\MembershipRefused;
use App\Workspaces\Invitations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The invitations a workspace has sent that wait for an answer: for its owner, who invites, sends again and withdraws them.
 *
 * What may be done is decided by {@see Invitations}: somebody who does not belong to the workspace is answered as if it was not there
 * (`404`), a member who is not its owner is refused with the code `not_owner` (`403`, see {@see MembershipRefused}). An invitation that
 * is not one of this workspace, has lapsed or was withdrawn is a `404`.
 */
final class WorkspaceInvitationController extends Controller
{
    public function __construct(private readonly Invitations $invitations) {}

    /**
     * The invitations of the workspace that wait for an answer, the newest first.
     */
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $invitations = $this->invitations->sent($this->user($request), $workspace)->load('inviter');

        return SentInvitationResource::collection($invitations);
    }

    /**
     * Invites an address to the workspace as an editor or a viewer, and mails it after the answer (`201`). Throttled per person
     * (`workspaces.invitations_per_hour`, sends and resends together) and per address
     * (`workspaces.invitations_per_address_per_day`, from everybody together).
     */
    public function store(InviteMemberRequest $request, Workspace $workspace): JsonResponse
    {
        $invitation = $this->invitations->invite($this->user($request), $workspace, $request->email(), $request->role());

        return (new SentInvitationResource($invitation->load('inviter')))->response()->setStatusCode(201);
    }

    /**
     * Sends an invitation again: the mail goes out once more and the invitation is good for another term. Throttled like
     * {@see self::store()}, and refused for ten minutes after a send (`429`, `resend_too_soon`, with `Retry-After`).
     */
    public function resend(Request $request, Workspace $workspace, WorkspaceInvitation $invitation): JsonResponse
    {
        $sent = $this->invitations->resend($this->user($request), $workspace, $invitation);

        return (new SentInvitationResource($sent->load('inviter')))->response();
    }

    /**
     * Withdraws an invitation (`204`): it can no longer be accepted.
     */
    public function destroy(Request $request, Workspace $workspace, WorkspaceInvitation $invitation): Response
    {
        $this->invitations->cancel($this->user($request), $workspace, $invitation);

        return response()->noContent();
    }
}
