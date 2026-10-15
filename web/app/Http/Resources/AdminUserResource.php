<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How the API describes an account to an administrator: who it is, whether it administers the instance, since when it
 * exists and how many workspaces it belongs to (the resource must come with `workspaces_count`, see `withCount`).
 *
 * The numeric id is the identifier of the administration endpoints (it is shown to administrators only). `is_self`
 * tells the page which row is the administrator who is looking, whose own account it does not offer to delete.
 *
 * @mixin User
 */
final class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $count = $this->resource->getAttribute('workspaces_count');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale,
            'is_admin' => $this->is_admin,
            'is_self' => $request->user()?->is($this->resource) ?? false,
            'workspaces_count' => is_numeric($count) ? (int) $count : 0,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
