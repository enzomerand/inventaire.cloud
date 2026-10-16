<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventories\UploadAttachmentRequest;
use App\Http\Resources\ItemResource;
use App\Http\Responses\ApiError;
use App\Http\Responses\StoredFileResponse;
use App\Inventories\Attachments\Attachments;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Exceptions\AttachmentLimitReachedException;
use App\Inventories\Exceptions\FieldFileLimitReachedException;
use App\Inventories\Exceptions\StorageLimitReachedException;
use App\Models\Attachment;
use App\Models\Inventory;
use App\Models\Item;
use App\Policies\InventoryPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The files attached to an item: upload one, download one (or its thumbnail), remove one.
 *
 * Who may is decided by {@see InventoryPolicy}: every member reads, editors and owners write. A file belongs to the item in
 * the path, and the item to the inventory in the path, or it is a 404. The answers to a change describe the item as it is
 * now ({@see ItemResource}), `attachments` included. The limits answer `409` with a machine-readable `code`:
 * `attachment_limit_reached` (files of the item), `field_file_limit_reached` (files of a file or photo field),
 * `storage_limit_reached` (storage of the workspace).
 */
final class AttachmentController extends Controller
{
    /**
     * Receives the service that attaches and removes files and the storage that holds them.
     */
    public function __construct(
        private readonly Attachments $attachments,
        private readonly AttachmentStorage $storage,
    ) {}

    /**
     * Attaches the `file` part of the request to the item (`201`, the item as it is now), as the content of the file or photo
     * `field` of the inventory when one is named (its ULID). `422` when the file is missing or too large, the field is not a
     * file or photo field of the inventory, or a photo field is given something that is not a JPEG, PNG, WebP or GIF image.
     */
    public function store(UploadAttachmentRequest $request, Inventory $inventory, Item $item): JsonResponse
    {
        try {
            $this->attachments->upload($inventory, $item, $request->uploaded(), $this->user($request), $request->field());
        } catch (AttachmentLimitReachedException $limit) {
            return ApiError::conflict(
                trans_choice('An item cannot hold more than :count file.|An item cannot hold more than :count files.', $limit->limit),
                AttachmentLimitReachedException::CODE,
                ['limit' => $limit->limit],
            );
        } catch (FieldFileLimitReachedException $limit) {
            return ApiError::conflict(
                trans_choice('This field cannot hold more than :count file.|This field cannot hold more than :count files.', $limit->limit),
                FieldFileLimitReachedException::CODE,
                ['limit' => $limit->limit],
            );
        } catch (StorageLimitReachedException $limit) {
            return ApiError::conflict(
                __('The storage of this workspace is full: its files cannot weigh more than :size in all.', ['size' => Number::fileSize($limit->limit, maxPrecision: 1)]),
                StorageLimitReachedException::CODE,
                ['limit' => $limit->limit],
            );
        }

        return $this->describe($inventory, $item)->response()->setStatusCode(201);
    }

    /**
     * Removes a file from the item (the item as it is now).
     */
    public function destroy(Request $request, Inventory $inventory, Item $item, Attachment $attachment): ItemResource
    {
        Gate::authorize('editItems', $inventory);

        $this->attachments->delete($inventory, $item, $attachment, $this->user($request));

        return $this->describe($inventory, $item);
    }

    /**
     * The file, as a download; shown in the page instead with `?inline=1` for an image or a PDF. `304` for a reader who has it already.
     */
    public function show(Request $request, Inventory $inventory, Item $item, Attachment $attachment): Response|StreamedResponse|JsonResponse
    {
        Gate::authorize('view', $inventory);

        return StoredFileResponse::file($attachment, $this->storage, $request);
    }

    /**
     * The thumbnail of the file, a JPEG; `404` when it has none.
     */
    public function thumbnail(Inventory $inventory, Item $item, Attachment $attachment): StreamedResponse|JsonResponse
    {
        Gate::authorize('view', $inventory);

        return StoredFileResponse::thumbnail($attachment, $this->storage);
    }

    /**
     * The answer that describes the item as it is now, files included.
     */
    private function describe(Inventory $inventory, Item $item): ItemResource
    {
        return new ItemResource($item->refresh()->load('attachments.uploader:id,name'), $inventory->fields()->get(), $inventory);
    }
}
