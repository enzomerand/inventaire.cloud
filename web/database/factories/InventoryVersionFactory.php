<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Inventories\Versions\VersionStatus;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds official versions for tests and local seeding: the first version of an inventory, frozen now, whose PDF is still waiting
 * for the queue.
 *
 * It writes no file and counts no byte (`versionFreeze()` of the tests support freezes for real). Without an inventory given
 * (`->for($inventory)`), it builds a bare one; the number is the caller's to choose when it makes several for one inventory.
 *
 * @extends Factory<InventoryVersion>
 */
class InventoryVersionFactory extends Factory
{
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
