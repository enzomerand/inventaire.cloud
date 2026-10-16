<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Inventories\ItemSearchText;
use App\Models\Inventory;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Builds items for tests and local seeding: an empty one unless values are given.
 *
 * The values are written as given, without being validated against the fields of the inventory (that is what
 * `App\Inventories\Items` does); a test that wants a realistic item gives the values in their canonical form. The item
 * is counted in the `items_count` of its inventory, as `Items` does, so that the counter stays true to the rows. The text
 * it is searched by (`search_text`) is built from its values and the fields the inventory has when the item is created
 * (empty when it has no values or nothing to search), as `Items` does, unless the test gives one (even null): the fields
 * added to the inventory afterwards are not taken into account, which is what `InventoryFields` is for.
 *
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * Counts each item built in the counter of its inventory, and writes its search text, the way the application does
     * when it adds one.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Item $item): void {
            DB::table('inventories')->where('id', $item->inventory_id)->increment('items_count');

            // One that was given a text (or null) on purpose keeps it.
            if (array_key_exists('search_text', $item->getAttributes())) {
                return;
            }

            // An item without values has an empty text, as `Items` writes it: null would say it was never built.
            $text = $item->values === []
                ? ''
                : app(ItemSearchText::class)->build($item->inventory()->firstOrFail()->fields()->get(), $item->values);

            DB::table('items')->where('id', $item->getKey())->update(['search_text' => $text]);
            $item->setAttribute('search_text', $text);
            $item->syncOriginalAttribute('search_text');
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_id' => Inventory::factory(),
            'values' => [],
        ];
    }
}
