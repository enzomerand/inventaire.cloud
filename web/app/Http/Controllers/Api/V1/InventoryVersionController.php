<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventories\StoreInventoryVersionRequest;
use App\Http\Resources\InventoryVersionResource;
use App\Http\Responses\ApiError;
use App\Http\Responses\StoredFileResponse;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Exceptions\DocumensoUnavailableException;
use App\Inventories\Exceptions\ExportRefusedException;
use App\Inventories\Exceptions\SignatureNotFailedException;
use App\Inventories\Exceptions\SignatureNotPendingException;
use App\Inventories\Exceptions\SignatureUnavailableException;
use App\Inventories\Exceptions\SnapshotTooLargeException;
use App\Inventories\Exceptions\StorageLimitReachedException;
use App\Inventories\Exceptions\VersionLimitReachedException;
use App\Inventories\Versions\OfficialVersions;
use App\Inventories\Versions\SignatureStatus;
use App\Inventories\Versions\VersionFileName;
use App\Inventories\Versions\VersionStatus;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Policies\InventoryVersionPolicy;
use App\Signatures\SignatureRequests;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The official versions of an inventory: freeze it (`202`: a job writes the PDF), list them, read one, download its PDF, its data
 * (the canonical JSON that was frozen) and its signed PDF, delete one, and cancel or send again its request for signatures.
 *
 * Every member of the workspace may read and download; editors and owners freeze and cancel or send again a request for signatures;
 * owners delete (see {@see InventoryVersionPolicy}; a viewer is refused with a `403`, a stranger told the inventory is not there). Unlike an export, a version belongs to the inventory,
 * not to the person who froze it: everybody who can see the inventory sees every version. A version of another inventory is a `404`
 * like one that does not exist.
 */
final class InventoryVersionController extends Controller
{
    /** Versions per page. */
    public const PER_PAGE = 20;

    /**
     * Gets the service that freezes and deletes the versions, the one that cancels and sends again the requests for signatures, the one
     * that knows the roles, and the storage the files are read from.
     */
    public function __construct(
        private readonly OfficialVersions $versions,
        private readonly SignatureRequests $signatures,
        private readonly Workspaces $workspaces,
        private readonly AttachmentStorage $storage,
    ) {}

    /**
     * A page of the versions of the inventory, the newest first, in the `data` envelope with `meta` and `links`.
     */
    public function index(Request $request, Inventory $inventory): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [InventoryVersion::class, $inventory]);

        $role = $this->roleIn($request, $inventory);
        $page = $inventory->versions()->orderByDesc('number')->orderByDesc('id')->paginate(self::PER_PAGE);

        return InventoryVersionResource::collection($page->through(fn (InventoryVersion $version): InventoryVersionResource => new InventoryVersionResource($version, $inventory->ulid, $role)));
    }

    /**
     * Freezes the inventory as the next version (`202`, with the version `pending`: the PDF is written in the background).
     *
     * `422` for a mistake in the request (`label`, `signers`, `signers.N.name`, `signers.N.email`) and for an inventory with more items
     * than a PDF may hold, or whose data weighs more than a snapshot may (`inventory`, with the code `pdf_too_large`; the `limit` of items
     * only for the first); `409` when the inventory already holds as many
     * versions as it may (`version_limit_reached`, with the `limit`), when the files of the workspace already weigh its quota
     * (`storage_limit_reached`, with the `limit`) and when signatures are asked for on an instance that has no signature service
     * (`signature_unavailable`); `429` beyond the pace of versions.
     */
    public function store(StoreInventoryVersionRequest $request, Inventory $inventory): JsonResponse
    {
        try {
            $version = $this->versions->create($inventory, $this->user($request), $request->label(), $request->signers());
        } catch (VersionLimitReachedException $limit) {
            return ApiError::conflict(
                trans_choice('An inventory cannot hold more than :count official version: delete one to freeze it again.|An inventory cannot hold more than :count official versions: delete one to freeze it again.', $limit->limit),
                VersionLimitReachedException::CODE,
                ['limit' => $limit->limit],
            );
        } catch (StorageLimitReachedException $limit) {
            return ApiError::conflict(
                __('The storage of this workspace is full: its files cannot weigh more than :size in all.', ['size' => Number::fileSize($limit->limit, maxPrecision: 1)]),
                StorageLimitReachedException::CODE,
                ['limit' => $limit->limit],
            );
        } catch (SignatureUnavailableException) {
            return ApiError::conflict(
                __('Signatures cannot be asked for: this instance has no signature service configured.'),
                SignatureUnavailableException::CODE,
            );
        } catch (ExportRefusedException $refused) {
            return ApiError::invalid(
                trans_choice('A version of this inventory cannot hold more than :count item, which is all a PDF can hold.|A version of this inventory cannot hold more than :count items, which is all a PDF can hold.', (int) $refused->limit),
                'inventory',
                $refused->reason,
                ['limit' => $refused->limit],
            );
        } catch (SnapshotTooLargeException $large) {
            return ApiError::invalid(
                __('The data of this inventory is too large to be frozen as an official version: it cannot weigh more than :size.', ['size' => Number::fileSize($large->limit, maxPrecision: 1)]),
                'inventory',
                ExportRefusedException::PDF_TOO_LARGE,
            );
        }

        return $this->describe($request, $inventory, $version)->response()->setStatusCode(202);
    }

    /**
     * One version of the inventory.
     */
    public function show(Request $request, Inventory $inventory, InventoryVersion $version): InventoryVersionResource
    {
        Gate::authorize('view', [$version, $inventory]);

        return $this->describe($request, $inventory, $version);
    }

    /**
     * The PDF of a completed version, as a download. `409` (`version_not_ready`) while it is being written or if it failed.
     */
    public function pdf(Inventory $inventory, InventoryVersion $version): StreamedResponse|JsonResponse
    {
        Gate::authorize('view', [$version, $inventory]);

        if ($version->status !== VersionStatus::Completed || $version->pdf_path === null) {
            return $this->notReady();
        }

        return StoredFileResponse::download(
            $version->disk,
            $version->pdf_path,
            VersionFileName::for($inventory->name, $version->number, 'pdf'),
            'application/pdf',
            (int) $version->pdf_size,
            $this->storage,
        );
    }

    /**
     * The data that was frozen with a completed version (`snapshot.json`), as a download: its SHA-256 is the one the version gives.
     * `409` (`version_not_ready`) while the version is being written or if it failed.
     */
    public function snapshot(Inventory $inventory, InventoryVersion $version): StreamedResponse|JsonResponse
    {
        Gate::authorize('view', [$version, $inventory]);

        if ($version->status !== VersionStatus::Completed) {
            return $this->notReady();
        }

        return StoredFileResponse::download(
            $version->disk,
            $version->snapshot_path,
            VersionFileName::for($inventory->name, $version->number, 'json'),
            'application/json',
            $version->snapshot_size,
            $this->storage,
        );
    }

    /**
     * The PDF with the signatures, once every signer has signed, as a download. `409` (`signed_pdf_not_ready`) until then (and for a
     * version nobody was asked to sign).
     */
    public function signedPdf(Inventory $inventory, InventoryVersion $version): StreamedResponse|JsonResponse
    {
        Gate::authorize('view', [$version, $inventory]);

        if ($version->signature_status !== SignatureStatus::Completed || $version->signed_pdf_path === null) {
            return ApiError::conflict(__('The signed PDF is not ready yet.'), 'signed_pdf_not_ready');
        }

        return StoredFileResponse::download(
            $version->disk,
            $version->signed_pdf_path,
            VersionFileName::for($inventory->name, $version->number, 'pdf', signed: true),
            'application/pdf',
            (int) $version->signed_pdf_size,
            $this->storage,
        );
    }

    /**
     * Cancels the request for signatures of a version while it awaits its signers (`200`, with the version): Documenso is asked at once
     * and tells the signers. `409` (`signature_not_pending`) when the request is not waiting for them, or Documenso says it is not
     * pending any more (the request is then read again in the background); `503` (`documenso_unavailable`) when Documenso cannot be
     * reached.
     */
    public function cancelSignature(Request $request, Inventory $inventory, InventoryVersion $version): InventoryVersionResource|JsonResponse
    {
        Gate::authorize('cancelSignature', [$version, $inventory]);

        try {
            $cancelled = $this->signatures->cancel($version);
        } catch (SignatureNotPendingException) {
            return ApiError::conflict(__('This signature request is not waiting for its signers: it cannot be cancelled.'), SignatureNotPendingException::CODE);
        } catch (DocumensoUnavailableException) {
            return ApiError::unavailable(__('The signature service cannot be used right now. Try again in a moment.'), DocumensoUnavailableException::CODE);
        }

        return $this->describe($request, $inventory, $cancelled);
    }

    /**
     * Sends again the request for signatures of a completed version that failed (`202`, with the version, `requested`): a job takes it
     * from where it stopped. `409` (`signature_not_failed`) when the request did not fail, `409` (`signature_unavailable`) when the
     * instance has no signature service.
     */
    public function retrySignature(Request $request, Inventory $inventory, InventoryVersion $version): JsonResponse
    {
        Gate::authorize('retrySignature', [$version, $inventory]);

        try {
            $retried = $this->signatures->retry($version);
        } catch (SignatureNotFailedException) {
            return ApiError::conflict(__('This signature request did not fail: there is nothing to send again.'), SignatureNotFailedException::CODE);
        } catch (SignatureUnavailableException) {
            return ApiError::conflict(__('Signatures cannot be asked for: this instance has no signature service configured.'), SignatureUnavailableException::CODE);
        }

        return $this->describe($request, $inventory, $retried)->response()->setStatusCode(202);
    }

    /**
     * Deletes a version (`204`) and, once that is committed, its files; a request for signatures still in progress is cancelled at the
     * signature service in the background. One that is being written is deleted all the same: the worker finds the row gone and
     * deletes what it wrote.
     */
    public function destroy(Inventory $inventory, InventoryVersion $version): Response
    {
        Gate::authorize('delete', [$version, $inventory]);

        $this->versions->delete($version);

        return response()->noContent();
    }

    /**
     * The version as the API gives it to the signed-in person.
     */
    private function describe(Request $request, Inventory $inventory, InventoryVersion $version): InventoryVersionResource
    {
        return new InventoryVersionResource($version, $inventory->ulid, $this->roleIn($request, $inventory));
    }

    /**
     * The role of the signed-in person in the workspace of the inventory: a viewer when they have none, which the policies make
     * unreachable.
     */
    private function roleIn(Request $request, Inventory $inventory): WorkspaceRole
    {
        return $this->workspaces->roleIn($this->user($request), $inventory->workspace_id) ?? WorkspaceRole::Viewer;
    }

    /**
     * The `409` of a file asked for before the version is completed.
     */
    private function notReady(): JsonResponse
    {
        return ApiError::conflict(__('This version is not ready yet.'), 'version_not_ready');
    }
}
