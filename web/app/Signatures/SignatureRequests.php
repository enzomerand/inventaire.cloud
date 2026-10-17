<?php

declare(strict_types=1);

namespace App\Signatures;

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Exceptions\DocumensoUnavailableException;
use App\Inventories\Exceptions\SignatureNotFailedException;
use App\Inventories\Exceptions\SignatureNotPendingException;
use App\Inventories\Exceptions\SignatureUnavailableException;
use App\Inventories\Versions\SignatureError;
use App\Inventories\Versions\SignatureStatus;
use App\Inventories\Versions\VersionStatus;
use App\Jobs\RequestSignatures;
use App\Jobs\SyncSignature;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The life of a request for signatures from the moment a version is completed: sending it to Documenso, cancelling it, sending it again.
 *
 * **Sending** (what the `RequestSignatures` job does) walks a request from `requested` to `sending` to `awaiting`, and every step can
 * be repeated without harm, since the job is tried again after a failure and a request can be sent again after it failed:
 *
 * 1. `requested` becomes `sending` (a request already `sending` is being tried again);
 * 2. when the version has no envelope yet, the PDF is sent to Documenso, which creates a draft, and the id it gives is recorded **at
 *    once** (a conditional update of its own), so that no later try creates a second one; an envelope that is gone (`404`) is forgotten
 *    and created again, once. When another id was recorded meanwhile (a read of the envelope that a webhook announced, say), that one is
 *    the request's: the draft just made is deleted at Documenso and the request goes on with the recorded envelope;
 * 3. the envelope is read: the id Documenso gave each recipient is recorded with the signers;
 * 4. a draft is distributed (Documenso emails the signers), unless the version was deleted or the request moved on meanwhile;
 * 5. the request is `awaiting` ({@see SignatureSync::apply()}), which also takes whatever the envelope already is (signed, rejected).
 *
 * A failure Documenso will repeat (a refusal) ends the request, `failed` with `documenso_refused`; one that may pass (no answer, `429`,
 * `5xx`) is thrown for the queue to try again, and when the tries run out the job reports it ({@see self::fail()}) as
 * `documenso_unavailable`. A request that is `failed` keeps its envelope: sending it again resumes where it stopped.
 *
 * **Cancelling** and **sending again** are what the API offers a person on a request: the first asks Documenso at once (and tells the
 * person when it cannot), the second puts a failed request back to `requested` and queues the job. Sending again is a request for
 * signatures like the first one: it counts against the daily limit of the workspace ({@see SignatureQuota}), once the request is known to
 * be failed.
 */
final class SignatureRequests
{
    /**
     * Gets the client, the part that follows an envelope, the storage the PDF is read from, what cancels envelopes, the daily limit and
     * the database.
     */
    public function __construct(
        private readonly DocumensoClient $client,
        private readonly SignatureSync $sync,
        private readonly AttachmentStorage $storage,
        private readonly EnvelopeCleanup $envelopes,
        private readonly SignatureQuota $quota,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Sends the request for signatures of a version to Documenso, or finishes sending it.
     *
     * @throws DocumensoException When Documenso cannot be reached or is failing (`retryable()`): the queue tries again.
     * @throws RuntimeException When the PDF cannot be read from the disk, or the signed PDF cannot be stored.
     */
    public function send(InventoryVersion $version): void
    {
        InventoryVersion::query()
            ->whereKey($version->getKey())
            ->where('signature_status', SignatureStatus::Requested->value)
            ->where('status', VersionStatus::Completed->value)
            ->update(['signature_status' => SignatureStatus::Sending->value]);

        $current = InventoryVersion::query()->find($version->id);

        if ($current === null || $current->signature_status !== SignatureStatus::Sending || $current->status !== VersionStatus::Completed || $current->pdf_path === null) {
            return;
        }

        try {
            $this->transmit($current);
        } catch (DocumensoException $exception) {
            if ($exception->retryable()) {
                throw $exception;
            }

            Log::warning('Documenso refused a request for signatures.', ['version' => $current->ulid, 'status' => $exception->status(), 'reason' => $exception->code()]);
            $this->fail($current, SignatureError::DocumensoRefused);
        }
    }

    /**
     * Gives up on a request that is `requested` or `sending`: `failed`, with the reason. The envelope is kept, so that sending the
     * request again resumes where it stopped. Does nothing to a request that is over, or was taken up by another worker.
     */
    public function fail(InventoryVersion $version, SignatureError $error): void
    {
        InventoryVersion::query()
            ->whereKey($version->getKey())
            ->whereIn('signature_status', [SignatureStatus::Requested->value, SignatureStatus::Sending->value])
            ->update(['signature_status' => SignatureStatus::Failed->value, 'signature_error' => $error->value]);
    }

    /**
     * Cancels a request that awaits its signers: Documenso is asked at once (its signers are told), then the request is `cancelled`.
     *
     * When Documenso answers that the envelope is not pending (it was signed, refused or cancelled meanwhile), the request is read again
     * in the background and the person is told it cannot be cancelled.
     *
     * @return InventoryVersion The version as it is now.
     *
     * @throws SignatureNotPendingException When the request is not waiting for its signers, or Documenso says it is not pending.
     * @throws DocumensoUnavailableException When Documenso cannot be reached, or refuses the token of the instance.
     */
    public function cancel(InventoryVersion $version): InventoryVersion
    {
        $current = InventoryVersion::query()->findOrFail($version->id);

        if ($current->signature_status !== SignatureStatus::Awaiting || $current->documenso_envelope_id === null) {
            throw new SignatureNotPendingException;
        }

        try {
            $this->client->cancel($current->documenso_envelope_id, __('This signature request was cancelled.'));
        } catch (DocumensoException $exception) {
            if ($exception->status() === null || $exception->retryable() || $exception->unauthorized()) {
                Log::warning('A signature request could not be cancelled at Documenso.', ['version' => $current->ulid, 'status' => $exception->status(), 'reason' => $exception->code()]);

                throw new DocumensoUnavailableException;
            }

            SyncSignature::dispatch($current->id);

            throw new SignatureNotPendingException;
        }

        InventoryVersion::query()
            ->whereKey($current->getKey())
            ->where('signature_status', SignatureStatus::Awaiting->value)
            ->update(['signature_status' => SignatureStatus::Cancelled->value, 'signature_error' => null, 'signature_synced_at' => now()]);

        return $current->refresh();
    }

    /**
     * Sends a request that failed again: it is `requested` and the job is queued. It resumes where it stopped (an envelope that
     * exists is not created again).
     *
     * The request is taken first, by an update that holds only while it is still `failed`, and the place in the daily limit of the
     * workspace is reserved only then, in the same transaction: a request that is not (or no longer) failed is refused with `409`
     * whatever the count, and costs nothing; one that is refused by the limit stays `failed`, as it was.
     *
     * @return InventoryVersion The version as it is now.
     *
     * @throws SignatureNotFailedException When the request did not fail, or its version is not completed.
     * @throws SignatureUnavailableException When the instance has no Documenso.
     * @throws ThrottleRequestsException When the workspace has made as many requests for signatures today as it may.
     */
    public function retry(InventoryVersion $version): InventoryVersion
    {
        $current = InventoryVersion::query()->findOrFail($version->id);

        if ($current->signature_status !== SignatureStatus::Failed || $current->status !== VersionStatus::Completed) {
            throw new SignatureNotFailedException;
        }

        if (! Documenso::configured()) {
            throw new SignatureUnavailableException;
        }

        $workspaceId = Inventory::query()->whereKey($current->inventory_id)->value('workspace_id');

        if ($workspaceId === null) {
            throw (new ModelNotFoundException)->setModel(Inventory::class, [$current->inventory_id]);
        }

        $this->db->transaction(function () use ($current, $workspaceId): void {
            $moved = InventoryVersion::query()
                ->whereKey($current->getKey())
                ->where('signature_status', SignatureStatus::Failed->value)
                ->where('status', VersionStatus::Completed->value)
                ->update(['signature_status' => SignatureStatus::Requested->value, 'signature_error' => null]);

            // Another call took it first: the request is no longer failed, and nothing is counted.
            if ($moved !== 1) {
                throw new SignatureNotFailedException;
            }

            // Past the limit this throws, and the request goes back to what it was with the rollback.
            $this->quota->reserve((int) $workspaceId);
        });

        $current->refresh();

        try {
            RequestSignatures::dispatch($current);
        } catch (Throwable $exception) {
            // The request waits as `requested`: the schedule gives up on it after two hours, and the person can send it again.
            Log::error('The job that sends a request for signatures could not be queued.', ['version' => $current->ulid, 'exception' => $exception]);
        }

        return $current;
    }

    /**
     * Creates the envelope when there is none, then takes the request as far as `awaiting`.
     *
     * @throws DocumensoException
     * @throws RuntimeException
     */
    private function transmit(InventoryVersion $version): void
    {
        $envelopeId = $version->documenso_envelope_id;
        // The envelope this try made and recorded, and whether an envelope that was recorded has been forgotten already.
        $ours = null;
        $forgotten = false;

        do {
            if ($envelopeId === null) {
                $made = $this->create($version);

                // The id of the request: the one just made, or the one that was recorded first.
                $envelopeId = $this->remember($version, $made);

                if ($envelopeId === null) {
                    return;
                }

                $ours = $envelopeId === $made ? $made : null;
            }

            try {
                $envelope = $this->client->getEnvelope($envelopeId);

                break;
            } catch (DocumensoException $exception) {
                // An envelope recorded earlier that Documenso no longer has is forgotten, and created again once. The one this try
                // has just made is not: if it is not there, something is wrong.
                if (! $exception->notFound() || $envelopeId === $ours || $forgotten) {
                    throw $exception;
                }

                $this->forget($version, $envelopeId);
                $envelopeId = null;
                $forgotten = true;
            }
        } while (true);

        $distributed = false;

        if (strtoupper((string) $envelope['status']) === 'DRAFT') {
            // Not while the request was deleted, cancelled or taken up meanwhile.
            if (! InventoryVersion::query()->whereKey($version->getKey())->where('signature_status', SignatureStatus::Sending->value)->exists()) {
                return;
            }

            $this->client->distribute($envelopeId);
            $envelope['status'] = 'PENDING';
            $distributed = true;
        }

        $current = InventoryVersion::query()->find($version->id);

        if ($current === null) {
            // Deleted while the signers were emailed: their document is cancelled again.
            if ($distributed) {
                $this->envelopes->cancelAfterCommit([$envelopeId]);
            }

            return;
        }

        $this->sync->apply($current, $envelope);
    }

    /**
     * Sends the PDF of the version to Documenso, which creates a draft envelope, and gives its id.
     *
     * @throws DocumensoException
     * @throws RuntimeException When the PDF cannot be read from the disk.
     */
    private function create(InventoryVersion $version): string
    {
        $pdf = $this->storage->disk($version->disk)->get((string) $version->pdf_path);

        if (! is_string($pdf)) {
            throw new RuntimeException('The PDF of the version could not be read from the disk.');
        }

        $envelope = SignatureEnvelope::forVersion($version);

        return $this->client->createEnvelope($envelope->title, $envelope->externalId, $pdf, $envelope->recipients, $envelope->meta, $envelope->fileName);
    }

    /**
     * Records the id of the envelope that was just created, in an update of its own, as long as the request is still being sent and
     * has none.
     *
     * When another id was recorded first (a read of an envelope a webhook announced, which finds it is this version's), that one is the
     * request's: the draft just made is deleted at Documenso (it emails nobody, and nothing will ever refer to it) and the request goes
     * on with the recorded one.
     *
     * @return string|null The id of the envelope to go on with: the one just made, or the one recorded first. Null when the version is
     *                     gone or the request moved on (the envelope stays a draft at Documenso, which emails nobody).
     */
    private function remember(InventoryVersion $version, string $envelopeId): ?string
    {
        $stored = InventoryVersion::query()
            ->whereKey($version->getKey())
            ->where('signature_status', SignatureStatus::Sending->value)
            ->whereNull('documenso_envelope_id')
            ->update(['documenso_envelope_id' => $envelopeId]);

        if ($stored === 1) {
            return $envelopeId;
        }

        $recorded = InventoryVersion::query()->whereKey($version->getKey())->value('documenso_envelope_id');

        if ($recorded === $envelopeId) {
            return $envelopeId;
        }

        if (is_string($recorded) && $recorded !== '' && InventoryVersion::query()->whereKey($version->getKey())->where('signature_status', SignatureStatus::Sending->value)->exists()) {
            $this->deleteDraft($version, $envelopeId);

            return $recorded;
        }

        Log::warning('An envelope was created for a version that no longer waits for it: it stays a draft at Documenso.', ['version' => $version->ulid, 'envelope' => $envelopeId]);

        return null;
    }

    /**
     * Deletes a draft envelope this try made and does not need. Best effort: one that stays is only a draft nobody was told of, and is
     * written in the log.
     */
    private function deleteDraft(InventoryVersion $version, string $envelopeId): void
    {
        try {
            $this->client->deleteEnvelope($envelopeId);
        } catch (DocumensoException $exception) {
            Log::warning('A draft envelope that is not needed could not be deleted at Documenso: it stays there.', ['version' => $version->ulid, 'envelope' => $envelopeId, 'status' => $exception->status(), 'reason' => $exception->code()]);
        }
    }

    /**
     * Forgets the id of an envelope that Documenso no longer has.
     */
    private function forget(InventoryVersion $version, string $envelopeId): void
    {
        InventoryVersion::query()
            ->whereKey($version->getKey())
            ->where('documenso_envelope_id', $envelopeId)
            ->update(['documenso_envelope_id' => null]);
    }
}
