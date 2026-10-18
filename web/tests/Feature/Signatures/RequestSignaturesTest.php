<?php

declare(strict_types=1);

use App\Inventories\Versions\SignatureError;
use App\Inventories\Versions\SignatureStatus;
use App\Inventories\Versions\SignerStatus;
use App\Inventories\Versions\VersionStatus;
use App\Jobs\CancelDocumensoEnvelopes;
use App\Jobs\GenerateOfficialVersion;
use App\Jobs\RequestSignatures;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Signatures\DocumensoException;
use App\Signatures\SignatureRequests;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDocumenso;

require_once __DIR__.'/../../Support/versions.php';

/*
 * Sending the request for signatures of a completed version to Documenso: `requested`, then `sending`, then `awaiting`, and every way it
 * goes wrong or is repeated. The job is run in the process, a try at a time, as a worker would; the network is a fake Documenso.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->useLanguage('en');
});

/**
 * A completed version with signers, `requested`, and a fake Documenso that has nothing yet.
 *
 * @return array{case: array{team: array<string, mixed>, inventory: Inventory}, version: InventoryVersion, fake: FakeDocumenso}
 */
function signatureRequested(int $signers = 2): array
{
    $fake = versionDocumenso();
    $case = versionTableCase();
    $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], 'Year end', versionSigners($signers)));

    return ['case' => $case, 'version' => $version, 'fake' => $fake];
}

/**
 * Runs the job the way a worker would, up to its tries: stops at the first try that does not throw, and reports the last failure to the job.
 *
 * @return list<Throwable> What the tries threw, in order.
 */
function signatureWork(InventoryVersion $version): array
{
    $job = new RequestSignatures($version);
    $thrown = [];

    for ($attempt = 1; $attempt <= $job->tries; $attempt++) {
        try {
            $job->handle(app(SignatureRequests::class));

            break;
        } catch (Throwable $exception) {
            $thrown[] = $exception;

            if ($attempt === $job->tries) {
                $job->failed($exception);
            }
        }
    }

    return $thrown;
}

describe('the job', function (): void {
    it('is queued when the PDF is written, for a version that asks for signatures', function (): void {
        $made = signatureRequested();

        Queue::assertPushed(RequestSignatures::class, 1);
        Queue::assertPushed(RequestSignatures::class, fn (RequestSignatures $job): bool => $job->version->is($made['version']));
        expect($made['version']->status)->toBe(VersionStatus::Completed)
            ->and($made['version']->signature_status)->toBe(SignatureStatus::Requested);
    });

    it('is tried five times, with a pause that grows, within four minutes (a creation may wait two), on the default queue', function (): void {
        $made = signatureRequested();
        $job = new RequestSignatures($made['version']);

        expect($job->tries)->toBe(5)
            ->and($job->backoff())->toBe([10, 60, 300, 900])
            ->and($job->timeout)->toBe(240)->and($job->timeout)->toBeGreaterThan(120 + 20 + 20)
            ->and($job->queue)->toBeNull()
            ->and($job->deleteWhenMissingModels)->toBeTrue();
    });

    it('is not queued for a version nobody has to sign', function (): void {
        $case = versionTableCase();

        versionRun(versionFreeze($case['inventory'], $case['team']['owner']));

        Queue::assertNotPushed(RequestSignatures::class);
        Queue::assertPushed(GenerateOfficialVersion::class);
    });

    it('is not queued when the PDF could not be written', function (): void {
        versionDocumenso();
        FakeDocumenso::install();
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1));
        Storage::disk(ATTACHMENT_TEST_DISK)->delete($version->snapshot_path);

        $failed = versionRun($version);

        expect($failed->status)->toBe(VersionStatus::Failed);
        Queue::assertNotPushed(RequestSignatures::class);
    });

    it('does not fail the version when the queue cannot be reached: it stays as it is, for the schedule to give up on', function (): void {
        Log::spy();
        versionDocumenso();
        FakeDocumenso::install();
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1));
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('The queue is down.')));

        $completed = versionRun($version);

        expect($completed->status)->toBe(VersionStatus::Completed)
            ->and($completed->signature_status)->toBe(SignatureStatus::Requested)
            ->and($completed->counted_bytes)->toBeGreaterThan(0);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'sends the request for signatures'))->once();
    });
});

describe('sending', function (): void {
    it('creates the envelope, reads it, distributes it, and awaits the signers', function (): void {
        $made = signatureRequested(2);

        $sent = versionSend($made['version']);
        $fake = $made['fake'];

        expect($fake->calls)->toBe(['POST /envelope/create', 'GET /envelope/{id}', 'POST /envelope/distribute'])
            ->and($fake->requestsOf('POST /envelope/distribute')[0]->data())->toBe(['envelopeId' => 'envelope_test1'])
            ->and($fake->status)->toBe('PENDING')
            ->and($fake->externalId)->toBe($sent->ulid)
            ->and($sent->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($sent->signature_error)->toBeNull()
            ->and($sent->documenso_envelope_id)->toBe('envelope_test1')
            ->and($sent->signature_requested_at)->not->toBeNull()
            ->and($sent->signature_requested_at->diffInSeconds(now(), true))->toBeLessThan(30)
            ->and($sent->signature_synced_at)->not->toBeNull()
            ->and($sent->status)->toBe(VersionStatus::Completed)
            ->and(collect($sent->signers)->pluck('recipient_id')->all())->toBe([100, 101])
            ->and(collect($sent->signers)->pluck('status')->unique()->all())->toBe([SignerStatus::Pending->value])
            // What was stored with the signers before (their box on the page) is kept.
            ->and($sent->signers[0]['field']['page'])->toBe($sent->pdf_pages);
    });

    it('records the id of the envelope at once, before it reads it, and is `sending` while it works', function (): void {
        $made = signatureRequested(1);
        $seen = [];
        $made['fake']
            ->onCall('POST /envelope/create', function () use ($made, &$seen): void {
                $row = $made['version']->fresh();
                $seen['create'] = [$row->signature_status, $row->documenso_envelope_id];
            })
            ->onCall('GET /envelope/{id}', function () use ($made, &$seen): void {
                $row = $made['version']->fresh();
                $seen['read'] = [$row->signature_status, $row->documenso_envelope_id];
            })
            ->onCall('POST /envelope/distribute', function () use ($made, &$seen): void {
                $row = $made['version']->fresh();
                $seen['distribute'] = [$row->signature_status, $row->documenso_envelope_id];
            });

        versionSend($made['version']);

        expect($seen)->toBe([
            'create' => [SignatureStatus::Sending, null],
            'read' => [SignatureStatus::Sending, 'envelope_test1'],
            'distribute' => [SignatureStatus::Sending, 'envelope_test1'],
        ]);
    });

    it('sends the PDF that was written, not another one', function (): void {
        $made = signatureRequested(1);

        versionSend($made['version']);

        expect($made['fake']->pdf)->toBe(versionFile($made['version'], (string) $made['version']->pdf_path))
            ->and(hash('sha256', (string) $made['fake']->pdf))->toBe($made['version']->pdf_sha256);
    });

    it('does nothing for a version that is not requested, or not completed', function (): void {
        $made = signatureRequested(1);
        $made['version']->forceFill(['signature_status' => SignatureStatus::Cancelled])->save();

        versionSend($made['version']);

        expect($made['fake']->calls)->toBe([])
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Cancelled);

        $made['version']->forceFill(['signature_status' => SignatureStatus::Requested, 'status' => VersionStatus::Running])->save();
        versionSend($made['version']);

        expect($made['fake']->calls)->toBe([])
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Requested);
    });
});

describe('sending again after a failure part way', function (): void {
    it('does not create a second envelope when the first was created and the read failed', function (): void {
        $made = signatureRequested(2);
        $made['fake']->failNext('GET /envelope/{id}', 503);

        // The first try throws (the queue releases the job), the second one succeeds.
        $thrown = signatureWork($made['version']);

        expect($thrown)->toHaveCount(1)
            ->and($made['fake']->created)->toBe(1)
            ->and($made['fake']->count('POST /envelope/create'))->toBe(1)
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);
    });

    it('keeps the request `sending` with its envelope after a try that throws, and the next try resumes without creating again', function (): void {
        $made = signatureRequested(2);
        $made['fake']->failNext('GET /envelope/{id}', 503);

        $job = new RequestSignatures($made['version']);
        $first = null;

        try {
            $job->handle(app(SignatureRequests::class));
        } catch (Throwable $exception) {
            $first = $exception;
        }

        $between = $made['version']->fresh();

        expect($first)->toBeInstanceOf(DocumensoException::class)
            ->and($first->retryable())->toBeTrue()
            ->and($between->signature_status)->toBe(SignatureStatus::Sending)
            ->and($between->signature_error)->toBeNull()
            ->and($between->documenso_envelope_id)->toBe('envelope_test1');

        $job->handle(app(SignatureRequests::class));

        expect($made['fake']->calls)->toBe(['POST /envelope/create', 'GET /envelope/{id}', 'GET /envelope/{id}', 'POST /envelope/distribute'])
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);
    });

    it('resumes at the distribution when that is what failed', function (): void {
        $made = signatureRequested(1);
        $made['fake']->failNext('POST /envelope/distribute', 502);

        $thrown = signatureWork($made['version']);

        expect($thrown)->toHaveCount(1)
            ->and($made['fake']->count('POST /envelope/create'))->toBe(1)
            ->and($made['fake']->count('POST /envelope/distribute'))->toBe(2)
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);
    });

    it('does not distribute an envelope that was already distributed (the worker died after Documenso sent the emails)', function (): void {
        $made = signatureRequested(2);
        // The first try went as far as the distribution, and the worker was killed before it recorded it.
        $made['fake']->failNext('POST /envelope/distribute', 502);
        try {
            app(SignatureRequests::class)->send($made['version']);
        } catch (Throwable) {
        }
        $made['fake']->status = 'PENDING';

        versionSend($made['version']);

        expect($made['fake']->count('POST /envelope/create'))->toBe(1)
            ->and($made['fake']->count('POST /envelope/distribute'))->toBe(1)
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($made['version']->fresh()->signature_requested_at)->not->toBeNull();
    });

    it('is harmless to run again once the request is awaiting: not a call is made', function (): void {
        $made = versionAwaiting(2);
        $calls = $made['fake']->calls;

        versionSend($made['version']);
        versionSend($made['version']);

        expect($made['fake']->calls)->toBe($calls)
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);
    });

    it('creates the envelope again, once, when the one it recorded is gone from Documenso', function (): void {
        $made = signatureRequested(1);
        $made['version']->forceFill(['signature_status' => SignatureStatus::Failed, 'signature_error' => SignatureError::DocumensoUnavailable, 'documenso_envelope_id' => 'envelope_gone'])->save();
        app(SignatureRequests::class)->retry($made['version']);

        versionSend($made['version']);
        $row = $made['version']->fresh();

        expect($made['fake']->calls)->toBe(['GET /envelope/{id}', 'POST /envelope/create', 'GET /envelope/{id}', 'POST /envelope/distribute'])
            ->and($row->documenso_envelope_id)->toBe('envelope_test1')
            ->and($row->signature_status)->toBe(SignatureStatus::Awaiting);
    });

    it('gives up when an envelope it has just created is not found', function (): void {
        $made = signatureRequested(1);
        $made['fake']->failNext('GET /envelope/{id}', 404);

        versionSend($made['version']);
        $row = $made['version']->fresh();

        expect($made['fake']->count('POST /envelope/create'))->toBe(1)
            ->and($row->signature_status)->toBe(SignatureStatus::Failed)
            ->and($row->signature_error)->toBe(SignatureError::DocumensoRefused);
    });

    it('takes whatever the envelope already is: signed by everybody, refused, cancelled', function (string $state, SignatureStatus $expected): void {
        $made = signatureRequested(1);
        $made['version']->forceFill(['signature_status' => SignatureStatus::Sending, 'documenso_envelope_id' => 'envelope_test1'])->save();
        $made['fake']->envelopeFor($made['version']->fresh(), 'PENDING');

        match ($state) {
            'COMPLETED' => $made['fake']->complete(),
            'REJECTED' => $made['fake']->reject('signer1@example.test'),
            'CANCELLED' => $made['fake']->status = 'CANCELLED',
        };

        versionSend($made['version']);

        expect($made['fake']->count('POST /envelope/distribute'))->toBe(0)
            ->and($made['version']->fresh()->signature_status)->toBe($expected);
    })->with([
        'completed' => ['COMPLETED', SignatureStatus::Completed],
        'rejected' => ['REJECTED', SignatureStatus::Rejected],
        'cancelled' => ['CANCELLED', SignatureStatus::Cancelled],
    ]);
});

describe('a failure', function (): void {
    it('that Documenso will repeat ends the request at once: failed, refused, nothing thrown', function (int $status): void {
        Log::spy();
        $made = signatureRequested(1);
        $made['fake']->failNext('POST /envelope/create', $status);

        $thrown = signatureWork($made['version']);
        $row = $made['version']->fresh();

        expect($thrown)->toBe([])
            ->and($made['fake']->count('POST /envelope/create'))->toBe(1)
            ->and($row->signature_status)->toBe(SignatureStatus::Failed)
            ->and($row->signature_error)->toBe(SignatureError::DocumensoRefused)
            ->and($row->documenso_envelope_id)->toBeNull()
            // The version itself is fine, and can be downloaded.
            ->and($row->status)->toBe(VersionStatus::Completed);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ($context['status'] ?? null) === $status && ! str_contains((string) json_encode($context), 'api_test_key'))->once();
    })->with([400, 401, 403, 413, 422]);

    it('that may pass is thrown for the queue, and leaves the request `sending`', function (?int $status): void {
        $made = signatureRequested(1);
        $made['fake']->failNext('POST /envelope/create', $status);

        $job = new RequestSignatures($made['version']);

        try {
            $job->handle(app(SignatureRequests::class));
            $thrown = null;
        } catch (DocumensoException $exception) {
            $thrown = $exception;
        }

        $row = $made['version']->fresh();

        expect($thrown?->retryable())->toBeTrue()
            ->and($row->signature_status)->toBe(SignatureStatus::Sending)
            ->and($row->signature_error)->toBeNull()
            ->and($row->documenso_envelope_id)->toBeNull();
    })->with([
        'a lost connection' => [null],
        'too many requests' => [429],
        'a server error' => [500],
        'a bad gateway' => [502],
        'unavailable' => [503],
    ]);

    it('after the last try is `documenso_unavailable`, and sending the request again resumes from the envelope', function (): void {
        $made = signatureRequested(1);
        $made['fake']->failNext('POST /envelope/create', 503, 5);

        $thrown = signatureWork($made['version']);
        $failed = $made['version']->fresh();

        expect($thrown)->toHaveCount(5)
            ->and($made['fake']->count('POST /envelope/create'))->toBe(5)
            ->and($failed->signature_status)->toBe(SignatureStatus::Failed)
            ->and($failed->signature_error)->toBe(SignatureError::DocumensoUnavailable)
            ->and($failed->status)->toBe(VersionStatus::Completed);

        app(SignatureRequests::class)->retry($failed);
        versionSend($failed);

        expect($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($made['fake']->created)->toBe(1);
    });

    it('keeps the envelope it created when the tries run out after it, so that no second one is made', function (): void {
        $made = signatureRequested(1);
        $made['fake']->failNext('GET /envelope/{id}', 503, 5);

        $thrown = signatureWork($made['version']);
        $failed = $made['version']->fresh();

        expect($thrown)->toHaveCount(5)
            ->and($failed->signature_status)->toBe(SignatureStatus::Failed)
            ->and($failed->signature_error)->toBe(SignatureError::DocumensoUnavailable)
            ->and($failed->documenso_envelope_id)->toBe('envelope_test1');

        app(SignatureRequests::class)->retry($failed);
        versionSend($failed);

        expect($made['fake']->created)->toBe(1)
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);
    });

    it('is reported by the job as unavailable only while the request is being sent', function (SignatureStatus $status, SignatureStatus $expected): void {
        $made = signatureRequested(1);
        $made['version']->forceFill(['signature_status' => $status])->save();

        (new RequestSignatures($made['version']))->failed(new RuntimeException('The job timed out.'));

        expect($made['version']->fresh()->signature_status)->toBe($expected);
    })->with([
        'requested' => [SignatureStatus::Requested, SignatureStatus::Failed],
        'sending' => [SignatureStatus::Sending, SignatureStatus::Failed],
        'awaiting' => [SignatureStatus::Awaiting, SignatureStatus::Awaiting],
        'completed' => [SignatureStatus::Completed, SignatureStatus::Completed],
        'cancelled' => [SignatureStatus::Cancelled, SignatureStatus::Cancelled],
    ]);

    it('is `documenso_refused` when the instance lost its Documenso meanwhile, and nothing is sent', function (): void {
        $made = signatureRequested(1);
        config(['services.documenso.url' => null]);

        versionSend($made['version']);

        expect($made['fake']->calls)->toBe([])
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Failed)
            ->and($made['version']->fresh()->signature_error)->toBe(SignatureError::DocumensoRefused);
    });

    it('is `documenso_refused` when a signer has no signature box, and nothing is sent', function (): void {
        $made = signatureRequested(2);
        $signers = $made['version']->signers;
        unset($signers[0]['field']);
        $made['version']->forceFill(['signers' => $signers])->save();

        versionSend($made['version']);

        expect($made['fake']->calls)->toBe([])
            ->and($made['version']->fresh()->signature_error)->toBe(SignatureError::DocumensoRefused);
    });

    it('sends the token Documenso expects on every call, or is refused', function (): void {
        $made = signatureRequested(1);
        config(['services.documenso.api_key' => 'another_key']);

        versionSend($made['version']);

        expect($made['version']->fresh()->signature_error)->toBe(SignatureError::DocumensoRefused)
            ->and($made['fake']->calls)->toBe(['POST /envelope/create']);
    });
});

describe('a version that is deleted while it is sent', function (): void {
    it('is not distributed when it is gone before the signers would be emailed', function (): void {
        $made = signatureRequested(1);
        $made['fake']->onCall('GET /envelope/{id}', fn () => InventoryVersion::query()->whereKey($made['version']->id)->delete());

        versionSend($made['version']);

        expect($made['fake']->count('POST /envelope/distribute'))->toBe(0)
            ->and(InventoryVersion::query()->count())->toBe(0);
        Queue::assertNotPushed(CancelDocumensoEnvelopes::class);
    });

    it('has its envelope cancelled when it is gone as the signers are emailed', function (): void {
        $made = signatureRequested(1);
        $made['fake']->onCall('POST /envelope/distribute', fn () => InventoryVersion::query()->whereKey($made['version']->id)->delete());

        versionSend($made['version']);

        Queue::assertPushed(CancelDocumensoEnvelopes::class, fn (CancelDocumensoEnvelopes $job): bool => $job->envelopeIds === ['envelope_test1']);
    });

    it('is not distributed when it was cancelled or taken up meanwhile', function (): void {
        $made = signatureRequested(1);
        $made['fake']->onCall('GET /envelope/{id}', fn () => InventoryVersion::query()->whereKey($made['version']->id)->update(['signature_status' => SignatureStatus::Cancelled->value]));

        versionSend($made['version']);

        expect($made['fake']->count('POST /envelope/distribute'))->toBe(0)
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Cancelled);
    });
});

describe('a webhook that comes first', function (): void {
    it('has recorded the id of the envelope by the time the job records it: the request goes on with it', function (): void {
        $made = signatureRequested(1);
        $made['fake']->onCall('POST /envelope/create', fn () => InventoryVersion::query()->whereKey($made['version']->id)->update(['documenso_envelope_id' => 'envelope_test1']));

        versionSend($made['version']);

        expect($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($made['version']->fresh()->documenso_envelope_id)->toBe('envelope_test1');
    });

    it('has recorded another id: the request goes on with that envelope, which is distributed once, and the draft the job made is deleted', function (): void {
        $made = signatureRequested(1);
        $version = $made['version'];
        // The envelope the webhook announced and the read adopted: a draft of this version at Documenso, with its recipients.
        $made['fake']->other('envelope_other', 'DRAFT', $version->ulid, [['id' => 301, 'email' => 'signer1@example.test', 'name' => 'Signer 1', 'signingStatus' => 'NOT_SIGNED', 'signedAt' => null, 'rejectionReason' => null]]);
        $made['fake']->onCall('POST /envelope/create', fn () => InventoryVersion::query()->whereKey($version->id)->update(['documenso_envelope_id' => 'envelope_other']));

        versionSend($version);

        $deleted = array_map(fn (Request $request): array => $request->data(), $made['fake']->requestsOf('POST /envelope/delete'));
        $distributed = array_map(fn (Request $request): array => $request->data(), $made['fake']->requestsOf('POST /envelope/distribute'));
        $row = $version->fresh();

        // Ours is deleted, theirs is distributed (once), and the request is awaiting its signers with the recorded envelope.
        expect($deleted)->toBe([['envelopeId' => 'envelope_test1']])
            ->and($distributed)->toBe([['envelopeId' => 'envelope_other']])
            ->and($made['fake']->count('POST /envelope/create'))->toBe(1)
            ->and($row->documenso_envelope_id)->toBe('envelope_other')
            ->and($row->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and($row->signers[0]['recipient_id'])->toBe(301);
    });

    it('has recorded another id that Documenso cannot read: that one is forgotten once and the request is made again, never in a loop', function (): void {
        Log::spy();
        $made = signatureRequested(1);
        $version = $made['version'];
        $made['fake']->onCall('POST /envelope/create', fn () => InventoryVersion::query()->whereKey($version->id)->update(['documenso_envelope_id' => 'envelope_other']));

        // The recorded id is not at Documenso: forgotten, a second envelope is made, and the hook records the same stranger again.
        $row = versionSend($version);

        expect($made['fake']->count('POST /envelope/create'))->toBe(2)
            ->and($row->signature_status)->toBe(SignatureStatus::Failed)
            ->and($row->signature_error)->toBe(SignatureError::DocumensoRefused);
    });

    it('does not fail the request when the draft it made cannot be deleted: it is only left a draft, and written in the log', function (): void {
        Log::spy();
        $made = signatureRequested(1);
        $version = $made['version'];
        $made['fake']->other('envelope_other', 'DRAFT', $version->ulid);
        $made['fake']->onCall('POST /envelope/create', fn () => InventoryVersion::query()->whereKey($version->id)->update(['documenso_envelope_id' => 'envelope_other']));
        $made['fake']->failNext('POST /envelope/delete', 500);

        versionSend($version);

        expect($version->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'could not be deleted'))->once();
    });

});
