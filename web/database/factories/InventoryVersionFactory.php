<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Inventories\Versions\VersionStatus;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Builds official versions for tests and local seeding: the first version of an inventory, frozen now, whose PDF is still waiting
 * for the queue.
 *
 * It writes no file and counts no byte (`versionFreeze()` of the tests support freezes for real). Without an inventory given
 * (`->for($inventory)`), it builds a bare one; the number is the caller's to choose when it makes several for one inventory. The
 * counter of the numbers of the inventory (`last_version_number`) is brought up to the number of the row, as the freeze does, so that
 * the next version frozen for real is numbered after the ones the factory made.
 *
 * @extends Factory<InventoryVersion>
 */
class InventoryVersionFactory extends Factory
{
    /**
     * Keeps the counter of the numbers of the inventory up with the rows made.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (InventoryVersion $version): void {
            DB::table('inventories')
                ->where('id', $version->inventory_id)
                ->where('last_version_number', '<', $version->number)
                ->update(['last_version_number' => $version->number]);
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
            'user_id' => null,
            'number' => 1,
            'label' => null,
            'author_name' => 'Ada',
            'locale' => 'en',
            'frozen_at' => now()->startOfSecond(),
            'items_count' => 0,
            'fields_count' => 0,
            'status' => VersionStatus::Pending,
            'disk' => 'local',
            'snapshot_path' => 'versions/snapshot.json',
            'snapshot_size' => 2,
            'snapshot_sha256' => hash('sha256', '{}'),
            'counted_bytes' => 0,
        ];
    }
}
