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
 * It writes no file; a test that needs one writes it where `path` says. Without an inventory or a person given
 * (`->for($inventory)`, `->for($user)`), it builds bare ones.
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

    /**
     * An export that is done: its file is where `path` says (the caller writes it), and it lasts one more day.
     */
    public function completed(string $path = 'export.xlsx', int $size = 1024): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ExportStatus::Completed,
            'path' => $path,
            'file_name' => basename($path),
            'mime' => ExportFormat::Xlsx->mime(),
            'size' => $size,
            'items_count' => 0,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }
}
