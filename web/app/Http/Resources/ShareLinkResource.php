<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Inventories\Sharing\ShareLinks;
use App\Models\ShareLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How the API describes a share link to the owner of its inventory: what it is called, the address to give out and when it was made, last
 * opened and expires.
 *
 * ```
 * {"id", "label", "url", "include_files", "expires_at", "last_used_at", "created_at", "created_by": {"id", "name", "is_owner"}}
 * ```
 *
 * `url` is the absolute address of the link, built from `APP_URL`; null when it cannot be read back (the application key was changed since
 * the link was made), in which case the owner makes a new link. `expires_at` is null for a link that never expires and `last_used_at` for
 * one nobody opened (it is moved at most once an hour). `created_by` is `{id, name, is_owner}` (`id` is the `ulid` of the account, as a
 * member's `id` is; `is_owner` whether that account is still an owner of the workspace) or null once the account was deleted. A link
 * outlives the ownership of its author: it works until it expires or is revoked, and the owners are told when its author is not one of them. The token itself is the last part of `url` and nowhere else; the hash that is stored is
 * never given.
 *
 * @property ShareLink $resource With its `creator` loaded.
 */
final class ShareLinkResource extends JsonResource
{
    /**
     * Remembers the link and its address.
     *
     * @param  ShareLink  $resource
     * @param  string|null  $url  The address of the link, null when it cannot be read back.
     * @param  bool  $creatorIsOwner  Whether the account that made the link is an owner of the workspace now ({@see ShareLinks::ownerIds()}).
     */
    public function __construct($resource, private readonly ?string $url, private readonly bool $creatorIsOwner)
    {
        parent::__construct($resource);
    }

    /**
     * The link as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $link = $this->resource;
        $creator = $link->creator;

        return [
            'id' => $link->ulid,
            'label' => $link->label,
            'url' => $this->url,
            'include_files' => $link->include_files,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'last_used_at' => $link->last_used_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
            'created_by' => $creator === null ? null : ['id' => $creator->ulid, 'name' => $creator->name, 'is_owner' => $this->creatorIsOwner],
        ];
    }
}
