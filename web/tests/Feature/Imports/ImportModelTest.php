<?php

declare(strict_types=1);

use App\Auth\Exceptions\AccountDeletionRefused;
use App\Installer\ApplicationTables;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Imports\ImportError;
use App\Inventories\Imports\ImportFormat;
use App\Inventories\Imports\ImportOptions;
use App\Inventories\Imports\ImportStatus;
use App\Jobs\PurgeStoredFiles;
use App\Models\Import;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/imports.php';

/*
 * The imports table and its model: the casts, the imports that still count, and what becomes of the files when an import, its
 * inventory, its workspace or the account that sent it is deleted (they are purged after the commit, by a queued job).
 */

uses(RefreshDatabase::class);

/**
 * An import with a source file on the fake disk, in a state.
 *
 * @param  array<string, mixed>  $attributes
 */
function modelImport(Inventory $inventory, User $user, array $attributes = []): Import
{
    $import = Import::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $user->id, 'disk' => ATTACHMENT_TEST_DISK] + $attributes);

    return importStored($import, "Name\r\na\r\n");
}

describe('the row', function (): void {
    it('is created uploaded, with its enums, options and dates cast', function (): void {
        attachmentDisk();
        $team = inventoryTeam();

        $import = modelImport(inventoryIn($team['workspace']), $team['editor'], ['options' => ['has_header' => false, 'delimiter' => 'tab', 'encoding' => 'utf-16', 'decimal' => 'point', 'date_order' => 'ymd', 'on_error' => 'skip']]);
        $fresh = Import::query()->findOrFail($import->id);

        expect($fresh->ulid)->toHaveLength(26)
            ->and($fresh->format)->toBe(ImportFormat::Csv)
            ->and($fresh->status)->toBe(ImportStatus::Uploaded)
            ->and($fresh->settings())->toEqual(new ImportOptions(false, 'tab', 'utf-16', 'point', 'ymd', 'skip'))
            ->and($fresh->columns)->toBe([])
            ->and($fresh->mapping)->toBeNull()
            ->and($fresh->errors)->toBeNull()
            ->and($fresh->rows_total)->toBeNull()
            ->and($fresh->isExpired())->toBeFalse()
            ->and($fresh->failure())->toBeNull();
    });

    it('is found by its ULID, whatever the case', function (): void {
        $team = inventoryTeam();
        $import = Import::factory()->create(['inventory_id' => inventoryIn($team['workspace'])->id, 'user_id' => $team['owner']->id]);

        expect((new Import)->resolveRouteBinding(strtoupper($import->ulid))?->id)->toBe($import->id);
    });

    it('is expired once its time is up', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $live = Import::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $team['owner']->id, 'expires_at' => now()->addMinute()]);
        $over = Import::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $team['owner']->id, 'expires_at' => now()->subSecond()]);
        $open = Import::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $team['owner']->id, 'expires_at' => null]);

        expect([$live->isExpired(), $over->isExpired(), $open->isExpired()])->toBe([false, true, false]);
    });

    it('knows its failure, and does not know the code of a later version', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $known = Import::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $team['owner']->id, 'status' => ImportStatus::Failed, 'error' => 'fields_changed']);
        $unknown = Import::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $team['owner']->id, 'status' => ImportStatus::Failed, 'error' => 'from_the_future']);

        expect($known->failure())->toBe(ImportError::FieldsChanged)
            ->and($known->failure()?->message())->toBeString()->not->toBe('')
            ->and($unknown->failure())->toBeNull();
    });

    it('has a message for every code of failure', function (): void {
        foreach (ImportError::cases() as $error) {
            expect($error->message())->toBeString()->not->toBe('');
        }
    });

    it('counts as active until it ends or expires, and as unexpired until it expires', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $make = fn (array $attributes) => Import::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $team['owner']->id] + $attributes)->id;
        $uploaded = $make(['expires_at' => now()->addMinute()]);
        $pending = $make(['status' => ImportStatus::Pending, 'expires_at' => null]);
        $running = $make(['status' => ImportStatus::Running, 'expires_at' => null]);
        $completed = $make(['status' => ImportStatus::Completed, 'expires_at' => now()->addHour()]);
        $failed = $make(['status' => ImportStatus::Failed, 'expires_at' => now()->addHour()]);
        $expired = $make(['expires_at' => now()->subMinute()]);

        expect(Import::query()->active()->pluck('id')->all())->toEqualCanonicalizing([$uploaded, $pending, $running])
            ->and(Import::query()->unexpired()->pluck('id')->all())->toEqualCanonicalizing([$uploaded, $pending, $running, $completed, $failed])
            ->and($expired)->not->toBeIn(Import::query()->unexpired()->pluck('id')->all());
    });
});

describe('the options', function (): void {
    it('default to the language and the separator, and read what was stored', function (): void {
        expect(ImportOptions::defaultDecimal('semicolon', 'en'))->toBe('comma')
            ->and(ImportOptions::defaultDecimal('comma', 'fr'))->toBe('comma')
            ->and(ImportOptions::defaultDecimal('tab', 'en'))->toBe('point')
            ->and(ImportOptions::defaultDateOrder('fr'))->toBe('dmy')
            ->and(ImportOptions::defaultDateOrder('en'))->toBe('mdy')
            ->and(ImportOptions::fromArray(null, 'fr')->toArray())->toBe(['has_header' => true, 'delimiter' => null, 'encoding' => null, 'decimal' => 'comma', 'date_order' => 'dmy', 'on_error' => 'abort'])
            ->and(ImportOptions::fromArray(['delimiter' => 'pipe', 'encoding' => 'klingon', 'decimal' => 'x', 'date_order' => 'y', 'on_error' => 'z', 'has_header' => false], 'en')->toArray())
            ->toBe(['has_header' => false, 'delimiter' => null, 'encoding' => null, 'decimal' => 'point', 'date_order' => 'mdy', 'on_error' => 'abort']);
    });

    it('change by the keys they are given, and recompute nothing else', function (): void {
        $options = new ImportOptions(true, 'semicolon', 'utf-8', 'comma', 'dmy', 'abort');

        expect($options->with(['on_error' => 'skip', 'has_header' => false])->toArray())->toBe(['has_header' => false, 'delimiter' => 'semicolon', 'encoding' => 'utf-8', 'decimal' => 'comma', 'date_order' => 'dmy', 'on_error' => 'skip'])
            ->and($options->delimiterCharacter())->toBe(';')
            ->and(new ImportOptions(delimiter: 'tab')->delimiterCharacter())->toBe("\t")
            ->and(new ImportOptions)->delimiterCharacter()->toBe(',');
    });
});

describe('the cascade', function (): void {
    it('goes with its inventory and with the account that sent it', function (): void {
        $team = inventoryTeam();
        $one = inventoryIn($team['workspace'], 'One');
        $two = inventoryIn($team['workspace'], 'Two');
        $byInventory = Import::factory()->create(['inventory_id' => $one->id, 'user_id' => $team['owner']->id]);
        $byUser = Import::factory()->create(['inventory_id' => $two->id, 'user_id' => $team['editor']->id]);
        $kept = Import::factory()->create(['inventory_id' => $two->id, 'user_id' => $team['owner']->id]);

        $one->delete();
        expect(Import::query()->whereKey($byInventory->id)->exists())->toBeFalse();

        $team['editor']->delete();
        expect(Import::query()->whereKey($byUser->id)->exists())->toBeFalse()
            ->and(Import::query()->whereKey($kept->id)->exists())->toBeTrue();
    });
});

describe('the files', function (): void {
    it('are purged when the import is deleted, and only its own folder', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $first = modelImport($inventory, $team['owner']);
        $second = modelImport($inventory, $team['owner']);

        $first->delete();

        expect(storedFiles())->toBe([$second->path])->and(Import::query()->count())->toBe(1);
    });

    it('are purged after the commit, and not at all when the deletion is rolled back', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $import = modelImport(inventoryIn($team['workspace']), $team['owner']);

        try {
            DB::transaction(function () use ($import): void {
                $import->delete();

                throw new RuntimeException('Rolled back.');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        expect(storedFiles())->toBe([$import->path])->and(Import::query()->count())->toBe(1);

        Queue::fake();
        DB::transaction(fn () => Import::query()->findOrFail($import->id)->delete());

        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [dirname((string) $import->path)]);
    });

    it('are purged with the inventory, the workspace and the account', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $one = inventoryIn($team['workspace'], 'One');
        $two = inventoryIn($team['workspace'], 'Two');
        $viewers = modelImport($one, $team['viewer']);
        $editors = modelImport($two, $team['editor']);
        $owners = modelImport($two, $team['owner']);

        $stranger = $team['stranger'];
        $elsewhere = inventoryIn($stranger->workspaces()->sole());
        $theirs = modelImport($elsewhere, $stranger);

        // The inventory takes its imports with it.
        $one->delete();
        expect(storedFiles())->toBe(collect([$editors->path, $owners->path, $theirs->path])->sort()->values()->all());

        // The account takes the imports it sent in a workspace that goes on.
        $team['editor']->delete();
        expect(storedFiles())->toBe(collect([$owners->path, $theirs->path])->sort()->values()->all());

        // The workspace takes the rest of its own.
        $team['workspace']->delete();
        expect(storedFiles())->toBe([$theirs->path]);

        // An account that owned its workspace alone takes everything with it.
        $stranger->delete();
        expect(storedFiles())->toBe([])->and(Import::query()->count())->toBe(0);
        unset($viewers);
    });

    it('are purged with the account together with its exports, which are collected too', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $import = modelImport($inventory, $team['editor']);
        $export = exportStored(exportRow($inventory, $team['editor']), 'mine.csv');
        $ownersImport = modelImport($inventory, $team['owner']);
        $ownersExport = exportStored(exportRow($inventory, $team['owner']), 'owners.csv');

        expect(storedFiles())->toHaveCount(4);

        $team['editor']->delete();

        expect(storedFiles())->toBe(collect([$ownersImport->path, $ownersExport->path])->sort()->values()->all())
            ->and($import->path)->not->toBeIn(storedFiles())
            ->and($export->path)->not->toBeIn(storedFiles());
    });

    it('are not purged for an account whose deletion is refused', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $import = modelImport(inventoryIn($team['workspace']), $team['owner']);

        // The owner of a workspace shared with other people cannot be deleted while it is the only owner.
        expect(fn () => $team['owner']->delete())->toThrow(AccountDeletionRefused::class);

        expect(storedFiles())->toBe([$import->path])->and(Import::query()->whereKey($import->id)->exists())->toBeTrue();
    });

    it('are kept for an import whose inventory is deleted by a statement that fails', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $import = modelImport($inventory, $team['owner']);

        try {
            DB::transaction(function () use ($inventory): void {
                $inventory->delete();

                throw new RuntimeException('Rolled back.');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        expect(storedFiles())->toBe([$import->path]);
    });
});

describe('the purge job', function (): void {
    it('accepts the folder of an import and nothing in between', function (): void {
        $ok = new PurgeStoredFiles('local', [], [AttachmentStorage::importDirectory('01abc', '01def', '01ghi')]);

        expect($ok->directories)->toBe(['workspaces/01abc/inventories/01def/imports/01ghi']);

        foreach (['workspaces/01abc/imports/01ghi', 'workspaces/01abc/inventories/01def/imports', 'workspaces/01abc/inventories/01def/imports/01ghi/source.csv', 'imports/01ghi'] as $folder) {
            expect(fn () => new PurgeStoredFiles('local', [], [$folder]))->toThrow(InvalidArgumentException::class);
        }
    });
});

it('does not purge anything for an import whose inventory is already gone', function (): void {
    attachmentDisk();
    Queue::fake();
    $team = inventoryTeam();
    $import = Import::factory()->create(['inventory_id' => ($inventory = inventoryIn($team['workspace']))->id, 'user_id' => $team['owner']->id]);
    $inventoryId = $inventory->id;

    DB::table('inventories')->where('id', $inventoryId)->delete();
    $import->delete();

    Queue::assertNotPushed(PurgeStoredFiles::class);
    expect(Inventory::query()->whereKey($inventoryId)->exists())->toBeFalse();
});

it('is listed among the tables of the application', function (): void {
    expect(ApplicationTables::owns('imports'))->toBeTrue();
});
