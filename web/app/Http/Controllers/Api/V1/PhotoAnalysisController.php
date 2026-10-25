<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventories\StorePhotoAnalysisRequest;
use App\Http\Resources\PhotoAnalysisResource;
use App\Http\Responses\ApiError;
use App\Http\Responses\TooManyRequests;
use App\Models\Inventory;
use App\Models\PhotoAnalysis;
use App\PhotoEntry\Exceptions\AnalysisNotStartedException;
use App\PhotoEntry\Exceptions\InstanceQuotaReachedException;
use App\PhotoEntry\Exceptions\NoFieldsToFillException;
use App\PhotoEntry\Exceptions\PhotoEntryDisabledException;
use App\PhotoEntry\PhotoAnalyses;
use App\PhotoEntry\PhotoAnalysisStatus;
use App\PhotoEntry\UnreadablePhotoException;
use App\Policies\InventoryPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The photo analyses of an inventory: send the photos of an object (`202`: a job asks Claude to read them), then read the analysis until
 * it ends (`pending`, `running`, then `completed` with the proposals or `failed`).
 *
 * Adding items is what an analysis is for, so the roles that may are the ones that may (editors and owners: see {@see InventoryPolicy}; a
 * viewer is refused, a stranger told the inventory is not there). An analysis is its author's alone: another person's, even in the same
 * workspace, and one whose time is up, are a `404` like one that does not exist. These are session routes: the web front only, never an
 * API token (the photos leave for a third party and every analysis is paid for by the instance).
 */
final class PhotoAnalysisController extends Controller
{
    /**
     * Gets the service that receives the photos and starts the analysis.
     */
    public function __construct(private readonly PhotoAnalyses $analyses) {}

    /**
     * Sends the photos (`202`, with the analysis `pending`).
     *
     * `422` for a mistake in the request (`photos`, `photos.<n>`) and for a photo that cannot be read (on `photos.<n>`, with a `code`:
     * `unsupported_type`, `unreadable_photo`, `photo_too_large`); `409` when the workspace may not use the photo entry
     * (`photo_entry_disabled`: the administrator turned it off, there is no key, or the owner did not turn it on) and when no field of
     * the inventory can be filled from a photo (`no_fields_to_fill`); `503` (`photo_analysis_unavailable`) when the photos could not be written or the job queued; `429` beyond the pace of requests, beyond the analyses of the day of the
     * person and beyond those of the instance (the latter with its own message and `code`: `photo_analyses_instance_limit`).
     */
    public function store(StorePhotoAnalysisRequest $request, Inventory $inventory): JsonResponse
    {
        try {
            $analysis = $this->analyses->request($inventory, $this->user($request), $request->photos(), app()->getLocale());
        } catch (PhotoEntryDisabledException) {
            return ApiError::conflict(__('The photo entry is not turned on for this workspace.'), PhotoEntryDisabledException::CODE);
        } catch (NoFieldsToFillException) {
            return ApiError::conflict(__('This inventory has no field that photos can fill.'), NoFieldsToFillException::CODE);
        } catch (InstanceQuotaReachedException $reached) {
            return TooManyRequests::response(__('The instance reached its daily number of photo analyses; try again tomorrow.'), InstanceQuotaReachedException::CODE, $reached->limit, $reached->retryAfter);
        } catch (AnalysisNotStartedException) {
            return ApiError::unavailable(__('The photos could not be sent for analysis right now. Try again in a moment.'), AnalysisNotStartedException::CODE);
        } catch (UnreadablePhotoException $refused) {
            return ApiError::invalid($refused->getMessage(), 'photos.'.($refused->photo ?? 0), $refused->reason);
        }

        return (new PhotoAnalysisResource($analysis))->response()->setStatusCode(202);
    }

    /**
     * One analysis of the signed-in person: where it is up to, and the proposals once it is done. The proposals for a field that was
     * deleted since are left out.
     */
    public function show(Request $request, Inventory $inventory, PhotoAnalysis $photoAnalysis): PhotoAnalysisResource
    {
        Gate::authorize('editItems', $inventory);

        if ($photoAnalysis->user_id !== $this->user($request)->getKey() || $photoAnalysis->isExpired()) {
            throw (new ModelNotFoundException)->setModel(PhotoAnalysis::class, [$photoAnalysis->ulid]);
        }

        // The fields are read only to leave out the proposals for one that was deleted: an analysis that is not over has none, and it is
        // read every two seconds until it is.
        $fieldIds = $photoAnalysis->status === PhotoAnalysisStatus::Completed
            ? array_map(strval(...), array_values($inventory->fields()->pluck('ulid')->all()))
            : null;

        return new PhotoAnalysisResource($photoAnalysis, $fieldIds);
    }
}
