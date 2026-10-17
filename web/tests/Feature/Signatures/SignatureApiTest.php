<?php

declare(strict_types=1);

use App\Inventories\Versions\SignatureError;
use App\Inventories\Versions\SignatureStatus;
use App\Inventories\Versions\VersionError;
use App\Inventories\Versions\VersionStatus;
use App\Jobs\RequestSignatures;
use App\Jobs\SyncSignature;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Signatures\SignatureRequests;
use App\Signatures\SignatureSync;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDocumenso;

require_once __DIR__.'/../../Support/tokens.php';
require_once __DIR__.'/../../Support/versions.php';

/*
 * What the API offers on the request for signatures of a version: downloading the signed PDF, cancelling the request while its signers are
 * awaited, and sending it again once it failed. Who may do what, what is refused and why, and what Documenso is told.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->useLanguage('en');
});

/**
 * The address of a version, or of something of it.
 */
function signatureUri(Inventory $inventory, InventoryVersion $version, string $suffix = ''): string
{
    return "/api/v1/inventories/{$inventory->ulid}/versions/{$version->ulid}".$suffix;
}

/**
 * The roles of a team, who they are, and what each is answered for an action that needs the right to edit: a viewer is refused (403), a
 * stranger is told the inventory is not there (404).
 *
 * @return array<string, array{0: string, 1: int}>
 */
function signatureRolesForEditing(): array
{
    return [
        'a viewer' => ['viewer', 403],
        'a stranger' => ['stranger', 404],
    ];
}

/**
 * A completed version whose request failed, as a person finds it.
 *
 * @return array{case: array{team: array<string, mixed>, inventory: Inventory}, version: InventoryVersion, fake: FakeDocumenso}
 */
function signatureFailed(SignatureError $error = SignatureError::DocumensoUnavailable): array
{
    $made = versionAwaiting(2);
    $made['version']->forceFill(['signature_status' => SignatureStatus::Failed, 'signature_error' => $error])->save();

    return $made;
}

describe('downloading the signed PDF', function (): void {
    it('is not ready until every signer has signed: 409, whoever asks, for a version in any other state', function (?SignatureStatus $status): void {
        $made = versionAwaiting(1);
        $made['version']->forceFill(['signature_status' => $status])->save();

        foreach (['owner', 'editor', 'viewer'] as $role) {
            $response = inventoryCall($this, $made['case']['team'][$role], 'GET', signatureUri($made['case']['inventory'], $made['version'], '/signed-pdf'));

            $response->assertStatus(409)->assertJsonPath('code', 'signed_pdf_not_ready');
        }
    })->with([
        'asked for' => [SignatureStatus::Requested],
        'being sent' => [SignatureStatus::Sending],
        'awaiting' => [SignatureStatus::Awaiting],
        'rejected' => [SignatureStatus::Rejected],
        'cancelled' => [SignatureStatus::Cancelled],
        'failed' => [SignatureStatus::Failed],
        'no signature asked for' => [null],
    ]);

    it('is not ready for a request that says completed but holds no file', function (): void {
        $made = versionAwaiting(1);
        $made['version']->forceFill(['signature_status' => SignatureStatus::Completed])->save();

        inventoryCall($this, $made['case']['team']['owner'], 'GET', signatureUri($made['case']['inventory'], $made['version'], '/signed-pdf'))
            ->assertStatus(409)->assertJsonPath('code', 'signed_pdf_not_ready');
    });

    it('is the file, for every member of the workspace, and a 404 for a stranger', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $done = versionSync($made['version']);
        $uri = signatureUri($made['case']['inventory'], $done, '/signed-pdf');

        foreach (['owner', 'editor', 'viewer'] as $role) {
            expect(attachmentGet($this, $made['case']['team'][$role], $uri)->assertOk()->streamedContent())->toBe($made['fake']->signedPdf);
        }

        attachmentGet($this, $made['case']['team']['stranger'], $uri)->assertNotFound();
    });

    it('is a 404 in the path of another inventory', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $done = versionSync($made['version']);
        $other = inventoryIn($made['case']['team']['workspace'], 'Other');

        attachmentGet($this, $made['case']['team']['owner'], signatureUri($other, $done, '/signed-pdf'))->assertNotFound();
    });

    it('is a 404 with a code when the file is gone from the storage', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $done = versionSync($made['version']);
        Storage::disk(ATTACHMENT_TEST_DISK)->delete((string) $done->signed_pdf_path);

        attachmentGet($this, $made['case']['team']['owner'], signatureUri($made['case']['inventory'], $done, '/signed-pdf'))
            ->assertNotFound()->assertJsonPath('code', 'file_not_found');
    });

    it('needs the `read` ability of a token, and no other', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $done = versionSync($made['version']);
        $uri = signatureUri($made['case']['inventory'], $done, '/signed-pdf');
        $owner = $made['case']['team']['owner'];

        expect(tokenCall($this, tokenFor($owner, ['read']), 'GET', $uri)->assertOk()->streamedContent())->toBe($made['fake']->signedPdf);
        tokenCall($this, tokenFor($owner, ['write', 'manage']), 'GET', $uri)->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
    });
});

describe('the signed PDF through the API', function (): void {
    it('is the very bytes Documenso gave, with the figures the version gives', function (): void {
        $made = versionAwaiting(2);
        $made['fake']->signedPdf = "%PDF-1.7\n".random_bytes(300)."\n%%EOF";
        $made['fake']->complete();
        $done = versionSync($made['version']);
        $uri = "/api/v1/inventories/{$made['case']['inventory']->ulid}/versions/{$done->ulid}";

        $shown = inventoryCall($this, $made['case']['team']['viewer'], 'GET', $uri)->assertOk();
        $file = attachmentGet($this, $made['case']['team']['viewer'], $shown->json('data.signature.signed_pdf.download_url'))->assertOk();
        $bytes = $file->streamedContent();

        expect($shown->json('data.signature.status'))->toBe('completed')
            ->and($shown->json('data.signature.completed_at'))->not->toBeNull()
            ->and($shown->json('data.signature.signed_pdf.size'))->toBe(strlen($made['fake']->signedPdf))
            ->and($shown->json('data.signature.signed_pdf.sha256'))->toBe(hash('sha256', $made['fake']->signedPdf))
            ->and($shown->json('data.signature.signed_pdf.download_url'))->toBe("{$uri}/signed-pdf")
            ->and($bytes)->toBe($made['fake']->signedPdf)
            ->and(hash('sha256', $bytes))->toBe($shown->json('data.signature.signed_pdf.sha256'))
            ->and($file->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($file->headers->get('Content-Disposition'))->toBe('attachment; filename="Tools - version 1 (signed).pdf"')
            ->and($file->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($file->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
            ->and($file->headers->get('Content-Length'))->toBe((string) strlen($made['fake']->signedPdf))
            ->and($file->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('is named in the language of the reader', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();
        $done = versionSync($made['version']);

        $file = attachmentGet($this, $made['case']['team']['owner'], "/api/v1/inventories/{$made['case']['inventory']->ulid}/versions/{$done->ulid}/signed-pdf", ['Accept-Language' => 'fr'])->assertOk();

        expect($file->headers->get('Content-Disposition'))->toContain("filename*=utf-8''Tools%20-%20version%201%20%28sign%C3%A9e%29.pdf");
    });
});

describe('cancelling the request', function (): void {
    it('is refused to a viewer and not found by a stranger, and asks Documenso for nothing', function (string $role, int $status): void {
        $made = versionAwaiting(2);
        $calls = $made['fake']->calls;

        $response = inventoryCall($this, $made['case']['team'][$role], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/cancel'));

        $response->assertStatus($status);
        expect($made['fake']->calls)->toBe($calls)
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);

        if ($status === 403) {
            $response->assertJsonMissingPath('code');
        }
    })->with(signatureRolesForEditing());

    it('asks Documenso to cancel the envelope, tells why, and is then cancelled, for an editor and for an owner', function (string $role): void {
        $made = versionAwaiting(2);
        $made['fake']->sign('signer1@example.test');

        $response = inventoryCall($this, $made['case']['team'][$role], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/cancel'));
        $row = $made['version']->fresh();

        $response->assertOk()
            ->assertJsonPath('data.id', $made['version']->ulid)
            ->assertJsonPath('data.signature.status', 'cancelled')
            ->assertJsonPath('data.signature.error', null)
            ->assertJsonPath('data.abilities.cancel_signature', false);
        expect($made['fake']->count('POST /envelope/cancel'))->toBe(1)
            ->and($made['fake']->requestsOf('POST /envelope/cancel')[0]->data())->toBe(['envelopeId' => 'envelope_test1', 'reason' => 'This signature request was cancelled.'])
            ->and($made['fake']->status)->toBe('CANCELLED')
            ->and($row->signature_status)->toBe(SignatureStatus::Cancelled)
            ->and($row->signature_error)->toBeNull()
            // The version, its files and what was signed so far are untouched.
            ->and($row->status)->toBe(VersionStatus::Completed)
            ->and($row->pdf_path)->not->toBeNull()
            ->and($row->documenso_envelope_id)->toBe('envelope_test1')
            ->and($row->counted_bytes)->toBe($made['version']->counted_bytes);
        Queue::assertNotPushed(SyncSignature::class);
    })->with(['an editor' => 'editor', 'an owner' => 'owner']);

    it('tells the signers in the language of the person who cancels', function (): void {
        $made = versionAwaiting(1);

        inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/cancel'), headers: ['Accept-Language' => 'fr'])->assertOk();

        expect($made['fake']->requestsOf('POST /envelope/cancel')[0]->data()['reason'])->toBe('Cette demande de signature a été annulée.');
    });

    it('leaves the ability to cancel, and to nothing else, with the request', function (): void {
        $made = versionAwaiting(1);
        $uri = signatureUri($made['case']['inventory'], $made['version']);

        $before = inventoryCall($this, $made['case']['team']['editor'], 'GET', $uri)->assertOk();
        inventoryCall($this, $made['case']['team']['editor'], 'POST', $uri.'/signature/cancel')->assertOk();
        $after = inventoryCall($this, $made['case']['team']['editor'], 'GET', $uri)->assertOk();

        expect($before->json('data.abilities.cancel_signature'))->toBeTrue()
            ->and($after->json('data.abilities.cancel_signature'))->toBeFalse()
            ->and($after->json('data.abilities.retry_signature'))->toBeFalse();
    });

    it('is refused with a code when the request is not waiting for its signers, and Documenso is not asked', function (?SignatureStatus $status): void {
        $made = versionAwaiting(1);
        $made['version']->forceFill(['signature_status' => $status])->save();
        $calls = $made['fake']->calls;

        $response = inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/cancel'));

        $response->assertStatus(409)
            ->assertJsonPath('code', 'signature_not_pending')
            ->assertJsonPath('message', 'This signature request is not waiting for its signers: it cannot be cancelled.');
        expect($made['fake']->calls)->toBe($calls)
            ->and($made['version']->fresh()->signature_status)->toBe($status);
        Queue::assertNotPushed(SyncSignature::class);
    })->with([
        'asked for' => [SignatureStatus::Requested],
        'being sent' => [SignatureStatus::Sending],
        'completed' => [SignatureStatus::Completed],
        'rejected' => [SignatureStatus::Rejected],
        'cancelled already' => [SignatureStatus::Cancelled],
        'failed' => [SignatureStatus::Failed],
        'no signature asked for' => [null],
    ]);

    it('is refused when Documenso says the envelope is not pending any more, and reads it again in the background', function (int $status): void {
        $made = versionAwaiting(1);
        $made['fake']->failNext('POST /envelope/cancel', $status);

        $response = inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/cancel'));

        $response->assertStatus(409)->assertJsonPath('code', 'signature_not_pending');
        // Nothing is decided on the word of a refusal: the request is as it was, until the job reads the envelope.
        expect($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);
        Queue::assertPushed(SyncSignature::class, fn (SyncSignature $job): bool => $job->versionId === $made['version']->id);
    })->with([400, 404, 409, 422]);

    it('finds, when the last signer has signed a moment before, the request completed once the job has read it', function (): void {
        $made = versionAwaiting(1);
        $made['fake']->complete();

        // Documenso refuses to cancel what is not pending (the fake answers 400 as the real one).
        inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/cancel'))
            ->assertStatus(409)->assertJsonPath('code', 'signature_not_pending');

        Queue::pushed(SyncSignature::class)->each(fn (SyncSignature $job) => $job->handle(app(SignatureSync::class)));

        expect($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Completed)
            ->and($made['version']->fresh()->signed_pdf_sha256)->toBe(hash('sha256', $made['fake']->signedPdf));
    });

    it('is a 503 when Documenso cannot be reached, is failing or refuses the token, and nothing changes', function (?int $status): void {
        Log::spy();
        $made = versionAwaiting(1);
        $made['fake']->failNext('POST /envelope/cancel', $status);

        $response = inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/cancel'));

        $response->assertStatus(503)
            ->assertJsonPath('code', 'documenso_unavailable')
            ->assertJsonPath('message', 'The signature service cannot be used right now. Try again in a moment.');
        expect($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);
        Queue::assertNotPushed(SyncSignature::class);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'could not be cancelled'))->once();
    })->with([
        'a lost connection' => [null],
        'too many requests' => [429],
        'a server error' => [500],
        'unavailable' => [503],
        'a refused token' => [401],
        'a token without the right' => [403],
    ]);

    it('is a 503 when the instance lost its Documenso, and nothing is asked', function (): void {
        $made = versionAwaiting(1);
        config(['services.documenso.url' => null]);
        $calls = $made['fake']->calls;

        inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/cancel'))
            ->assertStatus(503)->assertJsonPath('code', 'documenso_unavailable');

        expect($made['fake']->calls)->toBe($calls);
    });

    it('is a 404 for a version of another inventory or one that does not exist', function (): void {
        $made = versionAwaiting(1);
        $other = inventoryIn($made['case']['team']['workspace'], 'Other');

        inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($other, $made['version'], '/signature/cancel'))->assertNotFound();
        inventoryCall($this, $made['case']['team']['owner'], 'POST', "/api/v1/inventories/{$made['case']['inventory']->ulid}/versions/01j0000000000000000000000x/signature/cancel")->assertNotFound();
    });

    it('needs the `write` ability of a token, and no other', function (): void {
        $made = versionAwaiting(1);
        $owner = $made['case']['team']['owner'];
        $uri = signatureUri($made['case']['inventory'], $made['version'], '/signature/cancel');

        tokenCall($this, tokenFor($owner, ['read', 'manage']), 'POST', $uri)->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        expect($made['fake']->count('POST /envelope/cancel'))->toBe(0);

        tokenCall($this, tokenFor($owner, ['write']), 'POST', $uri)->assertOk()->assertJsonPath('data.signature.status', 'cancelled');
    });

    it('is not undone by the webhook that follows: a cancelled request is not followed', function (): void {
        $made = versionAwaiting(1);
        inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/cancel'))->assertOk();
        $calls = $made['fake']->calls;

        versionSync($made['version']);

        expect($made['fake']->calls)->toBe($calls)
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Cancelled);
    });
});

describe('sending the request again', function (): void {
    it('is refused to a viewer and not found by a stranger, and changes nothing', function (string $role, int $status): void {
        $made = signatureFailed();
        Queue::fake();

        inventoryCall($this, $made['case']['team'][$role], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/retry'))->assertStatus($status);

        expect($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Failed);
        Queue::assertNothingPushed();
    })->with(signatureRolesForEditing());

    it('puts the request back to requested and queues the job, for an editor and for an owner', function (string $role): void {
        $made = signatureFailed(SignatureError::Stale);
        Queue::fake();

        $response = inventoryCall($this, $made['case']['team'][$role], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/retry'));

        $response->assertStatus(202)
            ->assertJsonPath('data.id', $made['version']->ulid)
            ->assertJsonPath('data.signature.status', 'requested')
            ->assertJsonPath('data.signature.error', null)
            ->assertJsonPath('data.abilities.retry_signature', false);
        expect($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Requested)
            ->and($made['version']->fresh()->signature_error)->toBeNull();
        Queue::assertPushed(RequestSignatures::class, 1);
        Queue::assertPushed(RequestSignatures::class, fn (RequestSignatures $job): bool => $job->version->is($made['version']));
    })->with(['an editor' => 'editor', 'an owner' => 'owner']);

    it('sends the request from where it stopped: an envelope that exists is not created again', function (): void {
        $made = signatureFailed();
        Queue::fake();

        inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/retry'))->assertStatus(202);
        Queue::pushed(RequestSignatures::class)->each(fn (RequestSignatures $job) => $job->handle(app(SignatureRequests::class)));

        expect($made['fake']->created)->toBe(1)
            ->and($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);
    });

    it('sends a request that never reached Documenso, whatever failed it', function (SignatureError $error): void {
        $fake = versionDocumenso();
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1)));
        $version->forceFill(['signature_status' => SignatureStatus::Failed, 'signature_error' => $error])->save();
        Queue::fake();

        inventoryCall($this, $case['team']['owner'], 'POST', signatureUri($case['inventory'], $version, '/signature/retry'))->assertStatus(202);
        Queue::pushed(RequestSignatures::class)->each(fn (RequestSignatures $job) => $job->handle(app(SignatureRequests::class)));

        expect($fake->created)->toBe(1)
            ->and($version->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);
    })->with([SignatureError::DocumensoUnavailable, SignatureError::DocumensoRefused, SignatureError::Stale]);

    it('is refused with a code when the request did not fail, or its version did not complete', function (?SignatureStatus $status, VersionStatus $version): void {
        $made = versionAwaiting(1);
        $made['version']->forceFill(['signature_status' => $status, 'status' => $version])->save();
        Queue::fake();

        $response = inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/retry'));

        $response->assertStatus(409)
            ->assertJsonPath('code', 'signature_not_failed')
            ->assertJsonPath('message', 'This signature request did not fail: there is nothing to send again.');
        expect($made['version']->fresh()->signature_status)->toBe($status);
        Queue::assertNothingPushed();
    })->with([
        'asked for' => [SignatureStatus::Requested, VersionStatus::Completed],
        'being sent' => [SignatureStatus::Sending, VersionStatus::Completed],
        'awaiting' => [SignatureStatus::Awaiting, VersionStatus::Completed],
        'completed' => [SignatureStatus::Completed, VersionStatus::Completed],
        'rejected' => [SignatureStatus::Rejected, VersionStatus::Completed],
        'cancelled' => [SignatureStatus::Cancelled, VersionStatus::Completed],
        'no signature asked for' => [null, VersionStatus::Completed],
        'failed, on a version that failed' => [SignatureStatus::Failed, VersionStatus::Failed],
        'failed, on a version still being written' => [SignatureStatus::Failed, VersionStatus::Running],
    ]);

    it('is refused when the instance has no Documenso, after the state is judged', function (): void {
        $made = signatureFailed();
        config(['services.documenso.api_key' => '']);
        Queue::fake();

        inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/retry'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'signature_unavailable');

        expect($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Failed);
        Queue::assertNothingPushed();

        // A request that did not fail is told so, not that the instance has no Documenso.
        $made['version']->forceFill(['signature_status' => SignatureStatus::Awaiting])->save();

        inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/retry'))
            ->assertStatus(409)->assertJsonPath('code', 'signature_not_failed');
    });

    it('is done once when it is asked twice: the second call finds a request that did not fail', function (): void {
        $made = signatureFailed();
        Queue::fake();
        $uri = signatureUri($made['case']['inventory'], $made['version'], '/signature/retry');

        inventoryCall($this, $made['case']['team']['owner'], 'POST', $uri)->assertStatus(202);
        inventoryCall($this, $made['case']['team']['owner'], 'POST', $uri)->assertStatus(409)->assertJsonPath('code', 'signature_not_failed');

        Queue::assertPushed(RequestSignatures::class, 1);
    });

    it('does not fail the call when the queue cannot be reached: the request waits, requested', function (): void {
        Log::spy();
        $made = signatureFailed();
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('The queue is down.')));

        inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($made['case']['inventory'], $made['version'], '/signature/retry'))
            ->assertStatus(202)->assertJsonPath('data.signature.status', 'requested');

        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'could not be queued'))->once();
    });

    it('is a 404 for a version of another inventory', function (): void {
        $made = signatureFailed();
        $other = inventoryIn($made['case']['team']['workspace'], 'Other');

        inventoryCall($this, $made['case']['team']['owner'], 'POST', signatureUri($other, $made['version'], '/signature/retry'))->assertNotFound();
    });

    it('needs the `write` ability of a token, and no other', function (): void {
        $made = signatureFailed();
        $owner = $made['case']['team']['owner'];
        $uri = signatureUri($made['case']['inventory'], $made['version'], '/signature/retry');

        tokenCall($this, tokenFor($owner, ['read', 'manage']), 'POST', $uri)->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        expect($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Failed);

        tokenCall($this, tokenFor($owner, ['write']), 'POST', $uri)->assertStatus(202);
    });

    it('is offered by the version exactly when it can be done', function (): void {
        $made = signatureFailed();
        $uri = signatureUri($made['case']['inventory'], $made['version']);

        $failed = inventoryCall($this, $made['case']['team']['editor'], 'GET', $uri)->assertOk();
        $viewer = inventoryCall($this, $made['case']['team']['viewer'], 'GET', $uri)->assertOk();
        $made['version']->forceFill(['status' => VersionStatus::Failed, 'error' => VersionError::GenerationFailed])->save();
        $unfinished = inventoryCall($this, $made['case']['team']['editor'], 'GET', $uri)->assertOk();

        expect($failed->json('data.abilities.retry_signature'))->toBeTrue()
            ->and($failed->json('data.signature.status'))->toBe('failed')
            ->and($failed->json('data.signature.error'))->toBe('The signature service could not be reached. Try again later.')
            ->and($viewer->json('data.abilities.retry_signature'))->toBeFalse()
            ->and($unfinished->json('data.abilities.retry_signature'))->toBeFalse();
    });
});
