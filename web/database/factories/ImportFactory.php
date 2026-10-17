<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Inventories\Imports\ImportFormat;
use App\Inventories\Imports\ImportOptions;
use App\Inventories\Imports\ImportStatus;
use App\Models\Import;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds imports for tests and local seeding: a CSV that was sent and analysed, with no column, that nobody started.
 *
 * It stores no file (`importStored()` of the tests support does). Without an inventory given (`->for($inventory)`) or a `user_id`, it
 * builds a bare one.
 *
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
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
            'format' => ImportFormat::Csv,
            'status' => ImportStatus::Uploaded,
            'disk' => 'local',
            'file_name' => 'items.csv',
            'size' => 100,
            'locale' => 'en',
            'options' => (new ImportOptions(delimiter: 'comma', encoding: 'utf-8'))->toArray(),
            'columns' => [],
            'expires_at' => now()->addDay(),
        ];
    }
}
