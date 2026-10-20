<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WorkspaceInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How the API describes an invitation to the owner of the workspace who sent it: who it is for, the role it gives and when it lapses.
 *
 * ```
 * {"id", "email", "role", "invited_by", "expires_at", "created_at"}
 * ```
 *
 * `invited_by` is the person who sent it last, as `{id, name}` (`id` is the `ulid` of their account, as a member's `id` is), or `null` once
 * their account was deleted.
 *
 * @property WorkspaceInvitation $resource With its `inviter` loaded.
 */
final class SentInvitationResource extends JsonResource
{
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
            'invited_by' => $inviter === null ? null : ['id' => $inviter->ulid, 'name' => $inviter->name],
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }
}
