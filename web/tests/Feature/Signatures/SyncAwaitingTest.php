<?php

declare(strict_types=1);

use App\Inventories\Versions\SignatureStatus;
use App\Inventories\Versions\VersionMaintenance;
use App\Inventories\Versions\VersionStatus;
use App\Jobs\SyncSignature;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Signatures\SignatureSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/versions.php';

/*
 * The safety net of the webhook, run every ten minutes by `inventaire:sync-versions`: the requests that await their signers and that nobody
 * has looked at for half an hour (or ever) are read again, a hundred at a time, the longest waiting first. A webhook cannot reach an
 * instance behind a NAT or on a private address (Documenso refuses those), and may be lost.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->useLanguage('en');
});

/**
 * A request for signatures in a state, on a bare row (nothing is written to the disk).
 *
 * @param  array<string, mixed>  $attributes
 */
function syncRow(Inventory $inventory, int $number, SignatureStatus $status = SignatureStatus::Awaiting, ?int $syncedMinutesAgo = null, array $attributes = []): InventoryVersion
{
    $version = InventoryVersion::factory()->create(array_merge([
        'inventory_id' => $inventory->id,
        'number' => $number,
        'status' => VersionStatus::Completed,
        'signature_status' => $status,
        'documenso_envelope_id' => "envelope_{$number}",
        'signers' => [['name' => 'Ada', 'email' => 'ada@example.test', 'status' => 'pending', 'signed_at' => null, 'rejection_reason' => null, 'recipient_id' => 1]],
    ], $attributes));

    DB::table('inventory_versions')->where('id', $version->id)->update([
        'signature_synced_at' => $syncedMinutesAgo === null ? null : now()->subMinutes($syncedMinutesAgo),
        'updated_at' => $attributes['updated_at'] ?? now(),
    ]);

    return $version->refresh();
}

/**
 * The versions the jobs of the queue are about, in the order they were queued.
 *
 * @return list<int>
 */
function syncQueued(): array
{
    return Queue::pushed(SyncSignature::class)->map(fn (SyncSignature $job): int => $job->versionId)->values()->all();
}

describe('the requests that await their signers', function (): void {
    it('are read again when nobody has looked at them for more than half an hour, or ever', function (): void {
        $inventory = Inventory::factory()->create();
        $never = syncRow($inventory, 1);
        $old = syncRow($inventory, 2, syncedMinutesAgo: 31);
        $older = syncRow($inventory, 3, syncedMinutesAgo: 600);
        syncRow($inventory, 4, syncedMinutesAgo: 29);
        syncRow($inventory, 5, syncedMinutesAgo: 1);

        $result = app(VersionMaintenance::class)->run();

        expect(syncQueued())->toBe([$never->id, $older->id, $old->id])
            ->and($result['synced'])->toBe(3)
            ->and($result['generation'])->toBe(0)
            ->and($result['requests'])->toBe(0);
    });

    it('are the only ones read: not the ones that are over, not the ones that never started', function (SignatureStatus $status): void {
        $inventory = Inventory::factory()->create();
        syncRow($inventory, 1, $status, 600);

        app(VersionMaintenance::class)->run();

        Queue::assertNotPushed(SyncSignature::class);
    })->with([
        SignatureStatus::Requested,
        SignatureStatus::Completed,
        SignatureStatus::Rejected,
        SignatureStatus::Cancelled,
        SignatureStatus::Failed,
    ]);

    it('are read when they are still being sent long after, with an envelope: the job that sent them is gone', function (): void {
        $inventory = Inventory::factory()->create();
        $stuck = syncRow($inventory, 1, SignatureStatus::Sending, null, ['updated_at' => now()->subMinutes(121)]);
        syncRow($inventory, 2, SignatureStatus::Sending, null, ['updated_at' => now()->subMinutes(119)]);
        syncRow($inventory, 3, SignatureStatus::Sending, null, ['updated_at' => now()->subMinutes(600), 'documenso_envelope_id' => null]);

        app(VersionMaintenance::class)->run();

        expect(syncQueued())->toBe([$stuck->id]);
    });

    it('are read a hundred at a time: the ones nobody ever looked at first, then the longest waiting', function (): void {
        $inventory = Inventory::factory()->create();
        $never = [];
        $stale = [];

        for ($number = 1; $number <= 30; $number++) {
            $never[] = syncRow($inventory, $number)->id;
        }

        // 31 minutes ago for the most recent, 130 for the oldest.
        for ($number = 31; $number <= 130; $number++) {
            $stale[$number] = syncRow($inventory, $number, syncedMinutesAgo: 161 - $number)->id;
        }

        $first = app(VersionMaintenance::class)->syncAwaitingRequests();
        $queued = syncQueued();

        expect($first)->toBe(100)
            ->and($queued)->toHaveCount(100)
            ->and(array_slice($queued, 0, 30))->toBe($never)
            // The oldest of the others, oldest first: the 70 first of them.
            ->and(array_slice($queued, 30))->toBe(array_slice($stale, 0, 70, false));

        // Those were looked at; the next run takes the rest, which waited the least.
        DB::table('inventory_versions')->whereIn('id', $queued)->update(['signature_synced_at' => now()]);
        Queue::fake();

        $second = app(VersionMaintenance::class)->syncAwaitingRequests();

        expect($second)->toBe(30)
            ->and(syncQueued())->toBe(array_slice($stale, 70, null, false));
    });

    it('are not read again while the job that reads them waits in the queue', function (): void {
        $inventory = Inventory::factory()->create();
        syncRow($inventory, 1);
        syncRow($inventory, 2, syncedMinutesAgo: 90);

        app(VersionMaintenance::class)->run();
        app(VersionMaintenance::class)->run();

        // The job is unique per version: a second run of the schedule queues nothing more while they wait.
        Queue::assertPushed(SyncSignature::class, 2);
    });

    it('are said in the output of the command', function (): void {
        $inventory = Inventory::factory()->create();
        syncRow($inventory, 1);
        syncRow($inventory, 2, syncedMinutesAgo: 40);

        $this->artisan('inventaire:sync-versions')
            ->expectsOutputToContain('0 stuck version(s) given up on, 0 unsent signature request(s) given up on, 2 signature request(s) queued to be looked at again.')
            ->assertSuccessful();
    });
});

describe('the safety net', function (): void {
    it('completes a request the webhook never announced', function (): void {
        $made = versionAwaiting(2);
        $made['fake']->complete();
        DB::table('inventory_versions')->where('id', $made['version']->id)->update(['signature_synced_at' => now()->subMinutes(45)]);

        $this->artisan('inventaire:sync-versions')->assertSuccessful();
        Queue::pushed(SyncSignature::class)->each(fn (SyncSignature $job) => $job->handle(app(SignatureSync::class)));

        $done = $made['version']->fresh();

        expect($done->signature_status)->toBe(SignatureStatus::Completed)
            ->and($done->signed_pdf_sha256)->toBe(hash('sha256', $made['fake']->signedPdf));
    });

    it('leaves alone a request that something looked at a moment ago, a webhook for instance', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $before = $made['fake']->calls;

        $this->artisan('inventaire:sync-versions')->assertSuccessful();

        // Just sent: it was looked at when it was handed over, so nothing is queued.
        Queue::assertNotPushed(SyncSignature::class, fn (SyncSignature $job): bool => $job->versionId === $made['version']->id);
        expect($made['fake']->calls)->toBe($before);
    });

    it('looks again, every ten minutes, at a request Documenso keeps failing for, until it answers', function (): void {
        $made = versionAwaiting(1);
        DB::table('inventory_versions')->where('id', $made['version']->id)->update(['signature_synced_at' => now()->subMinutes(45)]);
        $made['fake']->failNext('GET /envelope/{id}', 503);

        app(VersionMaintenance::class)->run();

        try {
            Queue::pushed(SyncSignature::class)->each(fn (SyncSignature $job) => $job->handle(app(SignatureSync::class)));
        } catch (Throwable) {
            // The job fails and the queue would try again; the request was not looked at.
        }

        expect($made['version']->fresh()->signature_synced_at->lessThan(now()->subMinutes(40)))->toBeTrue();

        // The job that failed had released the lock that keeps a second one away when it started.
        Queue::fake();
        Cache::flush();
        app(VersionMaintenance::class)->run();

        expect(syncQueued())->toBe([$made['version']->id]);
    });
});
