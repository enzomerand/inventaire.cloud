<?php

declare(strict_types=1);

use App\Inventories\Exports\ExportTemp;
use App\Inventories\Imports\ImportError;
use App\Inventories\Imports\ImportPruner;
use App\Inventories\Imports\ImportRunner;
use App\Inventories\Imports\ImportStatus;
use App\Jobs\GenerateExport;
use App\Jobs\PurgeStoredFiles;
use App\Models\Import;
use App\Models\Item;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/imports.php';

/*
 * `inventaire:prune-imports`, run every hour: the imports that expired go with their files, the ones whose worker was lost are given
 * up on, and the scratch files of a worker that was stopped are cleared.
 *
 * "Lost" is judged where the import is stuck: one that waits is judged on the day it was started, one that runs on the day it began,
 * so that a backlog of the queue is not a failure.
 */

uses(RefreshDatabase::class);

/**
 * Imports in every state: one of each that is over, and an old and a recent of those that never ended.
 *
 * @return array<string, mixed>
 */
function pruneImportsCase(): array
{
    $case = importCase();
    config(['inventories.import_stale_minutes' => 60]);

    $case['fresh'] = importWithFile($case['inventory'], $case['team']['owner'], ['expires_at' => now()->addHour()]);
    $case['expiredFile'] = importWithFile($case['inventory'], $case['team']['owner'], ['expires_at' => now()->subMinute()]);
    $case['expiredReport'] = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Completed, 'expires_at' => now()->subHour()]);
    $case['expiredFailure'] = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Failed, 'error' => 'invalid_rows', 'expires_at' => now()->subHour()]);
    $case['stalePending'] = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Pending, 'expires_at' => null, 'started_at' => now()->subMinutes(61)]);
    $case['staleRunning'] = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Running, 'expires_at' => null, 'started_at' => now()->subMinutes(61), 'created_at' => now()->subMinutes(62)]);
    $case['young'] = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Running, 'expires_at' => null, 'started_at' => now()->subMinutes(10)]);
    $case['waiting'] = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Pending, 'expires_at' => null, 'started_at' => now()->subMinutes(59)]);

    return $case;
}

describe('the imports that expired', function (): void {
    it('are deleted with their files, whatever their state, and the others are left alone', function (): void {
        $case = pruneImportsCase();

        $result = app(ImportPruner::class)->prune();

        expect($result['expired'])->toBe(3)
            ->and(Import::query()->whereKey($case['expiredFile']->id)->exists())->toBeFalse()
            ->and(Import::query()->whereKey($case['expiredReport']->id)->exists())->toBeFalse()
            ->and(Import::query()->whereKey($case['expiredFailure']->id)->exists())->toBeFalse()
            ->and(Import::query()->whereKey($case['fresh']->id)->exists())->toBeTrue()
            ->and(collect(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->contains($case['expiredFile']->path))->toBeFalse()
            ->and(collect(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->contains($case['fresh']->path))->toBeTrue();
    });

    it('queue the deletion of the folder of every import they delete, whether or not the row still says where its file is', function (): void {
        Queue::fake([PurgeStoredFiles::class]);
        $case = importCase();
        $upload = importWithFile($case['inventory'], $case['team']['owner'], ['expires_at' => now()->subMinute()]);
        $report = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Completed, 'expires_at' => now()->subMinute()]);
        $failure = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Failed, 'error' => 'invalid_rows', 'expires_at' => now()->subMinute()]);
        $directories = collect([$upload, $report, $failure])->map(fn (Import $import): string => dirname((string) $import->path))->all();
        $report->forceFill(['path' => null])->save();
        $failure->forceFill(['path' => null])->save();

        $result = app(ImportPruner::class)->prune();

        expect($result['expired'])->toBe(3);
        Queue::assertPushed(PurgeStoredFiles::class, 3);

        foreach ($directories as $directory) {
            Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [$directory]);
        }
    });

    it('purge the file that a worker left when it stopped after recording that the import ended and before deleting it', function (ImportStatus $status): void {
        $case = importCase();
        $import = importWithFile($case['inventory'], $case['team']['owner'], ['status' => $status, 'error' => $status === ImportStatus::Failed ? 'invalid_rows' : null, 'expires_at' => now()->subMinute()]);
        // The transaction that ended the import emptied `path`; the file was still to be deleted.
        $import->forceFill(['path' => null])->save();
        expect(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->toHaveCount(1);

        app(ImportPruner::class)->prune();

        expect(Import::query()->whereKey($import->id)->exists())->toBeFalse()
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->toBe([]);
    })->with([ImportStatus::Completed, ImportStatus::Failed]);

    it('purge the folder of an upload whose row never got its path, found by the identifier of the import', function (): void {
        $case = importCase();
        // The process died after the row and before the path was recorded: the file may be on the disk, and the row does not say where.
        $import = importWithFile($case['inventory'], $case['team']['owner'], ['expires_at' => now()->subMinute()]);
        $import->forceFill(['path' => null])->save();

        app(ImportPruner::class)->prune();

        expect(Import::query()->whereKey($import->id)->exists())->toBeFalse()
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->toBe([]);
    });

    it('go as soon as their time is up, not before', function (): void {
        $case = pruneImportsCase();
        $this->travelTo($case['fresh']->expires_at->copy()->subSecond());

        app(ImportPruner::class)->prune();

        expect(Import::query()->whereKey($case['fresh']->id)->exists())->toBeTrue();

        $this->travelTo($case['fresh']->expires_at);
        app(ImportPruner::class)->prune();

        expect(Import::query()->whereKey($case['fresh']->id)->exists())->toBeFalse();
    });

    it('are kept as long as the time the configuration says after they ended', function (): void {
        $case = importCase();
        config(['inventories.import_ttl_hours' => 3]);
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $import = importDone(importAsked($this, $case, importFile("Name\r\na\r\n"), [['column' => 0, 'target' => 'field', 'field' => $name->ulid]]));

        $this->travelTo(now()->addHours(2));
        app(ImportPruner::class)->prune();
        expect(Import::query()->whereKey($import->id)->exists())->toBeTrue();

        $this->travelTo(now()->addHours(2));
        app(ImportPruner::class)->prune();
        expect(Import::query()->whereKey($import->id)->exists())->toBeFalse();
    });
});

describe('the imports that never ended', function (): void {
    it('are given up on when older than the stale delay: failed, with a code, their file deleted and an end to their life', function (): void {
        $case = pruneImportsCase();
        $this->travelTo(now()->startOfSecond());

        $result = app(ImportPruner::class)->prune();

        foreach (['stalePending', 'staleRunning'] as $key) {
            $import = $case[$key]->fresh();

            expect($import->status)->toBe(ImportStatus::Failed)
                ->and($import->error)->toBe(ImportError::Stale->value)
                ->and($import->failure()?->message())->toBeString()
                ->and($import->path)->toBeNull()
                ->and($import->completed_at)->not->toBeNull()
                ->and($import->expires_at?->equalTo(now()->addHours(24)))->toBeTrue()
                ->and(Storage::disk(ATTACHMENT_TEST_DISK)->exists(dirname((string) $case[$key]->path)))->toBeFalse();
        }

        expect($result['stale'])->toBe(2);
    });

    it('are left alone while they are younger than that', function (): void {
        $case = pruneImportsCase();

        app(ImportPruner::class)->prune();

        expect($case['young']->fresh()->status)->toBe(ImportStatus::Running)
            ->and($case['waiting']->fresh()->status)->toBe(ImportStatus::Pending)
            ->and($case['fresh']->fresh()->status)->toBe(ImportStatus::Uploaded);
    });

    it('wait for the delay the configuration says, when they are waiting for a worker', function (): void {
        $case = pruneImportsCase();
        config(['inventories.import_stale_minutes' => 5]);

        app(ImportPruner::class)->prune();

        expect($case['waiting']->fresh()->status)->toBe(ImportStatus::Failed)
            // Running is judged on the time a job may run, not on that delay.
            ->and($case['young']->fresh()->status)->toBe(ImportStatus::Running);
    });

    it('are judged on the day they were started, not on the day the file was sent', function (): void {
        $case = pruneImportsCase();
        // Sent three hours ago, started a minute ago: the person took their time, nothing is wrong.
        $late = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Pending, 'expires_at' => null, 'created_at' => now()->subHours(3), 'started_at' => now()->subMinute()]);
        // A row written without its start is judged on the day it was sent.
        $unknown = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Pending, 'expires_at' => null, 'created_at' => now()->subHours(3), 'started_at' => null]);

        app(ImportPruner::class)->prune();

        expect($late->fresh()->status)->toBe(ImportStatus::Pending)->and($unknown->fresh()->status)->toBe(ImportStatus::Failed);
    });

    it('are given up on once running for longer than a job may run, with a margin', function (): void {
        $case = pruneImportsCase();
        $limit = GenerateExport::TIMEOUT + ImportPruner::RUNNING_MARGIN_SECONDS;
        $this->travelTo(now()->startOfSecond());
        $inTime = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Running, 'expires_at' => null, 'started_at' => now()->subSeconds($limit - 5)]);
        $late = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Running, 'expires_at' => null, 'started_at' => now()->subSeconds($limit + 5)]);
        $unknownYoung = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Running, 'expires_at' => null, 'started_at' => null, 'created_at' => now()->subMinute()]);
        $unknownOld = importWithFile($case['inventory'], $case['team']['owner'], ['status' => ImportStatus::Running, 'expires_at' => null, 'started_at' => null, 'created_at' => now()->subHours(2)]);

        app(ImportPruner::class)->prune();

        expect($limit)->toBe(1200)
            ->and($inTime->fresh()->status)->toBe(ImportStatus::Running)
            ->and($late->fresh()->status)->toBe(ImportStatus::Failed)
            ->and($late->fresh()->error)->toBe(ImportError::Stale->value)
            ->and($unknownYoung->fresh()->status)->toBe(ImportStatus::Running)
            ->and($unknownOld->fresh()->status)->toBe(ImportStatus::Failed);
    });

    it('are not undone by a worker that wakes up', function (): void {
        $case = pruneImportsCase();

        app(ImportPruner::class)->prune();
        app(ImportRunner::class)->run($case['stalePending']->fresh());

        expect($case['stalePending']->fresh()->status)->toBe(ImportStatus::Failed)->and(Item::query()->count())->toBe(0);
    });

    it('do not undo an import that completed meanwhile', function (): void {
        $case = pruneImportsCase();
        $done = $case['stalePending'];
        $stale = Import::query()->findOrFail($done->id);
        $done->forceFill(['status' => ImportStatus::Completed, 'path' => null, 'expires_at' => now()->addDay()])->save();

        app(ImportRunner::class)->fail($stale, ImportError::Stale);

        expect($done->fresh()->status)->toBe(ImportStatus::Completed)->and($done->fresh()->error)->toBeNull();
    });
});

describe('the command', function (): void {
    it('prunes, says so, and is safe to run again', function (): void {
        pruneImportsCase();

        $this->artisan('inventaire:prune-imports')
            ->expectsOutputToContain('3 expired import(s) deleted, 2 stuck import(s) given up on')
            ->assertSuccessful();

        $this->artisan('inventaire:prune-imports')
            ->expectsOutputToContain('0 expired import(s) deleted, 0 stuck import(s) given up on')
            ->assertSuccessful();
    });

    it('is scheduled every hour, without running twice at once', function (): void {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'inventaire:prune-imports'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            ->and($events->first()->expiresAt)->toBe(30);
    });
});

describe('the scratch files', function (): void {
    it('are deleted when an hour old, the recent ones are not', function (): void {
        $directory = ExportTemp::directory('imports');
        $old = $directory.'/import-prune-old';
        $recent = $directory.'/import-prune-recent';
        $folder = $directory.'/prune-old-folder';
        file_put_contents($old, 'x');
        file_put_contents($recent, 'x');
        mkdir($folder);
        file_put_contents($folder.'/inside', 'x');
        touch($old, time() - 90000);
        touch($folder, time() - 90000);

        $deleted = ExportTemp::prune('imports');

        expect($deleted)->toBeGreaterThanOrEqual(2)
            ->and(file_exists($old))->toBeFalse()
            ->and(is_dir($folder))->toBeFalse()
            ->and(file_exists($recent))->toBeTrue();

        unlink($recent);
    });

    it('are those of the imports, apart from the ones of the exports', function (): void {
        $exports = ExportTemp::directory();
        $imports = ExportTemp::directory('imports');
        $export = $exports.'/export-prune-apart';
        $import = $imports.'/import-prune-apart';
        file_put_contents($export, 'x');
        file_put_contents($import, 'x');
        touch($export, time() - 90000);
        touch($import, time() - 90000);

        ExportTemp::prune('imports');

        expect(file_exists($import))->toBeFalse()->and(file_exists($export))->toBeTrue();

        unlink($export);
    });

    it('are cleared by the pruner', function (): void {
        $old = ExportTemp::directory('imports').'/import-pruner-old';
        file_put_contents($old, 'x');
        touch($old, time() - 90000);

        $result = app(ImportPruner::class)->prune();

        expect($result['scratch'])->toBeGreaterThanOrEqual(1)->and(file_exists($old))->toBeFalse();
    });
});
