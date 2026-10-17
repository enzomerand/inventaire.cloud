<?php

declare(strict_types=1);

use App\Inventories\Versions\SignatureError;
use App\Inventories\Versions\SignatureStatus;
use App\Inventories\Versions\VersionError;
use App\Inventories\Versions\VersionMaintenance;
use App\Inventories\Versions\VersionStatus;
use App\Jobs\CancelDocumensoEnvelopes;
use App\Jobs\PurgeStoredFiles;
use App\Jobs\RequestSignatures;
use App\Models\InventoryVersion;
use App\Signatures\DocumensoClient;
use App\Signatures\SignatureRequests;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/versions.php';

/*
 * `inventaire:sync-versions`, run every ten minutes: the versions whose PDF was never written, and the requests for signatures that were
 * never sent, are given up on, so that the person is told and can start again.
 *
 * "Never" is judged on the last time the row changed: a version that waited in a long queue and was picked up in time is not a failure.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->useLanguage('en');
});

/**
 * Makes a row look as if it had last changed `$minutes` ago (the model sets `updated_at` itself, so it is written below it).
 */
function versionAged(InventoryVersion $version, int $minutes): InventoryVersion
{
    DB::table('inventory_versions')->where('id', $version->id)->update(['updated_at' => now()->subMinutes($minutes)]);

    return $version->refresh();
}

describe('the versions whose PDF was never written', function (): void {
    it('are failed (stale) after an hour, pending or running, and their files purged', function (VersionStatus $status): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        $version->forceFill(['status' => $status])->save();
        versionAged($version, 61);

        $this->artisan('inventaire:sync-versions')->assertSuccessful();

        $version->refresh();

        expect($version->status)->toBe(VersionStatus::Failed)
            ->and($version->error)->toBe(VersionError::Stale)
            ->and($version->counted_bytes)->toBe(0);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [$version->directory()]);
    })->with([VersionStatus::Pending, VersionStatus::Running]);

    it('are left alone before the hour is up, whatever the age of the version', function (VersionStatus $status): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        // Frozen a day ago, picked up by a worker 59 minutes ago: it is running in time.
        $version->forceFill(['status' => $status, 'created_at' => now()->subDay()])->save();
        versionAged($version, 59);

        $this->artisan('inventaire:sync-versions')->assertSuccessful();

        expect($version->refresh()->status)->toBe($status);
        Queue::assertNotPushed(PurgeStoredFiles::class);
    })->with([VersionStatus::Pending, VersionStatus::Running]);

    it('are not touched when they are over: completed, or failed already', function (): void {
        $case = versionTableCase();
        $done = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        $failed = InventoryVersion::factory()->create(['inventory_id' => $case['inventory']->id, 'number' => 5, 'status' => VersionStatus::Failed, 'error' => VersionError::GenerationFailed]);
        versionAged($done, 600);
        versionAged($failed, 600);

        $this->artisan('inventaire:sync-versions')->assertSuccessful();

        expect($done->refresh()->status)->toBe(VersionStatus::Completed)
            ->and($done->counted_bytes)->toBeGreaterThan(0)
            ->and($failed->refresh()->error)->toBe(VersionError::GenerationFailed);
    });

    it('find the job done when it turns up late: it does nothing, the version stays failed', function (): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        versionAged($version, 90);

        app(VersionMaintenance::class)->run();
        $late = versionRun($version);

        expect($late->status)->toBe(VersionStatus::Failed)
            ->and($late->error)->toBe(VersionError::Stale)
            ->and($late->pdf_path)->toBeNull();
    });

    it('are shown to the person as failed, with the reason in their language', function (): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        versionAged($version, 90);

        app(VersionMaintenance::class)->run();

        $shown = inventoryCall($this, $case['team']['owner'], 'GET', "/api/v1/inventories/{$case['inventory']->ulid}/versions/{$version->ulid}")->assertOk();

        expect($shown->json('data.status'))->toBe('failed')
            ->and($shown->json('data.error'))->toBe('This version did not finish and was cancelled. Delete it and freeze the inventory again.');
    });
});

describe('the requests for signatures that were never sent', function (): void {
    it('are failed (stale) after two hours, asked for or being sent, when no envelope exists', function (SignatureStatus $status): void {
        versionDocumenso();
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1)));
        $version->forceFill(['signature_status' => $status])->save();
        versionAged($version, 121);

        $this->artisan('inventaire:sync-versions')->assertSuccessful();

        $version->refresh();

        expect($version->signature_status)->toBe(SignatureStatus::Failed)
            ->and($version->signature_error)->toBe(SignatureError::Stale)
            // The version itself is fine: only the request failed, and can be sent again.
            ->and($version->status)->toBe(VersionStatus::Completed)
            ->and($version->documenso_envelope_id)->toBeNull();
    })->with([SignatureStatus::Requested, SignatureStatus::Sending]);

    it('are left alone before two hours, and when they are beyond sending', function (): void {
        versionDocumenso();
        $case = versionTableCase();
        $make = function (SignatureStatus $status, ?string $envelope, int $minutes) use ($case): InventoryVersion {
            $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1)));
            $version->forceFill(['signature_status' => $status, 'documenso_envelope_id' => $envelope])->save();

            return versionAged($version, $minutes);
        };
        $recent = $make(SignatureStatus::Requested, null, 119);
        $sending = $make(SignatureStatus::Sending, 'envelope_1', 119);
        $awaiting = $make(SignatureStatus::Awaiting, null, 600);
        $cancelled = $make(SignatureStatus::Cancelled, null, 600);
        $none = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        versionAged($none, 600);

        $this->artisan('inventaire:sync-versions')->assertSuccessful();

        expect($recent->refresh()->signature_status)->toBe(SignatureStatus::Requested)
            ->and($sending->refresh()->signature_status)->toBe(SignatureStatus::Sending)
            ->and($sending->documenso_envelope_id)->toBe('envelope_1')
            ->and($awaiting->refresh()->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($cancelled->refresh()->signature_status)->toBe(SignatureStatus::Cancelled)
            ->and($none->refresh()->signature_status)->toBeNull();
    });

    it('tell the person why in their language, and offer to send the request again', function (): void {
        versionDocumenso();
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1)));
        versionAged($version, 130);

        app(VersionMaintenance::class)->run();

        $uri = "/api/v1/inventories/{$case['inventory']->ulid}/versions/{$version->ulid}";
        $english = inventoryCall($this, $case['team']['editor'], 'GET', $uri)->assertOk();
        $french = inventoryCall($this, $case['team']['editor'], 'GET', $uri, headers: ['Accept-Language' => 'fr'])->assertOk();

        expect($english->json('data.signature.status'))->toBe('failed')
            ->and($english->json('data.signature.error'))->toBe('The signature request did not finish and was cancelled. Try again.')
            ->and($french->json('data.signature.error'))->toBe('La demande de signature ne s\'est pas terminée et a été annulée. Réessayez.')
            ->and($english->json('data.abilities.retry_signature'))->toBeTrue();
    });
});

describe('the requests for signatures that are stuck with an envelope', function (): void {
    /**
     * A request that has an envelope at Documenso and has not moved for `$minutes`.
     */
    function stuckRequest(object $test, SignatureStatus $status, int $minutes, string $envelope = 'envelope_stuck'): InventoryVersion
    {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1)));
        $version->forceFill(['signature_status' => $status, 'documenso_envelope_id' => $envelope])->save();
        Queue::fake();

        return versionAged($version, $minutes);
    }

    it('are failed (stale) after two hours, asked for or being sent: nothing a read of the envelope did got them anywhere', function (SignatureStatus $status): void {
        versionDocumenso();
        $version = stuckRequest($this, $status, 121);

        $this->artisan('inventaire:sync-versions')->assertSuccessful();

        $version->refresh();

        expect($version->signature_status)->toBe(SignatureStatus::Failed)
            ->and($version->signature_error)->toBe(SignatureError::Stale)
            // The envelope is let go of: sending the request again makes a new one.
            ->and($version->documenso_envelope_id)->toBeNull()
            ->and($version->status)->toBe(VersionStatus::Completed);
    })->with([SignatureStatus::Requested, SignatureStatus::Sending]);

    it('have their envelope deleted if it is a draft and cancelled if it is pending, in the background', function (): void {
        versionDocumenso();
        $version = stuckRequest($this, SignatureStatus::Sending, 130);

        app(VersionMaintenance::class)->run();

        Queue::assertPushed(CancelDocumensoEnvelopes::class, 1);
        Queue::assertPushed(CancelDocumensoEnvelopes::class, fn (CancelDocumensoEnvelopes $job): bool => $job->envelopeIds === ['envelope_stuck'] && $job->deleteDrafts === true);
        expect($version->refresh()->signature_status)->toBe(SignatureStatus::Failed);
    });

    it('go through the cleanup job: the draft is deleted at Documenso, a pending envelope is cancelled, one that is over is left alone', function (): void {
        $fake = versionDocumenso();
        $fake->other('envelope_draft', 'DRAFT', null)->other('envelope_pending', 'PENDING', null)->other('envelope_done', 'COMPLETED', null);
        $case = versionTableCase();

        foreach (['envelope_draft', 'envelope_pending', 'envelope_done'] as $envelope) {
            $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1)));
            $version->forceFill(['signature_status' => SignatureStatus::Sending, 'documenso_envelope_id' => $envelope])->save();
            versionAged($version, 130);
        }

        Queue::fake();
        app(VersionMaintenance::class)->run();
        Queue::pushed(CancelDocumensoEnvelopes::class)->each(fn (CancelDocumensoEnvelopes $job) => $job->handle(app(DocumensoClient::class)));

        expect(array_keys($fake->others))->toBe(['envelope_pending', 'envelope_done'])
            ->and($fake->others['envelope_pending']['status'])->toBe('CANCELLED')
            ->and($fake->others['envelope_done']['status'])->toBe('COMPLETED');
    });

    it('can be sent again afterwards, with a new envelope', function (): void {
        $fake = versionDocumenso();
        $version = stuckRequest($this, SignatureStatus::Sending, 130);
        app(VersionMaintenance::class)->run();
        $case = ['owner' => $version->inventory->workspace->members()->wherePivot('role', 'owner')->first()];

        $retried = inventoryCall($this, $case['owner'], 'POST', "/api/v1/inventories/{$version->inventory->ulid}/versions/{$version->ulid}/signature/retry")->assertStatus(202);
        Queue::pushed(RequestSignatures::class)->each(fn (RequestSignatures $job) => $job->handle(app(SignatureRequests::class)));

        expect($retried->json('data.signature.status'))->toBe('requested')
            // The envelope that was let go of was never made by the fake: the one this try makes is the only one.
            ->and($fake->count('POST /envelope/create'))->toBe(1)
            ->and($version->fresh()->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($version->fresh()->documenso_envelope_id)->not->toBe('envelope_stuck');
    });

    it('are given up on whatever the reads did meanwhile: reading is not moving', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->status = 'DRAFT';
        $version = $made['version'];
        $version->forceFill(['signature_status' => SignatureStatus::Sending])->save();
        versionAged($version, 200);

        // A webhook or a schedule that reads the draft again and again does not keep it alive.
        versionSync($version);
        versionSync($version);
        Queue::fake();

        $result = app(VersionMaintenance::class)->run();

        expect($result['requests'])->toBe(1)
            ->and($version->refresh()->signature_status)->toBe(SignatureStatus::Failed)
            ->and($version->signature_error)->toBe(SignatureError::Stale);
    });

    it('are left alone, with their envelope, when they have moved meanwhile or are not asked for or being sent', function (SignatureStatus $status, int $minutes): void {
        versionDocumenso();
        $version = stuckRequest($this, $status, $minutes);

        $this->artisan('inventaire:sync-versions')->assertSuccessful();

        expect($version->refresh()->signature_status)->toBe($status)
            ->and($version->documenso_envelope_id)->toBe('envelope_stuck');
        Queue::assertNotPushed(CancelDocumensoEnvelopes::class);
    })->with([
        'sending, a moment ago' => [SignatureStatus::Sending, 119],
        'awaiting, long ago' => [SignatureStatus::Awaiting, 600],
        'failed, long ago' => [SignatureStatus::Failed, 600],
        'completed, long ago' => [SignatureStatus::Completed, 600],
    ]);

    it('are counted with the requests that were never sent, in the output of the command', function (): void {
        versionDocumenso();
        stuckRequest($this, SignatureStatus::Sending, 130);

        $this->artisan('inventaire:sync-versions')
            ->expectsOutputToContain('0 stuck version(s) given up on, 1 unsent signature request(s) given up on, 0 signature request(s) queued to be looked at again.')
            ->assertSuccessful();
    });

    it('queue nothing for a deletion that is rolled back with them', function (): void {
        versionDocumenso();
        $version = stuckRequest($this, SignatureStatus::Sending, 130);

        try {
            DB::transaction(function (): void {
                app(VersionMaintenance::class)->giveUpOnStuckRequests();

                throw new RuntimeException('The caller changes its mind.');
            });
        } catch (RuntimeException) {
        }

        Queue::assertNotPushed(CancelDocumensoEnvelopes::class);
        expect($version->refresh()->signature_status)->toBe(SignatureStatus::Sending);
    });
});

describe('the command', function (): void {
    it('gives up on what is stuck, says so, and is safe to run again', function (): void {
        versionDocumenso();
        $case = versionTableCase();
        $stuck = versionFreeze($case['inventory'], $case['team']['owner']);
        $request = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1)));
        versionAged($stuck, 70);
        versionAged($request, 130);

        $this->artisan('inventaire:sync-versions')
            ->expectsOutputToContain('1 stuck version(s) given up on, 1 unsent signature request(s) given up on, 0 signature request(s) queued to be looked at again.')
            ->assertSuccessful();

        $this->artisan('inventaire:sync-versions')
            ->expectsOutputToContain('0 stuck version(s) given up on, 0 unsent signature request(s) given up on, 0 signature request(s) queued to be looked at again.')
            ->assertSuccessful();
    });

    it('does nothing when there is nothing', function (): void {
        $this->artisan('inventaire:sync-versions')
            ->expectsOutputToContain('0 stuck version(s) given up on, 0 unsent signature request(s) given up on, 0 signature request(s) queued to be looked at again.')
            ->assertSuccessful();
    });

    it('is scheduled every ten minutes, without running twice at once', function (): void {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'inventaire:sync-versions'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('*/10 * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            // The lock of a run that was killed is forgotten after 15 minutes: the default is a day, a day without maintenance.
            ->and($events->first()->expiresAt)->toBe(15);
    });
});
