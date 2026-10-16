<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Models\Export;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use Generator;
use Illuminate\Support\Collection;

/**
 * What a writer needs to write an export: the export and its inventory, the fields it holds as columns, and the items, read a
 * chunk at a time in the order of the list made when the export began.
 *
 * The list of the ids is made first and the items are read afterwards, so that the set of items is the one of that moment even when
 * the inventory changes while the file is written (an item added meanwhile is not in it; one deleted meanwhile is skipped).
 */
final readonly class ExportContext
{
    /**
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory this version knows the type of, in the order they are shown.
     * @param  list<int>  $itemIds  The ids of the items, in the order they are written.
     */
    public function __construct(
        public Export $export,
        public Inventory $inventory,
        public Collection $fields,
        public array $itemIds,
        public ExportTable $table,
        private ExportItems $source,
    ) {}

    /**
     * The items, one at a time, in order, with their files when `$withAttachments` says so.
     *
     * @return Generator<int, Item>
     */
    public function items(bool $withAttachments = false): Generator
    {
        return $this->source->read($this->inventory, $this->itemIds, $withAttachments || $this->table->hasFileFields());
    }
}
