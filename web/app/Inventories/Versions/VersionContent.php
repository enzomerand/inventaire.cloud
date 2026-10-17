<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

use App\Models\InventoryField;
use App\Models\Item;
use Illuminate\Support\Collection;

/**
 * What the snapshot of an official version says, read back: the names, the definitions of the fields and the items with their
 * values, as models that are not saved anywhere so that the code that lays an export out can lay a version out too.
 *
 * The PDF of a version is written from this and never from the database, which has moved on since the inventory was frozen.
 */
final readonly class VersionContent
{
    /**
     * @param  Collection<int, InventoryField>  $fields  The fields, in the order they are shown.
     * @param  list<Item>  $items  The items, in the order they were frozen.
     */
    public function __construct(
        public string $inventoryName,
        public ?string $inventoryDescription,
        public Collection $fields,
        public array $items,
    ) {}
}
