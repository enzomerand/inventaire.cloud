<?php

declare(strict_types=1);

// Helpers shared by the tests of the photo entry. Each test file loads them with require_once, so a file can be run alone. Their names
// all start with "photo" so that they never collide with the helpers of tests/Pest.php or of other test files.

use App\Inventories\Attachments\AttachmentStorage;
use App\Models\Inventory;
use App\Models\PhotoAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/attachments.php';
require_once __DIR__.'/inventories.php';

/**
 * A team and an inventory on a fake disk, the way the photo analyses need them.
 *
 * @return array{team: array<string, mixed>, inventory: Inventory}
 */
function photoCase(string $name = 'Stock'): array
{
    attachmentDisk();

    $team = inventoryTeam();

    return ['team' => $team, 'inventory' => inventoryIn($team['workspace'], $name)];
}

/**
 * A photo analysis of an inventory, by a person, with `$count` photos on the fake disk, in a state.
 *
 * @param  array<string, mixed>  $attributes
 */
function photoAnalysisWithFiles(Inventory $inventory, User $user, array $attributes = [], int $count = 2): PhotoAnalysis
{
    $analysis = PhotoAnalysis::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $user->id, 'disk' => ATTACHMENT_TEST_DISK, 'photo_count' => $count] + $attributes);

    foreach ($count > 0 ? range(1, $count) : [] as $number) {
        Storage::disk(ATTACHMENT_TEST_DISK)->put(photoFolder($analysis).'/'.$number.'.jpg', 'jpeg-'.$number);
    }

    return $analysis;
}

/**
 * The folder of the photos of an analysis on the disk.
 */
function photoFolder(PhotoAnalysis $analysis): string
{
    $inventory = $analysis->inventory()->firstOrFail();

    return AttachmentStorage::analysisDirectory($inventory->workspace()->firstOrFail()->ulid, $inventory->ulid, $analysis->ulid);
}
