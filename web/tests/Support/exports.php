<?php

declare(strict_types=1);

// Helpers shared by the tests of exports. Each test file loads them with require_once, so a file can be run alone. Their names all
// start with "export" so that they never collide with the helpers of tests/Pest.php or of other test files.

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Exports\ExportStatus;
use App\Models\Export;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/attachments.php';

/**
 * A request for an export, as the application stores it (pending, nothing written yet), on the fake disk of the test.
 *
 * @param  array<string, mixed>  $attributes  Over the defaults of the factory: a spreadsheet of the whole inventory in English.
 */
function exportRow(Inventory $inventory, User $user, array $attributes = []): Export
{
    return Export::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $user->id, 'disk' => ATTACHMENT_TEST_DISK] + $attributes);
}

/**
 * The folder an export is stored in, as the application works it out.
 */
function exportFolder(Export $export): string
{
    $inventory = $export->inventory()->firstOrFail();

    return AttachmentStorage::exportDirectory($inventory->workspace()->firstOrFail()->ulid, $inventory->ulid, $export->ulid);
}

/**
 * Writes the file of an export where the application would, and marks the export completed with it.
 */
function exportStored(Export $export, string $name = 'export.xlsx', string $contents = 'contents'): Export
{
    $path = exportFolder($export).'/'.$name;
    Storage::disk(ATTACHMENT_TEST_DISK)->put($path, $contents);

    $export->forceFill([
        'status' => ExportStatus::Completed,
        'path' => $path,
        'file_name' => $name,
        'mime' => $export->format->mime(),
        'size' => strlen($contents),
        'items_count' => 0,
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
        'expires_at' => now()->addDay(),
    ])->save();

    return $export;
}
