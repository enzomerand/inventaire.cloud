<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Builds items for tests and local seeding: an empty one unless values are given.
 *
 * The values are written as given, without being validated against the fields of the inventory (that is what
 * `App\Inventories\Items` does); a test that wants a realistic item gives the values in their canonical form. The item
 * is counted in the `items_count` of its inventory, as `Items` does, so that the counter stays true to the rows.
 *
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * Counts each item built in the counter of its inventory, the way the application does when it adds one.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Item $item): void {
            DB::table('inventories')->where('id', $item->inventory_id)->increment('items_count');
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
