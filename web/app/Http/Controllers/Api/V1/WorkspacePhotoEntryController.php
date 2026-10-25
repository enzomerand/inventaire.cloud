<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdatePhotoEntryRequest;
use App\Http\Responses\ApiError;
use App\Models\Workspace;
use App\PhotoEntry\Exceptions\PhotoEntryUnavailableException;
use App\PhotoEntry\PhotoEntry;
use Illuminate\Http\JsonResponse;

/**
 * The consent of a workspace to the photo entry: its owner decides whether the editors may add an item from photos that Claude (Anthropic)
 * reads. A person at their browser decides it, never a script: the route refuses an API token (see the routes), and the workspace resource
 * of the token API does not carry it.
 */
final class WorkspacePhotoEntryController extends Controller
{
    /**
     * Gets the service that knows what the instance offers and records the choice.
     */
    public function __construct(private readonly PhotoEntry $photoEntry) {}

    /**
     * Turns the photo entry on or off for the workspace (the request checks that the person owns it) and answers `{data: {enabled}}` as it now
     * is. `422` for an `enabled` that is not a boolean; `409` (`photo_entry_unavailable`) when turning it on while the administrator has it
     * off or the instance has no API key: turning it off always works.
     */
    public function __invoke(UpdatePhotoEntryRequest $request, Workspace $workspace): JsonResponse
    {
        try {
            $this->photoEntry->setConsent($workspace, $request->enabled());
        } catch (PhotoEntryUnavailableException) {
            return ApiError::conflict(__('The photo entry is not available on this instance: ask its administrator.'), PhotoEntryUnavailableException::CODE);
        }

        return new JsonResponse(['data' => ['enabled' => $workspace->photo_entry_enabled]]);
    }
}
