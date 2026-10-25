<?php

declare(strict_types=1);

use App\Models\PhotoAnalysis;
use App\PhotoEntry\PhotoAnalysisError;
use App\PhotoEntry\PhotoAnalysisPruner;
use App\PhotoEntry\PhotoAnalysisRunner;
use App\PhotoEntry\PhotoAnalysisStatus;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/photo-entry.php';

/*
 * `inventaire:prune-photo-analyses`, run every hour: the analyses that expired go with their photos, the ones whose worker was lost are
 * given up on (and their photos deleted).
 *
 * "Lost" is judged where the analysis is stuck: one that waits on the day it was received, one that runs on the day it began.
 */

uses(RefreshDatabase::class);

/**
 * Analyses in every state: some that are over, and an old and a recent of those that never ended.
 *
 * @return array<string, mixed>
 */
function prunePhotoCase(): array
{
    $case = photoCase();
    $owner = $case['team']['owner'];
    $inventory = $case['inventory'];

    $case['fresh'] = photoAnalysisWithFiles($inventory, $owner, ['expires_at' => now()->addHour()]);
    $case['expired'] = photoAnalysisWithFiles($inventory, $owner, ['expires_at' => now()->subMinute()]);
    $case['expiredReport'] = photoAnalysisWithFiles($inventory, $owner, ['status' => PhotoAnalysisStatus::Completed, 'expires_at' => now()->subHour()]);
    $case['expiredFailure'] = photoAnalysisWithFiles($inventory, $owner, ['status' => PhotoAnalysisStatus::Failed, 'error' => 'unavailable', 'expires_at' => now()->subHour()]);
    $case['stalePending'] = photoAnalysisWithFiles($inventory, $owner, ['created_at' => now()->subMinutes(16)]);
    $case['staleRunning'] = photoAnalysisWithFiles($inventory, $owner, ['status' => PhotoAnalysisStatus::Running, 'started_at' => now()->subMinutes(16), 'created_at' => now()->subMinutes(17)]);
    $case['staleNeverStarted'] = photoAnalysisWithFiles($inventory, $owner, ['status' => PhotoAnalysisStatus::Running, 'started_at' => null, 'created_at' => now()->subMinutes(20)]);
    $case['young'] = photoAnalysisWithFiles($inventory, $owner, ['status' => PhotoAnalysisStatus::Running, 'started_at' => now()->subMinutes(10), 'created_at' => now()->subMinutes(30)]);
    $case['waiting'] = photoAnalysisWithFiles($inventory, $owner, ['created_at' => now()->subMinutes(14)]);
    $case['done'] = photoAnalysisWithFiles($inventory, $owner, ['status' => PhotoAnalysisStatus::Completed, 'created_at' => now()->subMinutes(50), 'expires_at' => now()->addHour()], count: 0);

    return $case;
}

describe('the analyses that expired', function (): void {
    it('are deleted with their photos, whatever their state, and the others are left alone', function (): void {
        $case = prunePhotoCase();

        $result = app(PhotoAnalysisPruner::class)->prune();

        expect($result['expired'])->toBe(3);

        foreach (['expired', 'expiredReport', 'expiredFailure'] as $gone) {
            expect(PhotoAnalysis::query()->whereKey($case[$gone]->id)->exists())->toBeFalse();
            expect(Storage::disk(ATTACHMENT_TEST_DISK)->exists(photoFolder($case[$gone]).'/1.jpg'))->toBeFalse();
        }

        foreach (['fresh', 'young', 'waiting', 'done'] as $kept) {
            expect(PhotoAnalysis::query()->whereKey($case[$kept]->id)->exists())->toBeTrue();
        }
    });
});

describe('the analyses that never ended', function (): void {
    it('are given up on after fifteen minutes, with their photos deleted, and a pending one is judged on the day it was received', function (): void {
        $case = prunePhotoCase();

        $result = app(PhotoAnalysisPruner::class)->prune();

        expect($result['stale'])->toBe(3);

        foreach (['stalePending', 'staleRunning', 'staleNeverStarted'] as $name) {
            $analysis = $case[$name]->fresh();

            expect($analysis->status)->toBe(PhotoAnalysisStatus::Failed)
                ->and($analysis->error)->toBe('stale')
                ->and($analysis->completed_at)->not->toBeNull()
                ->and(Storage::disk(ATTACHMENT_TEST_DISK)->exists(photoFolder($analysis).'/1.jpg'))->toBeFalse();
        }

        // A running one is judged on the day it began, not on the day it was received; a waiting one is young.
        expect($case['young']->fresh()->status)->toBe(PhotoAnalysisStatus::Running)
            ->and($case['waiting']->fresh()->status)->toBe(PhotoAnalysisStatus::Pending)
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->exists(photoFolder($case['young']).'/1.jpg'))->toBeTrue()
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->exists(photoFolder($case['waiting']).'/1.jpg'))->toBeTrue();
    });

    it('do not undo an analysis that completed meanwhile', function (): void {
        $case = prunePhotoCase();
        $done = $case['stalePending'];
        $stale = PhotoAnalysis::query()->findOrFail($done->id);
        $done->forceFill(['status' => PhotoAnalysisStatus::Completed, 'proposals' => []])->save();

        app(PhotoAnalysisRunner::class)->fail($stale, PhotoAnalysisError::Stale);

        expect($done->fresh()->status)->toBe(PhotoAnalysisStatus::Completed)->and($done->fresh()->error)->toBeNull();
    });
});

describe('the command', function (): void {
    it('prunes, says so, and is safe to run again', function (): void {
        prunePhotoCase();

        $this->artisan('inventaire:prune-photo-analyses')
            ->expectsOutputToContain('3 expired photo analysis(es) deleted, 3 stuck photo analysis(es) given up on')
            ->assertSuccessful();

        $this->artisan('inventaire:prune-photo-analyses')
            ->expectsOutputToContain('0 expired photo analysis(es) deleted, 0 stuck photo analysis(es) given up on')
            ->assertSuccessful();
    });

    it('is scheduled every hour, without running twice at once', function (): void {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'inventaire:prune-photo-analyses'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            ->and($events->first()->expiresAt)->toBe(30);
    });
});
