<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WorkspaceInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How the API describes an invitation to the person it is addressed to: which workspace, the role it gives, who sent it and when it lapses.
 *
 * ```
 * {"id", "workspace": {"id", "name"}, "role", "inviter", "expires_at"}
 * ```
 *
 * `inviter` is `{name}` (no identifier: the person is not a member of the workspace yet, and has no business with the accounts of a
 * workspace they are not in), or `null` once the account of the sender was deleted.
 *
 * @mixin WorkspaceInvitation
 */
final class ReceivedInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $inviter = $this->inviter;

        return [
            'id' => $this->ulid,
            'workspace' => ['id' => $this->workspace->ulid, 'name' => $this->workspace->name],
            'role' => $this->role->value,
            'inviter' => $inviter === null ? null : ['name' => $inviter->name],
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
