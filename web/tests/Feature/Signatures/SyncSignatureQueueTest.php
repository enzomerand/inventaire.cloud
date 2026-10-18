<?php

declare(strict_types=1);

use App\Jobs\SyncSignature;
use App\Signatures\SignatureSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * The job that reads a request again, on a real queue (the database one) and a real worker: what Laravel does with a job that is put back
 * because another of the same version runs, and with one that throws. A release for the overlap counts as a try for the queue, so the
 * job must not be bounded by its tries: a duplicate that waits for a long read, or every job of a version during the minutes a killed
 * worker's lock lasts, would be failed and the event lost until the schedule. Time is moved to let the pauses pass.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['queue.default' => 'database']);
});

/**
 * The key of the lock that keeps two jobs of one version apart.
 */
function overlapLock(int $versionId): string
{
    return 'laravel-queue-overlap:'.SyncSignature::class.':'.$versionId;
}

/**
 * Has a worker take the next job that is due and give it one try.
 */
function workOnce(object $test): void
{
    // The memory of the worker is the one of the whole test run: the default limit of the command (128 MB) would stop it.
    $test->artisan('queue:work', ['--once' => true, '--memory' => 8192])->assertSuccessful();
}

describe('a job that finds another of the same version running', function (): void {
    it('is put back in the queue again and again, never failed for the tries it spent doing so', function (): void {
        $lock = Cache::lock(overlapLock(4242), 3600);
        expect($lock->get())->toBeTrue();
        SyncSignature::dispatch(4242);

        // More times than the three tries it used to have.
        foreach (range(1, 8) as $round) {
            workOnce($this);

            expect(DB::table('failed_jobs')->count())->toBe(0, "failed at the round {$round}")
                ->and(DB::table('jobs')->count())->toBe(1)
                ->and((int) DB::table('jobs')->value('attempts'))->toBe($round);

            $this->travel(31)->seconds();
        }

        // The lock is let go of: the next try runs the job, which has nothing to read (no such version) and is done with.
        $lock->release();
        workOnce($this);

        expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('is failed when it has been kept waiting for more than half an hour, whatever the tries', function (): void {
        $lock = Cache::lock(overlapLock(4243), 7200);
        expect($lock->get())->toBeTrue();
        SyncSignature::dispatch(4243);
        workOnce($this);

        expect(DB::table('jobs')->count())->toBe(1);

        $this->travel(SyncSignature::RETRY_FOR_MINUTES + 1)->minutes();
        workOnce($this);

        expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(1);
    });

    it('does not use up the exceptions of the job: only real ones are counted, three of them failing it', function (): void {
        $lock = Cache::lock(overlapLock(4244), 3600);
        expect($lock->get())->toBeTrue();
        SyncSignature::dispatch(4244);

        foreach (range(1, 5) as $ignored) {
            workOnce($this);
            $this->travel(31)->seconds();
        }

        $lock->release();
        app()->bind(SignatureSync::class, function (): never {
            throw new RuntimeException('Documenso is down.');
        });

        // The releases above did not count: the first two exceptions put the job back in the queue (the pause is long, the queue counted
        // the releases as tries), and the third one fails it.
        foreach ([1, 2] as $exception) {
            workOnce($this);

            expect(DB::table('failed_jobs')->count())->toBe(0, "failed by the exception {$exception}")->and(DB::table('jobs')->count())->toBe(1);

            $this->travel(121)->seconds();
        }

        workOnce($this);

        expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(1);
    });
});

describe('a job that throws', function (): void {
    it('is tried again after a pause, and failed by the third exception', function (): void {
        app()->bind(SignatureSync::class, function (): never {
            throw new RuntimeException('Documenso is down.');
        });
        SyncSignature::dispatch(4245);

        workOnce($this);
        expect(DB::table('failed_jobs')->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(1);

        $this->travel(31)->seconds();
        workOnce($this);
        expect(DB::table('failed_jobs')->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(1);

        $this->travel(121)->seconds();
        workOnce($this);

        expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(1);
    });
});

describe('the settings of the job', function (): void {
    it('has no limit of tries, three exceptions, and half an hour from the moment it is queued', function (): void {
        $job = new SyncSignature(42);

        expect($job->tries)->toBe(0)
            ->and($job->maxExceptions)->toBe(3)
            ->and(SyncSignature::RETRY_FOR_MINUTES)->toBe(30)
            ->and($job->retryUntil()->getTimestamp())->toBeBetween(time() + 30 * 60 - 5, time() + 30 * 60 + 5)
            ->and($job->backoff())->toBe([30, 120]);
    });
});
