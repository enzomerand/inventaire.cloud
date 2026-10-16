<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventories\StoreExportRequest;
use App\Http\Resources\ExportResource;
use App\Http\Responses\ApiError;
use App\Http\Responses\StoredFileResponse;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Exceptions\ExportLimitReachedException;
use App\Inventories\Exceptions\ExportRefusedException;
use App\Inventories\Exceptions\ExportStorageFullException;
use App\Inventories\Exports\Exports;
use App\Inventories\Exports\ExportStatus;
use App\Models\Export;
use App\Models\Inventory;
use App\Policies\InventoryPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The exports of an inventory: ask for one (`202`: a job writes the file), list the ones a person has, read one, download its
 * file, delete it.
 *
 * Every member of the workspace may export (see {@see InventoryPolicy}: viewers too), and an export is its author's alone: another
 * person's export, even in the same workspace, is a `404` like one that does not exist. The file is kept for
 * `inventories.export_ttl_hours` and goes with the export.
 */
final class ExportController extends Controller
{
    /** Exports the list gives. */
    private const LIST_LIMIT = 20;

    /**
     * Gets the service that asks for exports, and the storage the files are read from.
     */
    public function __construct(
        private readonly Exports $exports,
        private readonly AttachmentStorage $storage,
    ) {}

    /**
     * The exports of the signed-in person for this inventory that did not expire, the newest first, twenty at most.
     */
    public function index(Request $request, Inventory $inventory): AnonymousResourceCollection
    {
        Gate::authorize('view', $inventory);

        $exports = $inventory->exports()
            ->where('user_id', $this->user($request)->getKey())
            ->active()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        return ExportResource::collection($exports->map(fn (Export $export): ExportResource => new ExportResource($export, $inventory->ulid)));
    }

    /**
     * Asks for an export (`202`, with the export `pending`: the file is written in the background).
     *
     * `422` for a mistake in the request (`format`, `delimiter`, `files`, `query.*`), for a PDF of more items than a PDF may hold
     * and for a zip with no file to put in it (both on `format`, with a `code`); `409` when the person already has as many
     * exports of this inventory as they may (`export_limit_reached`) or when their exports, this one included, would take more
     * room than they may (`export_storage_full`), both with the `limit`; `429` beyond the pace of requests.
     */
    public function store(StoreExportRequest $request, Inventory $inventory): JsonResponse
    {
        try {
            $export = $this->exports->request($inventory, $this->user($request), $request->exportFormat(), $request->exportScope(), $request->options());
        } catch (ExportLimitReachedException $limit) {
            return ApiError::conflict(
                trans_choice('You can have :count export of this inventory at a time: delete one, or wait for one to expire.|You can have :count exports of this inventory at a time: delete one, or wait for one to expire.', $limit->limit),
                ExportLimitReachedException::CODE,
                ['limit' => $limit->limit],
            );
        } catch (ExportStorageFullException $full) {
            return ApiError::conflict(
                __('Your exports take too much room: together, they cannot weigh more than :size. Delete some, or wait for them to expire.', ['size' => Number::fileSize($full->limit, maxPrecision: 1)]),
                ExportStorageFullException::CODE,
                ['limit' => $full->limit],
            );
        } catch (ExportRefusedException $refused) {
            return $refused->reason === ExportRefusedException::PDF_TOO_LARGE
                ? ApiError::invalid(
                    trans_choice('A PDF of this inventory cannot hold more than :count item: choose another format, or a view with fewer items.|A PDF of this inventory cannot hold more than :count items: choose another format, or a view with fewer items.', (int) $refused->limit),
                    'format',
                    $refused->reason,
                    ['limit' => $refused->limit],
                )
                : ApiError::invalid(__('None of these items has a file to put in the zip.'), 'format', $refused->reason);
        }

        return (new ExportResource($export, $inventory->ulid))->response()->setStatusCode(202);
    }

    /**
     * One export of the signed-in person.
     */
    public function show(Request $request, Inventory $inventory, Export $export): ExportResource
    {
        return new ExportResource($this->owned($request, $inventory, $export), $inventory->ulid);
    }

    /**
     * The file of a completed export, as a download. `409` (`export_not_ready`) while it is being written or if it failed, `404`
     * (`export_expired`) once its time is up and it is not yet cleared away.
     */
    public function download(Request $request, Inventory $inventory, Export $export): StreamedResponse|JsonResponse
    {
        $export = $this->owned($request, $inventory, $export);

        if ($export->status !== ExportStatus::Completed) {
            return ApiError::conflict(__('This export is not ready yet.'), 'export_not_ready');
        }

        if (! $export->isDownloadable()) {
            return ApiError::notFound(__('This export has expired.'), 'export_expired');
        }

        return StoredFileResponse::export($export, $this->storage);
    }

    /**
     * Deletes an export and, once that is committed, its file (`204`). One that is being written is deleted all the same; the
     * worker finds the row gone when it is done and deletes the file it wrote.
     */
    public function destroy(Request $request, Inventory $inventory, Export $export): Response
    {
        $this->owned($request, $inventory, $export)->delete();

        return response()->noContent();
    }

    /**
     * The export, if the signed-in person may read it: a member of the workspace of the inventory who asked for it. Anything else is
     * a `404`, so that nobody learns which exports exist.
     */
    private function owned(Request $request, Inventory $inventory, Export $export): Export
    {
        Gate::authorize('view', $inventory);

        if ($export->user_id !== $this->user($request)->getKey()) {
            throw (new ModelNotFoundException)->setModel(Export::class, [$export->ulid]);
        }

        return $export;
    }
}
