<?php

declare(strict_types=1);

namespace App\Signatures;

use App\Database\DeadlockRetries;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Attachments\WorkspaceStorage;
use App\Inventories\Exports\ExportTemp;
use App\Inventories\Versions\SignatureError;
use App\Inventories\Versions\SignatureStatus;
use App\Jobs\RequestSignatures;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Brings a request for signatures up to date with what Documenso says of its envelope: what the `SyncSignature` job does, apart from
 * the queue, and what the sending of a request ends with.
 *
 * **What Documenso sends is never believed.** The webhook only says that something happened to an envelope; the state of the request
 * is always read again from the API, and an envelope whose `externalId` is not the version's is refused. The envelope id a webhook
 * announces for a request that has none yet is not believed either: it is read, must carry the ULID of the version as its
 * `externalId` (a missing one fails the check), and is only then recorded, in one update that holds only while the request is
 * `sending` with no envelope ({@see self::sync()}). An id already recorded is never replaced by one a webhook gives.
 *
 * **Followed** is every request that has an envelope and has not ended ({@see SignatureStatus::unfinished()}): `requested`, `sending`,
 * `awaiting` and `failed` (a request whose last call to Documenso failed may nevertheless have its envelope pending there, with the
 * signers emailed). Every change is one update conditional on that, so that two workers (a webhook and the schedule, say) cannot undo
 * one another, and a request cancelled or deleted meanwhile is not brought back.
 *
 * What the envelope's `status` does to the request:
 *
 * - `DRAFT`: nothing yet (it is being sent); the signers are updated. A request that is `sending` and whose draft has not moved for
 *   {@see self::RESUME_AFTER_MINUTES} minutes is taken up again by the job that sends it ({@see RequestSignatures}), which resumes where
 *   it stopped: the job that was sending it is gone;
 * - `PENDING`: the signers are updated; a request that was `requested`, `sending` or `failed` becomes `awaiting` (Documenso has sent
 *   the emails);
 * - `COMPLETED`: the signed PDF is downloaded to a scratch file, hashed and stored as `signed.pdf`, and counted against the storage of
 *   the workspace under its lock together with the move to `completed`, so that the counter and `counted_bytes` never disagree;
 * - `REJECTED` and `CANCELLED`: the request becomes `rejected` or `cancelled`;
 * - an envelope that is gone (`404`): `cancelled`, with the error `envelope_missing`.
 *
 * `signature_synced_at` is written each time a state has been read; `updated_at` is not: it says when the request last moved, which is
 * what the schedule judges a request stuck by. A failure that is the service's (it cannot be reached, it is failing) is thrown, for the
 * queue to try again; one that is the call's (a refusal) is written in the log and not tried again before the schedule does.
 */
final class SignatureSync
{
    /** The name of the signed PDF in the folder of its version. */
    public const SIGNED_PDF_NAME = 'signed.pdf';

    /** Minutes a request may stay `sending` with a draft envelope before a read of it has the job that sends it run again. */
    public const RESUME_AFTER_MINUTES = 5;

    /**
     * Gets the client, the database, the locks, the storage and the counter of the workspace.
     */
    public function __construct(
        private readonly DocumensoClient $client,
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly AttachmentStorage $storage,
        private readonly WorkspaceStorage $usage,
    ) {}

    /**
     * Reads the envelope of a request and brings the request up to date. Does nothing for a version that is gone or whose request is
     * not being followed (no envelope, or ended).
     *
     * @param  string|null  $candidate  An envelope id a webhook announced for a request that is `sending` and has none: it is recorded
     *                                  only when Documenso says, by the API, that it is the envelope of this very version.
     *
     * @throws DocumensoException When Documenso cannot be reached or is failing (`retryable()`): the caller tries again.
     * @throws RuntimeException When the signed PDF cannot be stored.
     */
    public function sync(int $versionId, ?string $candidate = null): void
    {
        $version = InventoryVersion::query()->find($versionId);

        if ($version === null) {
            return;
        }

        $envelope = null;

        if ($candidate !== null && $version->documenso_envelope_id === null && $version->signature_status === SignatureStatus::Sending) {
            $envelope = $this->adopt($version, $candidate);

            if ($envelope === null) {
                return;
            }

            $version = InventoryVersion::query()->find($versionId);
        }

        if ($version === null || ! self::followed($version)) {
            return;
        }

        try {
            $envelope ??= $this->client->getEnvelope((string) $version->documenso_envelope_id);
        } catch (DocumensoException $exception) {
            if ($exception->notFound()) {
                $this->envelopeMissing($version);

                return;
            }

            $this->refused($version, $exception);

            return;
        }

        try {
            $this->apply($version, $envelope);
        } catch (DocumensoException $exception) {
            $this->refused($version, $exception);
        }
    }

    /**
     * Applies an envelope, as Documenso just gave it, to the request of a version.
     *
     * @param  array<string, mixed>  $envelope  From {@see DocumensoClient::getEnvelope()}.
     *
     * @throws DocumensoException When the signed PDF cannot be downloaded (retryable or not).
     * @throws RuntimeException When the signed PDF cannot be stored.
     */
    public function apply(InventoryVersion $version, array $envelope): void
    {
        $external = $envelope['externalId'] ?? null;

        if (is_string($external) && $external !== '' && $external !== $version->ulid) {
            Log::warning('An envelope of Documenso is not the one of the version it was recorded for: it is not followed.', ['version' => $version->ulid, 'envelope' => $version->documenso_envelope_id]);
            $this->touch($version);

            return;
        }

        $signers = SignerStates::merge($version->signers ?? [], $envelope['recipients'] ?? null);

        match (strtoupper((string) ($envelope['status'] ?? ''))) {
            'DRAFT' => $this->draft($version, $signers),
            'PENDING' => $this->pending($version, $signers),
            'COMPLETED' => $this->completed($version, $envelope, $signers),
            'REJECTED' => $this->record($version, $signers, ['signature_status' => SignatureStatus::Rejected->value, 'signature_error' => null, 'updated_at' => now()]),
            'CANCELLED' => $this->record($version, $signers, ['signature_status' => SignatureStatus::Cancelled->value, 'signature_error' => null, 'updated_at' => now()]),
            default => $this->unknown($version, $envelope),
        };
    }

    /**
     * Whether a request is one to follow: Documenso gave it an envelope and it has not ended ({@see SignatureStatus::unfinished()}).
     */
    public static function followed(InventoryVersion $version): bool
    {
        return in_array($version->signature_status, SignatureStatus::unfinished(), true) && $version->documenso_envelope_id !== null;
    }

    /**
     * Reads the envelope a webhook announced for a request that has none, and records it when it is the one of this version.
     *
     * Nothing a webhook says is recorded without this check: the envelope is read through the API and must carry the ULID of the version
     * as its `externalId`, exactly (an envelope with no `externalId` is not the version's). The id is then written by an update that
     * holds only while the request is still `sending` with no envelope, so that an id recorded meanwhile (by the job that sent the
     * request, say) is never replaced.
     *
     * @return array<string, mixed>|null The envelope, when it is now the one of the request; null when it is not (or cannot be told).
     *
     * @throws DocumensoException When Documenso cannot be reached or is failing (`retryable()`): the caller tries again.
     */
    private function adopt(InventoryVersion $version, string $candidate): ?array
    {
        try {
            $envelope = $this->client->getEnvelope($candidate);
        } catch (DocumensoException $exception) {
            if ($exception->retryable()) {
                throw $exception;
            }

            Log::warning('An envelope announced by a webhook could not be read: it is not adopted.', ['version' => $version->ulid, 'envelope' => $candidate, 'status' => $exception->status(), 'reason' => $exception->code()]);

            return null;
        }

        if (($envelope['externalId'] ?? null) !== $version->ulid) {
            Log::warning('An envelope announced by a webhook is not the one of the version it names: it is not adopted.', ['version' => $version->ulid, 'envelope' => $candidate]);

            return null;
        }

        $stored = InventoryVersion::query()
            ->whereKey($version->getKey())
            ->where('signature_status', SignatureStatus::Sending->value)
            ->whereNull('documenso_envelope_id')
            ->update(['documenso_envelope_id' => $candidate]);

        // Whoever recorded an id first wins; if it is this very one, there is nothing to undo.
        if ($stored !== 1 && ! InventoryVersion::query()->whereKey($version->getKey())->where('documenso_envelope_id', $candidate)->exists()) {
            return null;
        }

        return $envelope;
    }

    /**
     * The envelope is still a draft: the signers are updated. A request that is `sending` and whose draft has not moved for a while is
     * handed to the job that sends it again, which resumes where it stopped (it never stays `sending` for ever: the schedule gives up on
     * it later if even that does not move it).
     *
     * @param  list<array<string, mixed>>  $signers
     */
    private function draft(InventoryVersion $version, array $signers): void
    {
        $this->record($version, $signers);

        if ($version->signature_status !== SignatureStatus::Sending || $version->updated_at === null || $version->updated_at->greaterThan(now()->subMinutes(self::RESUME_AFTER_MINUTES))) {
            return;
        }

        try {
            RequestSignatures::dispatch($version);
        } catch (Throwable $exception) {
            Log::error('The job that sends a request for signatures could not be queued again.', ['version' => $version->ulid, 'exception' => $exception]);
        }
    }

    /**
     * The signers are updated, and a request that was asked for, being sent or failed is now awaiting them (Documenso has sent the
     * emails: the call that was to say so may have been lost).
     *
     * @param  list<array<string, mixed>>  $signers
     */
    private function pending(InventoryVersion $version, array $signers): void
    {
        InventoryVersion::query()
            ->whereKey($version->getKey())
            ->whereIn('signature_status', [SignatureStatus::Requested->value, SignatureStatus::Sending->value, SignatureStatus::Failed->value])
            ->update(['signature_status' => SignatureStatus::Awaiting->value, 'signature_error' => null, 'signature_requested_at' => now()]);

        $this->record($version, $signers);
    }

    /**
     * Everybody signed: the signed PDF is stored and the request is completed.
     *
     * @param  array<string, mixed>  $envelope
     * @param  list<array<string, mixed>>  $signers
     */
    private function completed(InventoryVersion $version, array $envelope, array $signers): void
    {
        $directory = $version->directory() ?? throw new RuntimeException('The snapshot of the version is not where a version keeps it.');
        $item = $this->itemOf($envelope) ?? throw DocumensoException::invalidResponse('GET /envelope/{id}', 'no envelope item');
        $path = $directory.'/'.self::SIGNED_PDF_NAME;
        $scratch = ExportTemp::file('versions', 'signed-');

        // To a scratch file and from it to the disk: the signed PDF is never held in memory.
        try {
            $size = $this->client->downloadSigned($item, $scratch);
            $sha256 = (string) hash_file('sha256', $scratch);
            $this->storage->writeFrom($version->disk, $path, $scratch);
        } finally {
            @unlink($scratch);
        }

        if (! $this->complete($version, $path, $size, $sha256, $this->encode($signers))) {
            $this->discard($version, $path);
        }
    }

    /**
     * Records the signed PDF and counts it against the storage of the workspace, in one decision under the lock of the workspace.
     *
     * @param  string  $signers  The signers, as JSON.
     * @return bool Whether the request was completed: false when it has ended meanwhile (deleted, cancelled, or completed by another worker).
     */
    private function complete(InventoryVersion $version, string $path, int $size, string $sha256, string $signers): bool
    {
        $workspaceId = Inventory::query()->whereKey($version->inventory_id)->value('workspace_id');

        if ($workspaceId === null) {
            return false;
        }

        return DeadlockRetries::run(fn (): bool => $this->db->transaction(function () use ($version, $workspaceId, $path, $size, $sha256, $signers): bool {
            $workspace = $this->locks->workspaces([$workspaceId])->first();

            if ($workspace === null) {
                return false;
            }

            // Read under the lock of the workspace, which every writer of `counted_bytes` holds: it is the figure to add to.
            $counted = InventoryVersion::query()->whereKey($version->getKey())->value('counted_bytes');

            if ($counted === null) {
                return false;
            }

            $completed = InventoryVersion::query()
                ->whereKey($version->getKey())
                ->whereIn('signature_status', SignatureStatus::unfinishedValues())
                ->update([
                    'signature_status' => SignatureStatus::Completed->value,
                    'signature_error' => null,
                    'signers' => $signers,
                    'signed_pdf_path' => $path,
                    'signed_pdf_size' => $size,
                    'signed_pdf_sha256' => $sha256,
                    'counted_bytes' => (int) $counted + $size,
                    'signature_completed_at' => now(),
                    'signature_synced_at' => now(),
                ]);

            if ($completed !== 1) {
                return false;
            }

            $this->usage->move($workspace, $size);

            return true;
        }));
    }

    /**
     * Deletes the signed PDF that was written for a request that did not take it, unless it is the very file another worker
     * recorded for the version (they share a path).
     */
    private function discard(InventoryVersion $version, string $path): void
    {
        $recorded = InventoryVersion::query()->whereKey($version->getKey())->value('signed_pdf_path');

        if ($recorded !== $path) {
            $this->storage->purgeAfterCommit($version->disk, [$path]);
        }
    }

    /**
     * The envelope is gone from Documenso: the request ends, cancelled.
     */
    private function envelopeMissing(InventoryVersion $version): void
    {
        $this->record($version, $version->signers ?? [], ['signature_status' => SignatureStatus::Cancelled->value, 'signature_error' => SignatureError::EnvelopeMissing->value, 'updated_at' => now()]);
    }

    /**
     * An envelope in a state this application does not know: written in the log, and read again later.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function unknown(InventoryVersion $version, array $envelope): void
    {
        Log::warning('An envelope of Documenso is in a state this application does not know.', ['version' => $version->ulid, 'status' => is_scalar($envelope['status'] ?? null) ? (string) $envelope['status'] : null]);
        $this->touch($version);
    }

    /**
     * A call that Documenso refused: thrown when trying again can help, written in the log otherwise and not repeated before the
     * schedule comes round (the state was not read, but the request is marked as looked at).
     *
     * @throws DocumensoException
     */
    private function refused(InventoryVersion $version, DocumensoException $exception): void
    {
        if ($exception->retryable()) {
            throw $exception;
        }

        Log::warning('Documenso refused to give the state of a signature request.', ['version' => $version->ulid, 'status' => $exception->status(), 'reason' => $exception->code()]);
        $this->touch($version);
    }

    /**
     * Writes the signers and the time the state was read, and what else the envelope changes, for a request still being followed.
     *
     * Reading a state is not moving: `updated_at` stays what it was (unless the changes bring a new one), since the schedule judges a
     * request that is stuck by the time it last moved.
     *
     * @param  list<array<string, mixed>>  $signers
     * @param  array<string, mixed>  $changes  More columns to write.
     */
    private function record(InventoryVersion $version, array $signers, array $changes = []): void
    {
        InventoryVersion::withoutTimestamps(fn (): int => InventoryVersion::query()
            ->whereKey($version->getKey())
            ->whereIn('signature_status', SignatureStatus::unfinishedValues())
            ->update(['signers' => $this->encode($signers), 'signature_synced_at' => now()] + $changes));
    }

    /**
     * Writes the time the state was read, and nothing else (not even `updated_at`).
     */
    private function touch(InventoryVersion $version): void
    {
        InventoryVersion::withoutTimestamps(fn (): int => InventoryVersion::query()->whereKey($version->getKey())->update(['signature_synced_at' => now()]));
    }

    /**
     * The id of the item that holds the document: the first one by `order`.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function itemOf(array $envelope): int|string|null
    {
        $best = null;

        foreach (is_array($envelope['envelopeItems'] ?? null) ? $envelope['envelopeItems'] : [] as $item) {
            if (! is_array($item) || (! is_string($item['id'] ?? null) && ! is_int($item['id'] ?? null)) || $item['id'] === '') {
                continue;
            }

            $order = is_int($item['order'] ?? null) ? $item['order'] : PHP_INT_MAX;

            if ($best === null || $order < $best['order']) {
                $best = ['id' => $item['id'], 'order' => $order];
            }
        }

        return $best['id'] ?? null;
    }

    /**
     * The signers as they are stored.
     *
     * @param  list<array<string, mixed>>  $signers
     */
    private function encode(array $signers): string
    {
        return json_encode($signers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
