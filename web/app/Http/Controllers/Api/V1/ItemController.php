<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventories\ListItemsRequest;
use App\Http\Requests\Inventories\SaveItemRequest;
use App\Http\Resources\ItemResource;
use App\Http\Responses\ApiError;
use App\Inventories\Attachments\Attachments;
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

/**
 * The items of an inventory: list them (50 a page, newest first unless sorted, searched by words, filtered and, when asked, totalled),
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
     * `meta.total` is the number of items that match. `totals[]` names the number and amount fields to add up, and
     * `meta.totals` then holds the sum of each over all the items that match (not only the page), keyed by the ULID of
     * the field. Totals are opt-in because they cost a pass over the values of every item for each field: without
     * `totals`, `meta` has no `totals` and the query is not run. `422` (`totals.<position>`) for a name that is no field
     * of the inventory or a field that does not add up. Each item comes with its files (`attachments`). `meta.limits` tells
     * what a file may weigh (`attachment_size`, bytes) and how many one item may hold (`attachments_per_item`).
     */
    public function index(ListItemsRequest $request, Inventory $inventory): AnonymousResourceCollection
    {
        $fields = $inventory->fields()->get();
        // The files of the page's items, and the names of those who uploaded them, come in two more queries, not two per item.
        $items = $inventory->items()->with('attachments.uploader:id,name');
        $this->search->restrict($items, (string) $request->validated('q', ''));
        $totalled = $this->query->apply($items, $fields, $request->validated());
        $page = $items->paginate(Items::PER_PAGE, Items::LIST_COLUMNS)->withQueryString();
        $list = ItemResource::collection($page->through(fn (Item $item): ItemResource => new ItemResource($item, $fields, $inventory)));
        $meta = ['limits' => Attachments::limits()];

        if ($totalled !== []) {
            $meta['totals'] = $this->query->totals($items, $fields, $totalled);
        }

        return $list->additional(['meta' => $meta]);
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
        return new ItemResource($item, $inventory->fields()->get(), $inventory);
    }
}
