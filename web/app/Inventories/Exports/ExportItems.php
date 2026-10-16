<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\ItemQuery;
use App\Inventories\Items;
use App\Inventories\ItemSearchText;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use Generator;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Which items an export holds, and how they are read.
 *
 * The scope `all` is the whole inventory in the order the grid shows it by default (newest first); the scope `view` is what the
 * grid showed when the export was asked for, the search words, the filters and the sort of the request of the list, applied by the
 * same code as the list ({@see ItemSearchText}, {@see ItemQuery}). The ids of the matching items are listed first, in a
 * deterministic order (every sort ends with the id of the item), and the items are then read a chunk of {@see self::CHUNK} at a
 * time: memory does not grow with the inventory, and the set of items does not move while the file is written.
 */
final class ExportItems
{
    /** Items read at a time. */
    public const CHUNK = 500;

    /**
     * Gets the search of the list and its sort and filters.
     */
    public function __construct(
        private readonly ItemQuery $query,
        private readonly ItemSearchText $search,
    ) {}

    /**
     * The query for the items of an export, in the order they are written.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory.
     * @param  array<array-key, mixed>  $view  For the scope `view`: `q`, `sort`, `direction` and `filter`, as the list takes them.
     * @return HasMany<Item, Inventory>
     *
     * @throws ValidationException When the view does not hold for the fields the inventory has now (a field it uses was deleted).
     */
    public function matching(Inventory $inventory, Collection $fields, ExportScope $scope, array $view = []): HasMany
    {
        $items = $inventory->items();

        if ($scope === ExportScope::View) {
            $this->search->restrict($items, is_string($view['q'] ?? null) ? $view['q'] : '');
            $this->query->apply($items, $fields, $view);

            return $items;
        }

        $items->orderByDesc('items.id');

        return $items;
    }

    /**
     * The ids of the items of a query, in its order.
     *
     * @param  HasMany<Item, Inventory>  $items
     * @return list<int>
     */
    public function ids(HasMany $items): array
    {
        return array_values($items->pluck('items.id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * How many items a query matches.
     *
     * @param  HasMany<Item, Inventory>  $items
     */
    public function count(HasMany $items): int
    {
        return $items->toBase()->reorder()->count();
    }

    /**
     * The ids of the items of a query as a sub-query, for a table that hangs from the items.
     *
     * @param  HasMany<Item, Inventory>  $items
     */
    public function subQuery(HasMany $items): Builder
    {
        return $items->toBase()->reorder()->select('items.id');
    }

    /**
     * Reads the items of a list of ids a chunk at a time, in the order of the list. An id whose item is gone is skipped.
     *
     * @param  list<int>  $ids
     * @return Generator<int, Item>
     */
    public function read(Inventory $inventory, array $ids, bool $withAttachments = false): Generator
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $items = Item::query()->select(Items::LIST_COLUMNS)->where('items.inventory_id', $inventory->getKey())->whereIn('items.id', $chunk);

            if ($withAttachments) {
                $items->with('attachments');
            }

            $found = $items->get()->keyBy('id');

            foreach ($chunk as $id) {
                if (isset($found[$id])) {
                    yield $found[$id];
                }
            }
        }
    }
}
