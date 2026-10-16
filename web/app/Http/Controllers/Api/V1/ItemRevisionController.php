<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemRevisionResource;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\ItemRevision;
use App\Policies\InventoryPolicy;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The history of an item: who changed what, and when, latest first.
 *
 * Every member of the workspace may read it ({@see InventoryPolicy}, `view`). The item belongs to the inventory in the
 * path, or it is a 404.
 */
final class ItemRevisionController extends Controller
{
    /** Revisions per page. */
    public const PER_PAGE = 25;

    /**
     * A page of the history of the item, latest first, in the `data` envelope with `meta` and `links`.
     */
    public function index(Inventory $inventory, Item $item): AnonymousResourceCollection
    {
        Gate::authorize('view', $inventory);

        $fields = $inventory->fields()->get()->keyBy('ulid');
        $page = $item->revisions()->with('user:id,name')->orderByDesc('id')->paginate(self::PER_PAGE);

        return ItemRevisionResource::collection($page->through(fn (ItemRevision $revision): ItemRevisionResource => new ItemRevisionResource($revision, $fields)));
    }
}
