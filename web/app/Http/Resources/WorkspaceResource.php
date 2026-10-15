<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Workspaces\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How the API describes a workspace to one of its members: the ULID that identifies it outside, its name, the role
 * the member holds in it and whether it is the one they are working in.
 *
 * @mixin Membership
 */
final class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->workspace->ulid,
            'name' => $this->workspace->name,
            'role' => $this->role->value,
            'is_current' => $this->current,
            'created_at' => $this->workspace->created_at?->toIso8601String(),
        ];
    }
}
