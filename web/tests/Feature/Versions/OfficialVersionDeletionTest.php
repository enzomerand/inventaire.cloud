<?php

declare(strict_types=1);

use App\Installer\ApplicationTables;
use App\Inventories\DerivedData;
use App\Inventories\Inventories;
use App\Inventories\Versions\OfficialVersions;
use App\Jobs\PurgeStoredFiles;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/versions.php';

/*
 * What becomes of the files and of the storage counter of a workspace when an official version, its inventory or its workspace is
 * deleted, and when the counter is added up again.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    app()->setLocale('en');
});

/**
 * The storage counter of a workspace, as the database holds it.
 */
function versionStorageUsed(Workspace $workspace): int
{
    return (int) DB::table('workspaces')->where('id', $workspace->id)->value('storage_used');
}

/**
 * The files of the versions on the fake disk.
 *
 * @return list<string>
 */
function versionFiles(): array
{
    return array_values(array_filter(storedFiles(), fn (string $file): bool => str_contains($file, '/versions/')));
}

describe('deleting a version', function (): void {
    it('gives the bytes it counted back to the workspace, deletes the row and purges its folder once committed', function (): void {
        $case = versionTableCase();
        $kept = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        $gone = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        $used = versionStorageUsed($case['team']['workspace']);

        app(OfficialVersions::class)->delete($gone);

        expect(InventoryVersion::query()->pluck('id')->all())->toBe([$kept->id])
            ->and(versionStorageUsed($case['team']['workspace']))->toBe($used - $gone->counted_bytes)
            ->and($gone->counted_bytes)->toBeGreaterThan(0);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [$gone->directory()]);
    });

    it('purges the files for real, and only its own folder', function (): void {
        $case = versionTableCase();
        $kept = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        $gone = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        Queue::fake([]);
        app()->forgetInstance(Illuminate\Contracts\Queue\Queue::class);

        // Run the job the deletion queues, by hand.
        app(OfficialVersions::class)->delete($gone);
        Queue::assertPushed(PurgeStoredFiles::class, function (PurgeStoredFiles $job): bool {
            $job->handle(app(Factory::class));

            return true;
        });

        expect(versionFiles())->toContain($kept->snapshot_path, (string) $kept->pdf_path)
            ->and(array_filter(versionFiles(), fn (string $file): bool => str_contains($file, $gone->ulid)))->toBe([]);
    });

    it('takes nothing back for a version that holds nothing: pending, or failed', function (): void {
        $case = versionTableCase();
        $pending = versionFreeze($case['inventory'], $case['team']['owner']);
        $used = versionStorageUsed($case['team']['workspace']);

        app(OfficialVersions::class)->delete($pending);

        expect(versionStorageUsed($case['team']['workspace']))->toBe($used)
            ->and(InventoryVersion::query()->count())->toBe(0);
    });

    it('takes the lock of the workspace, then the one of the inventory, and reads the version after them', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        $statements = statementsOf(fn () => app(OfficialVersions::class)->delete($version));

        $locks = array_values(array_filter(array_keys($statements), fn (int $at): bool => str_contains(strtolower($statements[$at]), ' for update')));
        $read = array_values(array_filter(array_keys($statements), fn (int $at): bool => str_contains($statements[$at], 'inventory_versions') && str_starts_with(strtolower($statements[$at]), 'select')));

        expect($locks)->toHaveCount(2)
            ->and($statements[$locks[0]])->toContain('workspaces')
            ->and($statements[$locks[1]])->toContain('inventories')
            ->and($read)->not->toBe([])
            ->and($read[0])->toBeGreaterThan($locks[1]);
    });

    it('fails like a missing route binding when the version, or its inventory, is already gone', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        $stale = InventoryVersion::query()->findOrFail($version->id);

        app(OfficialVersions::class)->delete($version);

        expect(fn () => app(OfficialVersions::class)->delete($stale))->toThrow(ModelNotFoundException::class);

        $other = versionFreeze($case['inventory'], $case['team']['owner']);
        Inventory::query()->whereKey($case['inventory']->id)->delete();

        expect(fn () => app(OfficialVersions::class)->delete($other))->toThrow(ModelNotFoundException::class);
    });

    it('does not take back more than the workspace holds', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        DB::table('workspaces')->where('id', $case['team']['workspace']->id)->update(['storage_used' => 1]);

        app(OfficialVersions::class)->delete($version);

        expect(versionStorageUsed($case['team']['workspace']))->toBe(0);
    });
});

describe('deleting an inventory', function (): void {
    it('gives back what its files and its versions counted, and purges its folder, versions included', function (): void {
        $case = versionTableCase();
        $before = versionStorageUsed($case['team']['workspace']);
        $first = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        $second = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        $photos = $before;

        expect(versionStorageUsed($case['team']['workspace']))->toBe($photos + $first->counted_bytes + $second->counted_bytes)
            ->and($photos)->toBeGreaterThan(0);

        app(Inventories::class)->delete($case['inventory']);

        expect(versionStorageUsed($case['team']['workspace']))->toBe(0)
            ->and(InventoryVersion::query()->count())->toBe(0);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === ["workspaces/{$case['team']['workspace']->ulid}/inventories/{$case['inventory']->ulid}"]);
    });

    it('purges the folder of an inventory that holds nothing but versions, on the disk of the version', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Only versions');
        $version = versionRun(versionFreeze($inventory, $team['owner']));
        // The disk the files were written to is not the one new files go to any more.
        DB::table('inventory_versions')->where('id', $version->id)->update(['disk' => 'elsewhere']);
        config(['inventories.attachments_disk' => 'local']);

        app(Inventories::class)->delete($inventory);

        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->disk === 'elsewhere' && $job->directories === ["workspaces/{$team['workspace']->ulid}/inventories/{$inventory->ulid}"]);
    });

    it('cascades the rows of the versions with the inventory, and keeps the other inventories versions', function (): void {
        $case = versionTableCase();
        $other = inventoryIn($case['team']['workspace'], 'Other');
        versionFreeze($case['inventory'], $case['team']['owner']);
        $kept = versionFreeze($other, $case['team']['owner']);

        app(Inventories::class)->delete($case['inventory']);

        expect(InventoryVersion::query()->pluck('id')->all())->toBe([$kept->id]);
    });
});

describe('deleting a workspace or an account', function (): void {
    it('purges the folder of the workspace, whatever disk its versions were written to', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');
        $version = versionRun(versionFreeze($inventory, $team['owner']));
        DB::table('inventory_versions')->where('id', $version->id)->update(['disk' => 'elsewhere']);

        app(Workspaces::class)->delete($team['owner'], $team['workspace']);

        expect(InventoryVersion::query()->count())->toBe(0);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->disk === 'elsewhere' && $job->directories === ["workspaces/{$team['workspace']->ulid}"]);
    });

    it('keeps a version when the account of its author is deleted, and forgets the author', function (): void {
        $case = versionTableCase();
        $editor = $case['team']['editor'];
        $version = versionFreeze($case['inventory'], $editor);

        User::query()->whereKey($editor->id)->delete();

        expect($version->fresh()->user_id)->toBeNull()
            ->and($version->fresh()->author_name)->toBe('Editor');
    });
});

describe('adding the storage up again', function (): void {
    it('counts the versions too, and corrects a counter that is off', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        $photos = (int) DB::table('attachments')->where('inventory_id', $case['inventory']->id)->sum('size');
        $right = $photos + $version->counted_bytes;
        DB::table('workspaces')->where('id', $case['team']['workspace']->id)->update(['storage_used' => $photos]);

        $corrected = app(DerivedData::class)->recountStorage();

        expect($corrected)->toBe(1)
            ->and(versionStorageUsed($case['team']['workspace']))->toBe($right)
            ->and(app(DerivedData::class)->recountStorage())->toBe(0);
    });

    it('does not count a version in the making, which holds nothing yet', function (): void {
        $case = versionTableCase();
        $pending = versionFreeze($case['inventory'], $case['team']['owner']);
        $photos = (int) DB::table('attachments')->where('inventory_id', $case['inventory']->id)->sum('size');
        DB::table('workspaces')->where('id', $case['team']['workspace']->id)->update(['storage_used' => $photos]);

        expect(app(DerivedData::class)->recountStorage())->toBe(0)
            ->and($pending->counted_bytes)->toBe(0);
    });

    it('counts the versions of the workspace alone, not those of another', function (): void {
        $case = versionTableCase();
        $other = inventoryTeam();
        $inventory = inventoryIn($other['workspace'], 'Else');
        $version = versionRun(versionFreeze($inventory, $other['owner']));
        $photos = (int) DB::table('attachments')->where('inventory_id', $case['inventory']->id)->sum('size');
        DB::table('workspaces')->where('id', $case['team']['workspace']->id)->update(['storage_used' => $photos]);
        DB::table('workspaces')->where('id', $other['workspace']->id)->update(['storage_used' => $version->counted_bytes]);

        expect(app(DerivedData::class)->recountStorage())->toBe(0);
    });
});

describe('the table', function (): void {
    it('has its columns, one number per inventory, and its rows hang from the inventory', function (): void {
        expect(Schema::hasColumns('inventory_versions', [
            'id', 'ulid', 'inventory_id', 'user_id', 'number', 'label', 'author_name', 'locale', 'frozen_at', 'items_count', 'fields_count',
            'status', 'error', 'disk', 'snapshot_path', 'snapshot_size', 'snapshot_sha256', 'pdf_path', 'pdf_size', 'pdf_sha256', 'pdf_pages',
            'counted_bytes', 'signature_status', 'signature_error', 'signers', 'documenso_envelope_id', 'signature_requested_at',
            'signature_completed_at', 'signature_synced_at', 'signed_pdf_path', 'signed_pdf_size', 'signed_pdf_sha256', 'created_at', 'updated_at',
        ]))->toBeTrue();

        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);

        expect(fn () => DB::transaction(fn () => DB::table('inventory_versions')->insert(['ulid' => strtolower((string) Str::ulid())] + $version->only(['inventory_id', 'number', 'author_name', 'locale', 'frozen_at', 'items_count', 'fields_count', 'status', 'disk', 'snapshot_path', 'snapshot_size', 'snapshot_sha256']) + ['created_at' => now(), 'updated_at' => now()])))
            ->toThrow(UniqueConstraintViolationException::class)
            ->and($case['inventory']->versions()->pluck('id')->all())->toBe([$version->id])
            ->and($version->inventory->is($case['inventory']))->toBeTrue()
            ->and($version->user_id)->toBe($case['team']['owner']->id)
            ->and($version->getRouteKeyName())->toBe('ulid')
            ->and($case['inventory']->versions()->first()?->is($version))->toBeTrue();
    });

    it('is known to the installer', function (): void {
        expect(ApplicationTables::owns('inventory_versions'))->toBeTrue();
    });

    it('finds a version by its ULID inside its inventory only, whatever the case', function (): void {
        $case = versionTableCase();
        $other = inventoryIn($case['team']['workspace'], 'Other');
        $version = versionFreeze($case['inventory'], $case['team']['owner']);

        expect($case['inventory']->resolveChildRouteBinding('version', strtoupper($version->ulid), null)?->is($version))->toBeTrue()
            ->and($other->resolveChildRouteBinding('version', $version->ulid, null))->toBeNull();
    });
});
