<?php

declare(strict_types=1);

use App\Auth\Exceptions\AccountDeletionRefused;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Exports\ExportError;
use App\Inventories\Exports\ExportFormat;
use App\Inventories\Exports\ExportScope;
use App\Inventories\Exports\ExportStatus;
use App\Jobs\PurgeStoredFiles;
use App\Models\Export;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/exports.php';

/*
 * The exports table and its model: the casts, the exports that still count, and what becomes of the files when an export, its
 * inventory, its workspace or the account that asked for it is deleted (they are purged after the commit, by a queued job).
 */

uses(RefreshDatabase::class);

describe('the row', function (): void {
    it('is created pending, with its enums and dates cast', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $export = exportRow($inventory, $team['viewer'], ['format' => ExportFormat::Csv, 'scope' => ExportScope::View, 'options' => ['delimiter' => 'tab']]);
        $fresh = Export::query()->findOrFail($export->id);

        expect($fresh->ulid)->toHaveLength(26)
            ->and($fresh->format)->toBe(ExportFormat::Csv)
            ->and($fresh->scope)->toBe(ExportScope::View)
            ->and($fresh->status)->toBe(ExportStatus::Pending)
            ->and($fresh->option('delimiter'))->toBe('tab')
            ->and($fresh->option('files', 'photos'))->toBe('photos')
            ->and($fresh->path)->toBeNull()
            ->and($fresh->size)->toBeNull()
            ->and($fresh->expires_at)->toBeNull()
            ->and($fresh->isDownloadable())->toBeFalse()
            ->and($fresh->failure())->toBeNull();
    });

    it('is found by its ULID, whatever the case', function (): void {
        $team = inventoryTeam();
        $export = exportRow(inventoryIn($team['workspace']), $team['owner']);

        expect((new Export)->resolveRouteBinding(strtoupper($export->ulid))?->id)->toBe($export->id);
    });

    it('is downloadable only once completed and until it expires', function (): void {
        $team = inventoryTeam();
        attachmentDisk();
        $export = exportStored(exportRow(inventoryIn($team['workspace']), $team['owner']));

        expect($export->isDownloadable())->toBeTrue();

        $export->forceFill(['expires_at' => now()->subSecond()])->save();

        expect($export->isDownloadable())->toBeFalse();

        $export->forceFill(['expires_at' => now()->addHour(), 'status' => ExportStatus::Failed, 'error' => ExportError::GenerationFailed->value])->save();

        expect($export->isDownloadable())->toBeFalse()
            ->and($export->failure())->toBe(ExportError::GenerationFailed)
            ->and($export->failure()?->message())->toBeString()->not->toBe('');
    });

    it('does not know a code of a later version, and says nothing for it', function (): void {
        $team = inventoryTeam();
        $export = exportRow(inventoryIn($team['workspace']), $team['owner'], ['status' => ExportStatus::Failed, 'error' => 'from_the_future']);

        expect($export->failure())->toBeNull();
    });

    it('counts as active until it expires', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $waiting = exportRow($inventory, $team['owner']);
        $valid = exportRow($inventory, $team['owner'], ['expires_at' => now()->addMinute()]);
        exportRow($inventory, $team['owner'], ['expires_at' => now()->subMinute()]);

        expect(Export::query()->active()->pluck('id')->all())->toEqualCanonicalizing([$waiting->id, $valid->id]);
    });
});

describe('the cascade', function (): void {
    it('goes with its inventory and with the account that asked for it', function (): void {
        $team = inventoryTeam();
        $one = inventoryIn($team['workspace'], 'One');
        $two = inventoryIn($team['workspace'], 'Two');
        $byInventory = exportRow($one, $team['owner']);
        $byUser = exportRow($two, $team['editor']);
        $kept = exportRow($two, $team['owner']);

        $one->delete();
        expect(Export::query()->whereKey($byInventory->id)->exists())->toBeFalse();

        $team['editor']->delete();
        expect(Export::query()->whereKey($byUser->id)->exists())->toBeFalse()
            ->and(Export::query()->whereKey($kept->id)->exists())->toBeTrue();
    });
});

describe('the files', function (): void {
    it('are purged when the export is deleted, and only its own folder', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $first = exportStored(exportRow($inventory, $team['owner']), 'first.xlsx');
        $second = exportStored(exportRow($inventory, $team['owner']), 'second.xlsx');

        $first->delete();

        expect(storedFiles())->toBe([$second->path])
            ->and(Export::query()->count())->toBe(1);
    });

    it('are purged after the commit, and not at all when the deletion is rolled back', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $export = exportStored(exportRow(inventoryIn($team['workspace']), $team['owner']));

        try {
            DB::transaction(function () use ($export): void {
                $export->delete();

                throw new RuntimeException('Rolled back.');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        expect(storedFiles())->toBe([$export->path])->and(Export::query()->count())->toBe(1);

        Queue::fake();
        DB::transaction(fn () => Export::query()->findOrFail($export->id)->delete());

        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [exportFolder($export)]);
    });

    it('are purged with the inventory, the workspace and the account', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $one = inventoryIn($team['workspace'], 'One');
        $two = inventoryIn($team['workspace'], 'Two');
        $viewers = exportStored(exportRow($one, $team['viewer']), 'viewer.csv');
        $editors = exportStored(exportRow($two, $team['editor']), 'editor.csv');
        $owners = exportStored(exportRow($two, $team['owner']), 'owner.csv');

        $stranger = $team['stranger'];
        $elsewhere = inventoryIn($stranger->workspaces()->sole());
        $theirs = exportStored(exportRow($elsewhere, $stranger), 'theirs.csv');

        // The inventory takes its exports with it.
        $one->delete();
        expect(storedFiles())->toBe(collect([$editors->path, $owners->path, $theirs->path])->sort()->values()->all());

        // The account takes the exports it asked for in a workspace that goes on.
        $team['editor']->delete();
        expect(storedFiles())->toBe(collect([$owners->path, $theirs->path])->sort()->values()->all());

        // The workspace takes the rest of its own.
        $team['workspace']->delete();
        expect(storedFiles())->toBe([$theirs->path]);

        // An account that owned its workspace alone takes everything with it.
        $stranger->delete();
        expect(storedFiles())->toBe([])->and(Export::query()->count())->toBe(0);
        unset($viewers);
    });

    it('are not purged for an account whose deletion is refused', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $export = exportStored(exportRow(inventoryIn($team['workspace']), $team['owner']));

        // The owner of a workspace shared with other people cannot be deleted while it is the only owner.
        expect(fn () => $team['owner']->delete())->toThrow(AccountDeletionRefused::class);

        expect(storedFiles())->toBe([$export->path])->and(Export::query()->whereKey($export->id)->exists())->toBeTrue();
    });
});

describe('the purge job', function (): void {
    it('accepts the folder of an export and nothing in between', function (): void {
        $ok = new PurgeStoredFiles('local', [], [AttachmentStorage::exportDirectory('01abc', '01def', '01ghi')]);

        expect($ok->directories)->toBe(['workspaces/01abc/inventories/01def/exports/01ghi']);

        foreach (['workspaces/01abc/exports/01ghi', 'workspaces/01abc/inventories/01def/exports', 'workspaces/01abc/inventories/01def/exports/01ghi/file', 'exports/01ghi'] as $folder) {
            expect(fn () => new PurgeStoredFiles('local', [], [$folder]))->toThrow(InvalidArgumentException::class);
        }
    });
});

it('does not purge anything for an export whose inventory is already gone', function (): void {
    attachmentDisk();
    Queue::fake();
    $team = inventoryTeam();
    $export = exportRow($inventory = inventoryIn($team['workspace']), $team['owner']);
    $inventoryId = $inventory->id;

    DB::table('inventories')->where('id', $inventoryId)->delete();
    $export->delete();

    Queue::assertNotPushed(PurgeStoredFiles::class);
    expect(User::query()->count())->toBeGreaterThan(0)->and(Inventory::query()->whereKey($inventoryId)->exists())->toBeFalse();
});
