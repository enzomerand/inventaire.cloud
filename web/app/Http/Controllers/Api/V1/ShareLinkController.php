<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventories\StoreShareLinkRequest;
use App\Http\Resources\ShareLinkResource;
use App\Http\Responses\ApiError;
use App\Inventories\Exceptions\ShareLinkLimitReachedException;
use App\Inventories\Exceptions\ShareLinksDisabledException;
use App\Inventories\Sharing\ShareLinks;
use App\Models\Inventory;
use App\Models\ShareLink;
use App\Policies\InventoryPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The share links of an inventory: secret read-only addresses that show it to anybody who holds them. List them, make one, revoke one.
 *
 * Owners of the workspace only (see {@see InventoryPolicy::share()}): a member who is not one is refused with a `403`, somebody who does not
 * belong to the workspace is told the inventory is not there. A link of another inventory is a `404` like one that does not exist. The
 * answers that carry addresses, the list and the creation, are never kept by a cache (`Cache-Control: no-store`).
 */
final class ShareLinkController extends Controller
{
    /**
     * Gets the service that makes, lists and revokes the links.
     */
    public function __construct(private readonly ShareLinks $links) {}

    /**
     * The links of the inventory, the newest first, the ones that expired included (their `expires_at` says so), in the `data` envelope.
     * Listing stays possible while the administrator has sharing turned off: the owner sees what is there and can revoke it.
     */
    public function index(Inventory $inventory): JsonResponse
    {
        Gate::authorize('share', $inventory);

        // Mapped here, one resource at a time: `collection()` would not give each one its address.
        return ShareLinkResource::collection($this->links->list($inventory)->map(
            fn (ShareLink $link): ShareLinkResource => new ShareLinkResource($link, $this->links->urlOf($link)),
        ))->response()->header('Cache-Control', 'no-store, private');
    }

    /**
     * Makes a link (`201`). Its address is in this answer and, until the application key changes, in the list.
     *
     * `422` for a mistake in the request (`label`, `include_files`, `expires_in_days`); `409` when the inventory already has as many links as it
     * may (`share_link_limit_reached`, with the `limit`) and when the administrator turned sharing off (`share_links_disabled`); `429`
     * beyond the pace of creations.
     */
    public function store(StoreShareLinkRequest $request, Inventory $inventory): JsonResponse
    {
        try {
            ['link' => $link, 'token' => $token] = $this->links->create(
                $this->user($request),
                $inventory,
                $request->label(),
                $request->includeFiles(),
                $request->expiresInDays(),
            );
        } catch (ShareLinksDisabledException) {
            return ApiError::conflict(__('Sharing by link is turned off on this instance.'), ShareLinksDisabledException::CODE);
        } catch (ShareLinkLimitReachedException $limit) {
            return ApiError::conflict(
                trans_choice('An inventory can have :count share link at a time: delete one to make another.|An inventory can have :count share links at a time: delete one to make another.', $limit->limit),
                ShareLinkLimitReachedException::CODE,
                ['limit' => $limit->limit],
            );
        }

        return (new ShareLinkResource($link, ShareLinks::addressOf($token)))->response()
            ->setStatusCode(201)
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Revokes a link (`204`): its address stops working at once.
     */
    public function destroy(Inventory $inventory, ShareLink $shareLink): Response
    {
        Gate::authorize('share', $inventory);

        abort_unless($this->links->revoke($inventory, $shareLink), 404);

        return response()->noContent();
    }
}
