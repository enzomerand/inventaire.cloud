<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventories\ReorderFieldsRequest;
use App\Http\Requests\Inventories\SaveFieldRequest;
use App\Http\Resources\InventoryFieldResource;
use App\Http\Responses\ApiError;
use App\Inventories\Exceptions\FieldLimitReachedException;
use App\Inventories\InventoryFields;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Policies\InventoryPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The fields of an inventory: add one, change one, delete one (with its values), put them in order.
 *
 * Who may is decided by {@see InventoryPolicy} (`manageFields`). A field belongs to the inventory in the path, or it is
 * a 404. An inventory has at most `inventories.max_fields` fields (`409`, `field_limit_reached`).
 */
final class InventoryFieldController extends Controller
{
    /**
     * Gets the rules of fields.
     */
    public function __construct(private readonly InventoryFields $fields) {}

    /**
     * Adds a field at the end of the inventory (`201`). `type` is one of `GET /api/v1/field-types` and cannot be
     * changed afterwards. `422` when the name is taken, in any case, by another field.
     */
    public function store(SaveFieldRequest $request, Inventory $inventory): JsonResponse
    {
        try {
            $field = $this->fields->create(
                $inventory,
                $request->sentType(),
                $request->changes()['name'] ?? '',
                $request->changes()['options'] ?? [],
                $request->changes()['required'] ?? false,
            );
        } catch (FieldLimitReachedException $limit) {
            return ApiError::conflict(
                trans_choice('An inventory cannot have more than :count field.|An inventory cannot have more than :count fields.', $limit->limit),
                FieldLimitReachedException::CODE,
                ['limit' => $limit->limit],
            );
        }

        return (new InventoryFieldResource($field))->response()->setStatusCode(201);
    }

    /**
     * Changes the name, the options or whether the field is required: whichever are sent. Sending another `type` is a `422`.
     */
    public function update(SaveFieldRequest $request, Inventory $inventory, InventoryField $field): InventoryFieldResource
    {
        return new InventoryFieldResource($this->fields->update($inventory, $field, $request->changes()));
    }

    /**
     * Deletes a field and its values from every item of the inventory (`204`).
     */
    public function destroy(Inventory $inventory, InventoryField $field): Response
    {
        Gate::authorize('manageFields', $inventory);

        $this->fields->delete($inventory, $field);

        return response()->noContent();
    }

    /**
     * Puts the fields in the order given. `fields` must be exactly the ULIDs of the fields the inventory has, each
     * once (`422` otherwise). Answers with the fields in their new order.
     */
    public function reorder(ReorderFieldsRequest $request, Inventory $inventory): AnonymousResourceCollection
    {
        return InventoryFieldResource::collection($this->fields->reorder($inventory, $request->fields()));
    }
}
