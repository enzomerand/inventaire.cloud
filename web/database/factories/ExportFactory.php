<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Inventories\Exports\ExportFormat;
use App\Inventories\Exports\ExportScope;
use App\Inventories\Exports\ExportStatus;
use App\Models\Export;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds exports for tests and local seeding: a spreadsheet of a whole inventory that is still waiting for the queue.
 *
 * It writes no file (`exportStored()` of the tests support does, and completes the export). Without an inventory given (`->for($inventory)`)
 * or a `user_id`, it builds a bare one.
 *
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
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
            'format' => ExportFormat::Xlsx,
            'scope' => ExportScope::All,
            'options' => [],
            'locale' => 'en',
            'status' => ExportStatus::Pending,
            'disk' => 'local',
        ];
    }
}
