<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CommitsDatabaseChanges;

require_once __DIR__.'/../../Support/attachments.php';

/*
 * What several uploads and removals do to one workspace at the same moment, in separate PHP processes against the real
 * database and a real folder (a single process cannot show it: see InventoryConcurrencyTest.php). Every one that writes a
 * file locks the workspace, then the inventory, then the item, so that the quota of the workspace, the files of an item and
 * the storage counter are judged one request after the other, on every engine; and a file written for a request that is
 * refused is deleted. Each scenario is repeated, as one lucky ordering proves nothing.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(function (): void {
    removeConcurrencyRows();
    config(['inventories.attachments_disk' => 'local']);
});
afterEach(fn () => removeConcurrencyRows());

/**
 * Files of a given size in a folder of the test, to be uploaded by the children.
 *
 * @return list<string> Their paths.
 */
function concurrencyUploads(string $root, int $count, int $bytes): array
{
    @mkdir($root.'/uploads', 0700, true);

    return array_map(function (int $number) use ($root, $bytes): string {
        $path = "{$root}/uploads/file-{$number}.bin";
        file_put_contents($path, str_repeat((string) ($number % 10), $bytes));

        return $path;
    }, range(1, $count));
}

/**
 * The environment of the children: the folder they write to, the disk, and the limits the scenario sets.
 *
 * @param  array<string, string>  $limits
 * @return array<string, string>
 */
function concurrencyEnvironment(string $root, array $limits): array
{
    return $limits + ['TEST_STORAGE_ROOT' => $root.'/storage', 'ATTACHMENTS_DISK' => 'local'];
}

/**
 * The bytes the workspace says its files use, as the database holds it.
 */
function concurrencyStorageUsed(int $workspaceId): int
{
    return (int) DB::table('workspaces')->where('id', $workspaceId)->value('storage_used');
}

/**
 * The files below the folders of the workspaces on the local disk of the test.
 *
 * @return list<string>
 */
function concurrencyFiles(): array
{
    return Storage::disk('local')->allFiles('workspaces');
}

describe('uploads for the last bytes of a workspace', function (): void {
    it('are taken one after the other: only the files that fit are kept, and the counter and the disk agree', function (): void {
        foreach (range(1, 2) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);
            $item = itemIn($inventory);
            $files = concurrencyUploads($this->sandbox->root, 5, 400);

            // 1,049 bytes: two files of 400 bytes fit, a third does not.
            $outcomes = actTogether($this->sandbox->root, array_map(fn (string $path): array => ['upload-attachment', $team['owner']->id, $inventory->id, $item->id, $path], $files), concurrencyEnvironment($this->sandbox->root, ['INVENTORIES_STORAGE_PER_WORKSPACE_MB' => '0.001']));

            expect($outcomes)->toBe(['refused:storage_limit_reached', 'refused:storage_limit_reached', 'refused:storage_limit_reached', 'uploaded', 'uploaded'])
                ->and(Attachment::query()->where('item_id', $item->id)->count())->toBe(2)
                ->and(concurrencyStorageUsed($team['workspace']->id))->toBe(800)
                ->and(concurrencyFiles())->toHaveCount(2)
                ->and(Attachment::query()->pluck('path')->sort()->values()->all())->toBe(collect(concurrencyFiles())->sort()->values()->all());

            removeConcurrencyRows();
            Storage::disk('local')->deleteDirectory('workspaces');
        }
    });

    it('share the quota of the workspace across its items and inventories', function (): void {
        $team = inventoryTeam();
        $first = inventoryIn($team['workspace'], 'First');
        $second = inventoryIn($team['workspace'], 'Second');
        $items = [itemIn($first), itemIn($first), itemIn($second)];
        $files = concurrencyUploads($this->sandbox->root, 3, 400);

        $outcomes = actTogether($this->sandbox->root, [
            ['upload-attachment', $team['owner']->id, $first->id, $items[0]->id, $files[0]],
            ['upload-attachment', $team['editor']->id, $first->id, $items[1]->id, $files[1]],
            ['upload-attachment', $team['owner']->id, $second->id, $items[2]->id, $files[2]],
        ], concurrencyEnvironment($this->sandbox->root, ['INVENTORIES_STORAGE_PER_WORKSPACE_MB' => '0.001']));

        expect($outcomes)->toBe(['refused:storage_limit_reached', 'uploaded', 'uploaded'])
            ->and(concurrencyStorageUsed($team['workspace']->id))->toBe(800)
            ->and(Attachment::query()->count())->toBe(2)
            ->and(concurrencyFiles())->toHaveCount(2);
    });
});

describe('uploads for the last place of an item', function (): void {
    it('are taken one after the other: the item keeps one file and the other is deleted', function (): void {
        foreach (range(1, 2) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);
            $item = itemIn($inventory);
            $files = concurrencyUploads($this->sandbox->root, 3, 100);

            $outcomes = actTogether($this->sandbox->root, array_map(fn (string $path): array => ['upload-attachment', $team['owner']->id, $inventory->id, $item->id, $path], $files), concurrencyEnvironment($this->sandbox->root, ['INVENTORIES_MAX_ATTACHMENTS_PER_ITEM' => '1']));

            expect($outcomes)->toBe(['refused:attachment_limit_reached', 'refused:attachment_limit_reached', 'uploaded'])
                ->and(Attachment::query()->count())->toBe(1)
                ->and(concurrencyStorageUsed($team['workspace']->id))->toBe(100)
                ->and(concurrencyFiles())->toHaveCount(1);

            removeConcurrencyRows();
            Storage::disk('local')->deleteDirectory('workspaces');
        }
    });
});

describe('uploads for the last place of a file field', function (): void {
    it('are taken one after the other: the value of the field lists one file and the other is deleted', function (): void {
        foreach (range(1, 2) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);
            $field = fieldIn($inventory, 'file', 'Documents');
            $item = itemIn($inventory);
            $files = concurrencyUploads($this->sandbox->root, 3, 100);

            $outcomes = actTogether($this->sandbox->root, array_map(fn (string $path): array => ['upload-attachment', $team['owner']->id, $inventory->id, $item->id, $path, $field->ulid], $files), concurrencyEnvironment($this->sandbox->root, []));

            expect($outcomes)->toBe(['refused:field_file_limit_reached', 'refused:field_file_limit_reached', 'uploaded'])
                ->and(Attachment::query()->count())->toBe(1)
                ->and(Item::query()->findOrFail($item->id)->values[$field->ulid])->toBe([Attachment::query()->sole()->ulid])
                ->and(concurrencyStorageUsed($team['workspace']->id))->toBe(100)
                ->and(concurrencyFiles())->toHaveCount(1);

            removeConcurrencyRows();
            Storage::disk('local')->deleteDirectory('workspaces');
        }
    });

    it('keep every file of a field that takes several, in the list, none lost to another upload', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $field = fieldIn($inventory, 'file', 'Documents', ['max_files' => 4]);
        $item = itemIn($inventory);
        $files = concurrencyUploads($this->sandbox->root, 4, 50);

        $outcomes = actTogether($this->sandbox->root, array_map(fn (string $path): array => ['upload-attachment', $team['owner']->id, $inventory->id, $item->id, $path, $field->ulid], $files), concurrencyEnvironment($this->sandbox->root, []));

        expect($outcomes)->toBe(['uploaded', 'uploaded', 'uploaded', 'uploaded'])
            ->and(Item::query()->findOrFail($item->id)->values[$field->ulid])->toEqualCanonicalizing(Attachment::query()->pluck('ulid')->all())
            ->and(Item::query()->findOrFail($item->id)->values[$field->ulid])->toHaveCount(4);
    });
});

describe('uploads and removals at the same moment as the deletion of the item', function (): void {
    it('leave nothing behind: no row, no file, nothing counted', function (): void {
        foreach (range(1, 3) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);
            $item = itemIn($inventory);
            $existing = attachmentTo($inventory, $item, attachmentUploadedAs('existing.txt', str_repeat('e', 50)), $team['owner']);
            $files = concurrencyUploads($this->sandbox->root, 2, 70);

            $outcomes = actTogether($this->sandbox->root, [
                ['delete-item', $inventory->id, $item->id],
                ['upload-attachment', $team['owner']->id, $inventory->id, $item->id, $files[0]],
                ['upload-attachment', $team['editor']->id, $inventory->id, $item->id, $files[1]],
                ['remove-attachment', $team['owner']->id, $inventory->id, $item->id, $existing->id],
            ], concurrencyEnvironment($this->sandbox->root, []));

            // The deletion always happens; each other request either got in before it or found the item gone. Nothing fails otherwise.
            expect($outcomes)->toContain('deleted')
                ->and(array_filter($outcomes, fn (string $outcome): bool => ! in_array($outcome, ['deleted', 'uploaded', 'removed', 'refused:gone'], true)))->toBe([])
                ->and(Attachment::query()->count())->toBe(0)
                ->and(concurrencyStorageUsed($team['workspace']->id))->toBe(0)
                ->and(concurrencyFiles())->toBe([]);

            removeConcurrencyRows();
            Storage::disk('local')->deleteDirectory('workspaces');
        }
    });

    it('keep the counter equal to the files that are left when the item stays', function (): void {
        foreach (range(1, 3) as $_) {
            $team = inventoryTeam();
            $inventory = inventoryIn($team['workspace']);
            $item = itemIn($inventory);
            $first = attachmentTo($inventory, $item, attachmentUploadedAs('first.txt', str_repeat('a', 30)), $team['owner']);
            $second = attachmentTo($inventory, $item, attachmentUploadedAs('second.txt', str_repeat('b', 40)), $team['owner']);
            $files = concurrencyUploads($this->sandbox->root, 2, 70);

            $outcomes = actTogether($this->sandbox->root, [
                ['remove-attachment', $team['owner']->id, $inventory->id, $item->id, $first->id],
                ['remove-attachment', $team['editor']->id, $inventory->id, $item->id, $second->id],
                ['upload-attachment', $team['owner']->id, $inventory->id, $item->id, $files[0]],
                ['upload-attachment', $team['editor']->id, $inventory->id, $item->id, $files[1]],
            ], concurrencyEnvironment($this->sandbox->root, []));

            expect($outcomes)->toBe(['removed', 'removed', 'uploaded', 'uploaded'])
                ->and(Attachment::query()->count())->toBe(2)
                ->and(concurrencyStorageUsed($team['workspace']->id))->toBe(140)
                ->and(concurrencyStorageUsed($team['workspace']->id))->toBe((int) Attachment::query()->sum('size'))
                ->and(concurrencyFiles())->toHaveCount(2);

            removeConcurrencyRows();
            Storage::disk('local')->deleteDirectory('workspaces');
        }
    });
});
