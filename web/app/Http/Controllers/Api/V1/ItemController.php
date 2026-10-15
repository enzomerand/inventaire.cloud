<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventories\ListItemsRequest;
use App\Http\Requests\Inventories\SaveItemRequest;
use App\Http\Resources\ItemResource;
use App\Http\Responses\ApiError;
use App\Inventories\Exceptions\ItemLimitReachedException;
use App\Inventories\ItemQuery;
use App\Inventories\Items;
use App\Inventories\ItemSearchText;
use App\Models\Inventory;
use App\Models\Item;
use App\Policies\InventoryPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use stdClass;

/**
 * The items of an inventory: list them (50 a page, newest first unless sorted, searched by words, filtered and totalled),
 * read, add, change and delete one.
 *
 * Who may is decided by {@see InventoryPolicy}: every member reads, editors and owners write. An item belongs to the
 * inventory in the path, or it is a 404. An inventory holds at most `inventories.max_items` items (`409`,
 * `item_limit_reached`).
 */
final class ItemController extends Controller
{
    /**
     * Receives the service that creates, changes and deletes items, the one that searches them, and the one that sorts,
     * filters and totals them.
     */
    public function __construct(
        private readonly Items $items,
        private readonly ItemSearchText $search,
        private readonly ItemQuery $query,
    ) {}

    /**
     * A page of items, newest first unless `sort` says otherwise, in the `data` envelope with `meta` and `links`. With
     * `q`, only the items that hold every word of it, whatever the case and the accents; with `filter[<field>][<operator>]`,
     * only the ones that meet every condition ({@see ItemQuery}). The links of the pages keep all of it.
     *
     * `meta.total` is the number of items that match, and `meta.totals` the sum of every number and amount field over all
     * of them (not only the page), keyed by the ULID of the field; an empty object when the inventory has no such field.
     */
    public function index(ListItemsRequest $request, Inventory $inventory): AnonymousResourceCollection
    {
        $fields = $inventory->fields()->get();
        $items = $inventory->items();
        $this->search->restrict($items, (string) $request->validated('q', ''));
        $this->query->apply($items, $fields, $request->validated());
        $totals = $this->query->totals($items, $fields);
        $page = $items->paginate(Items::PER_PAGE)->withQueryString();

        return ItemResource::collection($page->through(fn (Item $item): ItemResource => new ItemResource($item, $fields)))
            // An object even when it is empty: the same shape whatever the fields are.
            ->additional(['meta' => ['totals' => $totals === [] ? new stdClass : $totals]]);
    }

    /**
     * Adds an item (`201`). `values` is an object keyed by the ULID of the field; `422` for a key that is no field of the
     * inventory, a value the field refuses, or a required field without a value.
     */
    public function store(SaveItemRequest $request, Inventory $inventory): JsonResponse
    {
        try {
            $item = $this->items->create($inventory, $request->values(), $this->user($request));
        } catch (ItemLimitReachedException $limit) {
            return ApiError::conflict(
                trans_choice('An inventory cannot hold more than :count item.|An inventory cannot hold more than :count items.', $limit->limit),
                ItemLimitReachedException::CODE,
                ['limit' => $limit->limit],
            );
        }

        return $this->describe($inventory, $item)->response()->setStatusCode(201);
    }

    /**
     * One item.
     */
    public function show(Inventory $inventory, Item $item): ItemResource
    {
        Gate::authorize('view', $inventory);

        return $this->describe($inventory, $item);
    }

    /**
     * Changes the values of an item: only the keys that are sent change, and an empty value clears its field (`422` for
     * a required one).
     */
    public function update(SaveItemRequest $request, Inventory $inventory, Item $item): ItemResource
    {
        return $this->describe($inventory, $this->items->update($inventory, $item, $request->values(), $this->user($request)));
    }

    /**
     * Deletes an item (`204`).
     */
    public function destroy(Inventory $inventory, Item $item): Response
    {
        Gate::authorize('editItems', $inventory);

        $this->items->delete($inventory, $item);

        return response()->noContent();
    }

    /**
     * The answer that describes one item, against the fields the inventory has now.
     */
    private function describe(Inventory $inventory, Item $item): ItemResource
    {
        return new ItemResource($item, $inventory->fields()->get());
    }
}
