<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\PhotoAnalysis;
use App\Models\User;
use App\PhotoEntry\PhotoAnalysisStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds photo analyses for tests and local seeding: one that was received and waits for the queue, with no photo stored.
 *
 * Without an inventory given (`->for($inventory)`) or a `user_id`, it builds a bare one.
 *
 * @extends Factory<PhotoAnalysis>
 */
class PhotoAnalysisFactory extends Factory
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
            'user_id' => User::factory(),
            'status' => PhotoAnalysisStatus::Pending,
            'disk' => 'local',
            'locale' => 'en',
            'photo_count' => 1,
            'expires_at' => now()->addDay(),
        ];
    }
}
