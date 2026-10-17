<?php

declare(strict_types=1);

use App\Inventories\DerivedData;
use App\Inventories\Versions\OfficialVersions;
use App\Inventories\Versions\SignatureError;
use App\Inventories\Versions\SignatureStatus;
use App\Inventories\Versions\SignerStatus;
use App\Jobs\PurgeStoredFiles;
use App\Jobs\SyncSignature;
use App\Models\InventoryVersion;
use App\Signatures\DocumensoClient;
use App\Signatures\DocumensoException;
use App\Signatures\SignatureSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/versions.php';

/*
 * Reading the envelope of a request again, and what each state of the envelope does to the request: the signers, the signed PDF that is
 * downloaded, stored, hashed and counted, a request that is refused, cancelled or lost. Whatever Documenso said by webhook is not in play
 * here: the state always comes from the API, which is a fake Documenso.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->useLanguage('en');
});

/**
 * The storage counter of a workspace, as the database holds it.
 */
function signatureStorageUsed(InventoryVersion $version): int
{
    return (int) DB::table('workspaces')
        ->join('inventories', 'inventories.workspace_id', '=', 'workspaces.id')
        ->where('inventories.id', $version->inventory_id)
        ->value('workspaces.storage_used');
}

/**
 * Makes the request look as if it had been looked at `$minutes` ago.
 */
function signatureSyncedAgo(InventoryVersion $version, int $minutes): InventoryVersion
{
    DB::table('inventory_versions')->where('id', $version->id)->update(['signature_synced_at' => now()->subMinutes($minutes)]);

    return $version->refresh();
}

describe('the job', function (): void {
    it('is unique per version, so that a burst of events costs one read, and the lock goes when the job starts', function (): void {
        $job = new SyncSignature(42);

        expect($job)->toBeInstanceOf(ShouldBeUnique::class)
            ->and($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
            ->and($job->uniqueId())->toBe('42')
            ->and($job->uniqueFor)->toBe(60)
            ->and($job->tries)->toBe(3)
            ->and($job->backoff())->toBe([30, 120])
            ->and($job->queue)->toBeNull();

        SyncSignature::dispatch(42);
        SyncSignature::dispatch(42);
        SyncSignature::dispatch(43);

        Queue::assertPushed(SyncSignature::class, 2);
    });

    it('reads the version it was given and does what the service does', function (): void {
        $made = versionAwaiting(2);
        $made['fake']->sign('signer1@example.test');

        (new SyncSignature($made['version']->id))->handle(app(SignatureSync::class));

        expect($made['version']->fresh()->signers[0]['status'])->toBe(SignerStatus::Signed->value);
    });
});

describe('a pending envelope', function (): void {
    it('updates the signers, and leaves the request awaiting them', function (): void {
        $made = versionAwaiting(2);
        $made['fake']->sign('signer1@example.test', '2026-10-15T14:30:00.000+02:00');

        $synced = versionSync(signatureSyncedAgo($made['version'], 90));

        expect($synced->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($synced->signers[0]['status'])->toBe(SignerStatus::Signed->value)
            // In UTC, whichever zone Documenso wrote.
            ->and($synced->signers[0]['signed_at'])->toBe('2026-10-15T12:30:00+00:00')
            ->and($synced->signers[0]['recipient_id'])->toBe(100)
            ->and($synced->signers[1]['status'])->toBe(SignerStatus::Pending->value)
            ->and($synced->signers[1]['signed_at'])->toBeNull()
            // What was kept with the signer is kept.
            ->and($synced->signers[0]['field']['page'])->toBe($synced->pdf_pages)
            ->and($synced->signed_pdf_path)->toBeNull()
            ->and($made['fake']->count('GET /envelope/item/{id}/download'))->toBe(0)
            // Looked at just now.
            ->and($synced->signature_synced_at->diffInMinutes(now(), true))->toBeLessThan(2);
    });

    it('matches a signer to a recipient whatever the case of the address', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->recipients[0]['email'] = 'SIGNER1@Example.Test';
        $made['fake']->recipients[0]['signingStatus'] = 'SIGNED';
        $made['fake']->recipients[0]['signedAt'] = '2026-10-15T12:30:00.000Z';

        $synced = versionSync($made['version']);

        expect($synced->signers[0]['status'])->toBe(SignerStatus::Signed->value);
    });

    it('shows the reason of a signer who refused', function (): void {
        $made = versionAwaiting(2);
        $made['fake']->reject('signer2@example.test', 'I am not the owner');

        $synced = versionSync($made['version']);

        expect($synced->signature_status)->toBe(SignatureStatus::Rejected)
            ->and($synced->signers[1]['status'])->toBe(SignerStatus::Rejected->value)
            ->and($synced->signers[1]['rejection_reason'])->toBe('I am not the owner')
            ->and($synced->signers[0]['rejection_reason'])->toBeNull();
    });

    it('takes a request that was being sent to awaiting, when Documenso has sent the emails', function (): void {
        $made = versionAwaiting(2);
        // The worker died before it recorded it.
        $made['version']->forceFill(['signature_status' => SignatureStatus::Sending, 'signature_requested_at' => null])->save();

        $synced = versionSync($made['version']);

        expect($synced->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($synced->signature_requested_at)->not->toBeNull();
    });

    it('leaves a request that is being sent alone while its envelope is still a draft', function (): void {
        $made = versionAwaiting(2);
        $made['version']->forceFill(['signature_status' => SignatureStatus::Sending, 'signature_requested_at' => null])->save();
        $made['fake']->status = 'DRAFT';

        $synced = versionSync($made['version']);

        expect($synced->signature_status)->toBe(SignatureStatus::Sending)
            ->and($synced->signature_requested_at)->toBeNull()
            ->and($synced->signature_synced_at)->not->toBeNull()
            ->and($synced->signers[0]['recipient_id'])->toBe(100);
    });
});

describe('a completed envelope', function (): void {
    it('is downloaded, stored, hashed, counted against the storage and completed', function (): void {
        $made = versionAwaiting(2);
        $before = signatureStorageUsed($made['version']);
        $counted = $made['version']->counted_bytes;
        $made['fake']->signedPdf = "%PDF-1.7\n".str_repeat('signed document ', 100);
        $made['fake']->complete('2026-10-16T09:00:00.000Z');

        $done = versionSync($made['version']);
        $path = $done->directory().'/signed.pdf';

        expect($made['fake']->calls)->toContain('GET /envelope/item/{id}/download')
            ->and($made['fake']->requestsOf('GET /envelope/item/{id}/download')[0]->url())->toEndWith('/envelope/item/envelope_item_envelope_test1/download?version=signed')
            ->and($done->signature_status)->toBe(SignatureStatus::Completed)
            ->and($done->signature_error)->toBeNull()
            ->and($done->signed_pdf_path)->toBe($path)
            ->and($done->signed_pdf_size)->toBe(strlen($made['fake']->signedPdf))
            ->and($done->signed_pdf_sha256)->toBe(hash('sha256', $made['fake']->signedPdf))
            ->and(versionFile($done, $path))->toBe($made['fake']->signedPdf)
            ->and($done->signature_completed_at)->not->toBeNull()
            ->and($done->signature_synced_at)->not->toBeNull()
            ->and(collect($done->signers)->pluck('status')->unique()->all())->toBe([SignerStatus::Signed->value])
            ->and($done->signers[0]['signed_at'])->toBe('2026-10-16T09:00:00+00:00')
            // The storage of the workspace counts it, and so does the version: the one figure a deletion gives back.
            ->and($done->counted_bytes)->toBe($counted + $done->signed_pdf_size)
            ->and(signatureStorageUsed($done))->toBe($before + $done->signed_pdf_size)
            ->and(app(DerivedData::class)->recountStorage())->toBe(0);
    });

    it('is stored next to the other files of the version, and nowhere else', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();

        $done = versionSync($made['version']);

        expect(array_values(array_filter(storedFiles(), fn (string $file): bool => str_contains($file, $done->ulid))))->toEqualCanonicalizing([
            $done->snapshot_path, (string) $done->pdf_path, $done->directory().'/signed.pdf',
        ]);
    });

    it('is not downloaded twice, nor counted twice, when the request is looked at again', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $first = versionSync($made['version']);
        $used = signatureStorageUsed($first);

        $second = versionSync($first);
        $calls = $made['fake']->calls;

        expect($made['fake']->count('GET /envelope/item/{id}/download'))->toBe(1)
            ->and(signatureStorageUsed($second))->toBe($used)
            ->and($second->counted_bytes)->toBe($first->counted_bytes)
            ->and($calls)->toBe(['POST /envelope/create', 'GET /envelope/{id}', 'POST /envelope/distribute', 'GET /envelope/{id}', 'GET /envelope/item/{id}/download']);
    });

    it('is counted once when two workers complete it at the same time, and the file they share is kept', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $before = signatureStorageUsed($made['version']);
        // Both read the request while it was awaiting.
        $stale = InventoryVersion::query()->findOrFail($made['version']->id);
        $envelope = (new DocumensoClient)->getEnvelope('envelope_test1');

        app(SignatureSync::class)->apply($stale, $envelope);
        app(SignatureSync::class)->apply($stale, $envelope);
        $done = $made['version']->fresh();

        expect($done->signature_status)->toBe(SignatureStatus::Completed)
            ->and(signatureStorageUsed($done))->toBe($before + $done->signed_pdf_size)
            ->and($done->counted_bytes)->toBe($made['version']->counted_bytes + $done->signed_pdf_size)
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->exists((string) $done->signed_pdf_path))->toBeTrue();
        Queue::assertNotPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => in_array($done->signed_pdf_path, $job->paths, true));
    });

    it('is thrown away when the version is deleted while the PDF is being downloaded', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $before = signatureStorageUsed($made['version']);
        $made['fake']->onCall('GET /envelope/item/{id}/download', fn () => InventoryVersion::query()->whereKey($made['version']->id)->delete());
        $path = $made['version']->directory().'/signed.pdf';

        versionSync($made['version']);

        expect(InventoryVersion::query()->count())->toBe(0)
            ->and(signatureStorageUsed($made['version']))->toBe($before);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->paths === [$path]);
    });

    it('is thrown away when the request was cancelled while the PDF is being downloaded', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $before = signatureStorageUsed($made['version']);
        $made['fake']->onCall('GET /envelope/item/{id}/download', fn () => InventoryVersion::query()->whereKey($made['version']->id)->update(['signature_status' => SignatureStatus::Cancelled->value]));
        $path = $made['version']->directory().'/signed.pdf';

        $row = versionSync($made['version']);

        expect($row->signature_status)->toBe(SignatureStatus::Cancelled)
            ->and($row->signed_pdf_path)->toBeNull()
            ->and($row->counted_bytes)->toBe($made['version']->counted_bytes)
            ->and(signatureStorageUsed($row))->toBe($before);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->paths === [$path]);
    });

    it('is given back with the rest of the version when the version is deleted', function (): void {
        $made = versionAwaiting(1);
        $other = versionRun(versionFreeze($made['case']['inventory'], $made['case']['team']['owner']));
        $withoutSignature = signatureStorageUsed($made['version']);
        $made['fake']->complete();
        $done = versionSync($made['version']);

        expect(signatureStorageUsed($done))->toBe($withoutSignature + $done->signed_pdf_size);

        app(OfficialVersions::class)->delete($done);

        expect(signatureStorageUsed($done))->toBe($withoutSignature - $made['version']->counted_bytes)
            ->and($other->fresh())->not->toBeNull()
            ->and(app(DerivedData::class)->recountStorage())->toBe(0);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [$done->directory()]);
    });

    it('completes a request that was still being sent', function (): void {
        $made = versionAwaiting(1);
        $made['version']->forceFill(['signature_status' => SignatureStatus::Sending])->save();
        $made['fake']->complete();

        expect(versionSync($made['version'])->signature_status)->toBe(SignatureStatus::Completed);
    });

    it('cannot be completed without an item to download', function (): void {
        $made = versionAwaiting(1);
        $envelope = ['status' => 'COMPLETED', 'externalId' => $made['version']->ulid, 'recipients' => [], 'envelopeItems' => []];
        $calls = $made['fake']->calls;

        expect(fn () => app(SignatureSync::class)->apply($made['version'], $envelope))->toThrow(DocumensoException::class, 'no envelope item')
            ->and($made['fake']->calls)->toBe($calls)
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);
    });

    it('is not completed when what Documenso gives back is not a PDF: logged, looked at again later', function (): void {
        Log::spy();
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $made['fake']->signedPdf = 'This is not a PDF';

        $row = versionSync(signatureSyncedAgo($made['version'], 90));

        expect($row->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($row->signed_pdf_path)->toBeNull()
            ->and($row->signature_synced_at->diffInMinutes(now(), true))->toBeLessThan(2)
            ->and(array_filter(storedFiles(), fn (string $file): bool => str_ends_with($file, 'signed.pdf')))->toBe([]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'refused to give the state'))->once();
    });
});

describe('an envelope that ends otherwise', function (): void {
    it('is rejected: the request is rejected, and nothing is downloaded or counted', function (): void {
        $made = versionAwaiting(2);
        $before = signatureStorageUsed($made['version']);
        $made['fake']->sign('signer1@example.test')->reject('signer2@example.test');

        $row = versionSync($made['version']);

        expect($row->signature_status)->toBe(SignatureStatus::Rejected)
            ->and($row->signers[0]['status'])->toBe(SignerStatus::Signed->value)
            ->and($row->signers[1]['status'])->toBe(SignerStatus::Rejected->value)
            ->and($row->signed_pdf_path)->toBeNull()
            ->and($row->signature_completed_at)->toBeNull()
            ->and($made['fake']->count('GET /envelope/item/{id}/download'))->toBe(0)
            ->and(signatureStorageUsed($row))->toBe($before);
    });

    it('is cancelled at Documenso: the request is cancelled', function (): void {
        $made = versionAwaiting(2);
        $made['fake']->status = 'CANCELLED';

        $row = versionSync($made['version']);

        expect($row->signature_status)->toBe(SignatureStatus::Cancelled)
            ->and($row->signature_error)->toBeNull();
    });

    it('is gone from Documenso: the request is cancelled, with the reason', function (): void {
        $made = versionAwaiting(2);
        $made['fake']->exists = false;

        $row = versionSync($made['version']);

        expect($row->signature_status)->toBe(SignatureStatus::Cancelled)
            ->and($row->signature_error)->toBe(SignatureError::EnvelopeMissing)
            ->and($row->signature_synced_at)->not->toBeNull();

        $shown = inventoryCall($this, $made['case']['team']['editor'], 'GET', "/api/v1/inventories/{$made['case']['inventory']->ulid}/versions/{$row->ulid}")->assertOk();

        expect($shown->json('data.signature.status'))->toBe('cancelled')
            ->and($shown->json('data.signature.error'))->toBe('The signature request no longer exists at the signature service.')
            ->and($shown->json('data.abilities.cancel_signature'))->toBeFalse();
    });

    it('is in a state nobody knows: logged, and read again later', function (): void {
        Log::spy();
        $made = versionAwaiting(1);
        $made['fake']->status = 'ARCHIVED';

        $row = versionSync(signatureSyncedAgo($made['version'], 90));

        expect($row->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($row->signature_synced_at->diffInMinutes(now(), true))->toBeLessThan(2);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'does not know'))->once();
    });
});

describe('a request that is not followed', function (): void {
    it('is not looked at: not a call is made', function (?SignatureStatus $status, ?string $envelope): void {
        $made = versionAwaiting(1);
        $made['version']->forceFill(['signature_status' => $status, 'documenso_envelope_id' => $envelope])->save();
        $calls = $made['fake']->calls;

        versionSync($made['version']);

        expect($made['fake']->calls)->toBe($calls);
    })->with([
        'asked for' => [SignatureStatus::Requested, null],
        'being sent, with no envelope yet' => [SignatureStatus::Sending, null],
        'awaiting, with no envelope (cannot be)' => [SignatureStatus::Awaiting, null],
        'completed' => [SignatureStatus::Completed, 'envelope_test1'],
        'rejected' => [SignatureStatus::Rejected, 'envelope_test1'],
        'cancelled' => [SignatureStatus::Cancelled, 'envelope_test1'],
        'failed' => [SignatureStatus::Failed, 'envelope_test1'],
        'none asked for' => [null, null],
    ]);

    it('is not looked at when the version is gone', function (): void {
        $made = versionAwaiting(1);
        $calls = $made['fake']->calls;
        $made['version']->delete();

        app(SignatureSync::class)->sync($made['version']->id);

        expect($made['fake']->calls)->toBe($calls);
    });

    it('is not followed into an envelope that is not the one of the version', function (): void {
        Log::spy();
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $made['fake']->externalId = '01j00000000000000000000000';

        $row = versionSync(signatureSyncedAgo($made['version'], 90));

        expect($row->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($made['fake']->count('GET /envelope/item/{id}/download'))->toBe(0)
            ->and($row->signature_synced_at->diffInMinutes(now(), true))->toBeLessThan(2);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'not the one of the version'))->once();
    });

    it('is followed when Documenso does not give our identifier back', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $made['fake']->externalId = null;

        expect(versionSync($made['version'])->signature_status)->toBe(SignatureStatus::Completed);
    });
});

describe('Documenso failing', function (): void {
    it('is thrown for the queue when it may pass, and changes nothing', function (?int $status): void {
        $made = versionAwaiting(1);
        $synced = $made['version']->signature_synced_at;
        $made['fake']->failNext('GET /envelope/{id}', $status);
        signatureSyncedAgo($made['version'], 90);

        try {
            app(SignatureSync::class)->sync($made['version']->id);
            $thrown = null;
        } catch (DocumensoException $exception) {
            $thrown = $exception;
        }

        $row = $made['version']->fresh();

        expect($thrown?->retryable())->toBeTrue()
            ->and($row->signature_status)->toBe(SignatureStatus::Awaiting)
            // Not looked at: the schedule comes back soon.
            ->and($row->signature_synced_at->lessThan(now()->subMinutes(80)))->toBeTrue()
            ->and($synced)->not->toBeNull();
    })->with([
        'a lost connection' => [null],
        'too many requests' => [429],
        'a server error' => [500],
        'unavailable' => [503],
    ]);

    it('is thrown for the queue when the signed PDF cannot be downloaded now', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $made['fake']->failNext('GET /envelope/item/{id}/download', 503);

        expect(fn () => app(SignatureSync::class)->sync($made['version']->id))->toThrow(DocumensoException::class);

        $row = $made['version']->fresh();

        expect($row->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($row->signed_pdf_path)->toBeNull();

        // The next try gets it.
        expect(versionSync($row)->signature_status)->toBe(SignatureStatus::Completed);
    });

    it('is written in the log when it refuses, and the request is marked as looked at, not changed', function (int $status): void {
        Log::spy();
        $made = versionAwaiting(1);
        $made['fake']->failNext('GET /envelope/{id}', $status);
        signatureSyncedAgo($made['version'], 90);

        $row = versionSync($made['version']);

        expect($row->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($row->signature_synced_at->diffInMinutes(now(), true))->toBeLessThan(2);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ($context['status'] ?? null) === $status)->once();
    })->with([400, 401, 403]);

    it('is refused when the instance lost its Documenso', function (): void {
        $made = versionAwaiting(1);
        $calls = $made['fake']->calls;
        config(['services.documenso.api_key' => '']);

        $row = versionSync($made['version']);

        expect($made['fake']->calls)->toBe($calls)
            ->and($row->signature_status)->toBe(SignatureStatus::Awaiting);
    });
});
