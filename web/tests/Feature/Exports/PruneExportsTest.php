<?php

declare(strict_types=1);

use App\Inventories\Exports\ExportError;
use App\Inventories\Exports\ExportGenerator;
use App\Inventories\Exports\ExportPruner;
use App\Inventories\Exports\ExportStatus;
use App\Inventories\Exports\ExportTemp;
use App\Jobs\GenerateExport;
use App\Models\Export;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/exports.php';

/*
 * `inventaire:prune-exports`, run every hour: the exports that expired go with their files, the ones whose worker was lost are given
 * up on, and the scratch files of a worker that was stopped are cleared.
 *
 * "Lost" is judged where the export is stuck: one that waits is judged on the day it was asked for, one that runs on the day it
 * started, so that a backlog of the queue (an export that waited for hours, then started a minute ago) is not a failure.
 */

uses(RefreshDatabase::class);

/**
 * Three people's worth of exports in every state: one of each, and an old and a recent of those that never ended.
 *
 * @return array<string, mixed>
 */
function pruneCase(): array
{
    attachmentDisk();
    config(['inventories.export_stale_minutes' => 60]);
    $team = inventoryTeam();
    $inventory = inventoryIn($team['workspace']);
    $owner = $team['owner'];

    $case = compact('team', 'inventory');
    $case['fresh'] = exportStored(exportRow($inventory, $owner), 'fresh.xlsx');
    $case['expired'] = exportStored(exportRow($inventory, $owner), 'expired.xlsx');
    $case['expired']->forceFill(['expires_at' => now()->subMinute()])->save();
    $case['expiredFailure'] = exportRow($inventory, $owner, ['status' => ExportStatus::Failed, 'error' => ExportError::GenerationFailed->value, 'expires_at' => now()->subHour()]);
    $case['stalePending'] = exportRow($inventory, $owner, ['created_at' => now()->subMinutes(61)]);
    $case['staleRunning'] = exportRow($inventory, $owner, ['status' => ExportStatus::Running, 'started_at' => now()->subMinutes(61), 'created_at' => now()->subMinutes(62)]);
    $case['young'] = exportRow($inventory, $owner, ['status' => ExportStatus::Running, 'created_at' => now()->subMinutes(10)]);
    $case['waiting'] = exportRow($inventory, $owner, ['created_at' => now()->subMinutes(59)]);

    // A worker that died left part of a file in the folder of the stuck ones.
    Storage::disk(ATTACHMENT_TEST_DISK)->put(exportFolder($case['staleRunning']).'/partial.csv', 'half');

    return $case;
}

describe('the exports that expired', function (): void {
    it('are deleted with their files, and the others are left alone', function (): void {
        $case = pruneCase();

        $result = app(ExportPruner::class)->prune();

        expect($result['expired'])->toBe(2)
            ->and(Export::query()->whereKey($case['expired']->id)->exists())->toBeFalse()
            ->and(Export::query()->whereKey($case['expiredFailure']->id)->exists())->toBeFalse()
            ->and(Export::query()->whereKey($case['fresh']->id)->exists())->toBeTrue()
            ->and(storedFiles())->toBe([$case['fresh']->path]);
    });

    it('go as soon as their time is up, not before', function (): void {
        $case = pruneCase();
        $this->travelTo($case['fresh']->expires_at->copy()->subSecond());

        app(ExportPruner::class)->prune();

        expect(Export::query()->whereKey($case['fresh']->id)->exists())->toBeTrue();

        $this->travelTo($case['fresh']->expires_at);
        app(ExportPruner::class)->prune();

        expect(Export::query()->whereKey($case['fresh']->id)->exists())->toBeFalse()
            ->and(storedFiles())->toBe([]);
    });
});

describe('the exports that never ended', function (): void {
    it('are given up on when older than the stale delay: failed, with a code, and their folder cleared', function (): void {
        $case = pruneCase();
        $this->travelTo(now()->startOfSecond());

        $result = app(ExportPruner::class)->prune();

        foreach (['stalePending', 'staleRunning'] as $key) {
            $export = $case[$key]->fresh();

            expect($export->status)->toBe(ExportStatus::Failed)
                ->and($export->error)->toBe(ExportError::Stale->value)
                ->and($export->failure()?->message())->toBeString()
                ->and($export->completed_at)->not->toBeNull()
                ->and($export->expires_at?->equalTo(now()->addHours(24)))->toBeTrue();
        }

        expect($result['stale'])->toBe(2)
            ->and(storedFiles())->not->toContain(exportFolder($case['staleRunning']).'/partial.csv');
    });

    it('are left alone while they are younger than that', function (): void {
        $case = pruneCase();

        app(ExportPruner::class)->prune();

        expect($case['young']->fresh()->status)->toBe(ExportStatus::Running)
            ->and($case['waiting']->fresh()->status)->toBe(ExportStatus::Pending)
            ->and($case['fresh']->fresh()->status)->toBe(ExportStatus::Completed);
    });

    it('wait for the delay the configuration says, when they are waiting for a worker', function (): void {
        $case = pruneCase();
        config(['inventories.export_stale_minutes' => 5]);

        app(ExportPruner::class)->prune();

        expect($case['waiting']->fresh()->status)->toBe(ExportStatus::Failed)
            // Running is judged on the time a job may run, not on that delay.
            ->and($case['young']->fresh()->status)->toBe(ExportStatus::Running);
    });

    it('are judged on their start when they are running: one that waited in a backlog and just started is left to finish', function (): void {
        $case = pruneCase();
        $inventory = $case['inventory'];
        $owner = $case['team']['owner'];
        // Asked for three hours ago, started a minute ago: the queue was busy, nothing is wrong.
        $backlog = exportRow($inventory, $owner, ['status' => ExportStatus::Running, 'created_at' => now()->subHours(3), 'started_at' => now()->subMinute()]);

        app(ExportPruner::class)->prune();

        expect($backlog->fresh()->status)->toBe(ExportStatus::Running)
            ->and($backlog->fresh()->error)->toBeNull();
    });

    it('are given up on once running for longer than a job may run, with a margin', function (): void {
        $case = pruneCase();
        $inventory = $case['inventory'];
        $owner = $case['team']['owner'];
        $limit = GenerateExport::TIMEOUT + ExportPruner::RUNNING_MARGIN_SECONDS;
        $this->travelTo(now()->startOfSecond());
        $inTime = exportRow($inventory, $owner, ['status' => ExportStatus::Running, 'started_at' => now()->subSeconds($limit - 5)]);
        $late = exportRow($inventory, $owner, ['status' => ExportStatus::Running, 'started_at' => now()->subSeconds($limit + 5)]);
        // A row without its start (written by hand) is judged on the day it was asked for.
        $unknownYoung = exportRow($inventory, $owner, ['status' => ExportStatus::Running, 'started_at' => null, 'created_at' => now()->subMinute()]);
        $unknownOld = exportRow($inventory, $owner, ['status' => ExportStatus::Running, 'started_at' => null, 'created_at' => now()->subHours(2)]);

        app(ExportPruner::class)->prune();

        expect($limit)->toBe(1200)
            ->and($inTime->fresh()->status)->toBe(ExportStatus::Running)
            ->and($late->fresh()->status)->toBe(ExportStatus::Failed)
            ->and($late->fresh()->error)->toBe(ExportError::Stale->value)
            ->and($unknownYoung->fresh()->status)->toBe(ExportStatus::Running)
            ->and($unknownOld->fresh()->status)->toBe(ExportStatus::Failed);
    });

    it('are given up on when pending for longer than the delay, however long ago the others started', function (): void {
        $case = pruneCase();
        $stuck = exportRow($case['inventory'], $case['team']['owner'], ['created_at' => now()->subHours(3)]);
        // Something is running right now: the queue works, but this one has waited far too long all the same.
        exportRow($case['inventory'], $case['team']['owner'], ['status' => ExportStatus::Running, 'started_at' => now()->subSeconds(30)]);

        app(ExportPruner::class)->prune();

        expect($stuck->fresh()->status)->toBe(ExportStatus::Failed);
    });

    it('are not undone by a worker that wakes up: the file it was writing is not kept', function (): void {
        $case = pruneCase();

        app(ExportPruner::class)->prune();

        // The worker of the stuck export finishes now: it finds the export failed, and stores nothing that stays.
        app(ExportGenerator::class)->run($case['stalePending']->fresh());

        expect($case['stalePending']->fresh()->status)->toBe(ExportStatus::Failed)
            ->and($case['stalePending']->fresh()->path)->toBeNull();
    });
});

describe('the command', function (): void {
    it('prunes, says so, and is safe to run again', function (): void {
        pruneCase();

        $this->artisan('inventaire:prune-exports')
            ->expectsOutputToContain('2 expired export(s) deleted, 2 stuck export(s) given up on')
            ->assertSuccessful();

        $this->artisan('inventaire:prune-exports')
            ->expectsOutputToContain('0 expired export(s) deleted, 0 stuck export(s) given up on')
            ->assertSuccessful();
    });

    it('does not touch the exports of anyone who is not expired', function (): void {
        $case = pruneCase();
        $other = exportStored(exportRow(inventoryIn($case['team']['workspace'], 'Other'), $case['team']['viewer']), 'viewer.csv');

        $this->artisan('inventaire:prune-exports')->assertSuccessful();

        expect($other->fresh())->not->toBeNull()
            ->and(storedFiles())->toContain($other->path);
    });

    it('is scheduled every hour, without running twice at once', function (): void {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'inventaire:prune-exports'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            // The lock of a run that was killed is forgotten after 30 minutes: the default is a day, a day without pruning.
            ->and($events->first()->expiresAt)->toBe(30);
    });
});

describe('the scratch files', function (): void {
    it('are deleted when an hour old, the recent ones are not', function (): void {
        $directory = ExportTemp::directory();
        $old = $directory.'/export-prune-old';
        $recent = $directory.'/export-prune-recent';
        $folder = $directory.'/prune-old-folder';
        file_put_contents($old, 'x');
        file_put_contents($recent, 'x');
        mkdir($folder);
        file_put_contents($folder.'/inside', 'x');
        touch($old, time() - 90000);
        touch($folder, time() - 90000);

        $deleted = ExportTemp::prune();

        expect($deleted)->toBeGreaterThanOrEqual(2)
            ->and(file_exists($old))->toBeFalse()
            ->and(is_dir($folder))->toBeFalse()
            ->and(file_exists($recent))->toBeTrue();

        unlink($recent);
    });

    it('are kept for an hour by default: more than an export may run, and no more', function (): void {
        $directory = ExportTemp::directory();
        $stale = $directory.'/export-prune-stale';
        $running = $directory.'/export-prune-running';
        file_put_contents($stale, 'x');
        file_put_contents($running, 'x');
        touch($stale, time() - 3700);
        touch($running, time() - 3500);

        ExportTemp::prune();

        expect(file_exists($stale))->toBeFalse()
            ->and(file_exists($running))->toBeTrue()
            // Longer than the 15 minutes a job runs for.
            ->and(3600)->toBeGreaterThan(GenerateExport::TIMEOUT);

        unlink($running);
    });

    it('skip a file that vanished since the listing instead of failing', function (): void {
        $directory = ExportTemp::directory();
        $dangling = $directory.'/export-prune-dangling';
        symlink($directory.'/nowhere', $dangling);

        // No warning, so no exception: a link whose target is gone has no age.
        expect(fn () => ExportTemp::prune())->not->toThrow(Throwable::class)
            ->and(is_link($dangling))->toBeTrue();

        unlink($dangling);
    });

    it('are cleared by the command too', function (): void {
        pruneCase();
        $old = ExportTemp::directory().'/export-prune-command';
        file_put_contents($old, 'x');
        touch($old, time() - 90000);

        $this->artisan('inventaire:prune-exports')->assertSuccessful();

        expect(file_exists($old))->toBeFalse();
    });
});
