<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Workspaces\MemberEntry;
use App\Workspaces\MemberIds;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How the API describes a member of a workspace to the person who asks: who they are, the role they hold and since when.
 *
 * ```
 * {"id", "name", "email", "role", "joined_at", "is_self"}
 * ```
 *
 * - `id` names the member in this workspace only ({@see MemberIds}); it is not the number of their account, which other people never see;
 * - `email` is the address of the member for the owner of the workspace and for the member themselves, and `null` for anybody else
 *   (the key is always there);
 * - `is_self` is true on the line of the person who asks.
 *
 * @property MemberEntry $resource
 */
final class MemberResource extends JsonResource
{
    /**
     * Remembers the line and the identifier the API gives the member.
     *
     * @param  MemberEntry  $resource
     * @param  string  $memberId  See {@see MemberIds::of()}.
     */
    public function __construct($resource, private readonly string $memberId)
    {
        parent::__construct($resource);
    }

    /**
     * The member as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        return [
            'id' => $this->memberId,
            'name' => $entry->name,
            'email' => $entry->email,
            'role' => $entry->role->value,
            'joined_at' => $entry->joinedAt?->toIso8601String(),
            'is_self' => $entry->self,
        ];
    }
}
