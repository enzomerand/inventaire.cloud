<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WorkspaceInvitation;
use App\Workspaces\MemberIds;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How the API describes an invitation to the owner of the workspace who sent it: who it is for, the role it gives and when it lapses.
 *
 * ```
 * {"id", "email", "role", "invited_by", "expires_at", "created_at"}
 * ```
 *
 * `invited_by` is the member who sent it last, as `{id, name}` (`id` as {@see MemberIds} gives it in this workspace), or `null` once their
 * account was deleted.
 *
 * @property WorkspaceInvitation $resource With its `inviter` loaded.
 */
final class SentInvitationResource extends JsonResource
{
    /**
     * Remembers the invitation and the identifier of its sender in the workspace.
     *
     * @param  WorkspaceInvitation  $resource
     * @param  string|null  $inviterId  See {@see MemberIds::of()}; null when the sender's account is gone.
     */
    public function __construct($resource, private readonly ?string $inviterId)
    {
        parent::__construct($resource);
    }

    /**
     * The invitation as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invitation = $this->resource;
        $inviter = $invitation->inviter;

        return [
            'id' => $invitation->ulid,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'invited_by' => $inviter === null || $this->inviterId === null ? null : ['id' => $this->inviterId, 'name' => $inviter->name],
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }
}
