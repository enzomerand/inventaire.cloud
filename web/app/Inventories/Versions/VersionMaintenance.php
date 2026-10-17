<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

use App\Jobs\SyncSignature;
use App\Models\InventoryVersion;
use App\Signatures\EnvelopeCleanup;
use App\Signatures\SignatureSync;
use Illuminate\Database\DatabaseManager;

/**
 * Keeps the official versions moving when a worker or the queue was lost: what `inventaire:sync-versions` does, every ten minutes.
 *
 * Each step gives up on the rows that did not move for too long, judged on `updated_at` (the moment the row last changed state: it is
 * written when a version is frozen, claimed by a worker, completed, or when a request for signatures is handed over; reading the state
 * of a request at Documenso does not write it, see {@see SignatureSync}), so that a queue with a backlog is not mistaken for a failure
 * as long as the work started in time:
 *
 * 1. A version `pending` or `running` for more than {@see self::GENERATION_STALE_MINUTES} minutes will never end: the job was lost
 *    (the queue was not running, the worker was killed). It is marked `failed`/`stale` and its files are purged
 *    ({@see VersionGenerator::fail()}); a job that still turns up finds it failed and does nothing.
 * 2. A request for signatures still `requested` or `sending` after {@see self::REQUEST_STALE_MINUTES} minutes, with no envelope at the
 *    signature service, will never be sent: it is marked `failed`/`stale`, so that the person can send it again.
 * 3. So is one that has an envelope and has not moved for as long: it was looked at again meanwhile (below) and a read of the
 *    envelope did not take it anywhere, so nothing will. It is marked `failed`/`stale` and the envelope is let go of: forgotten by the
 *    request (sending it again makes a new one), and deleted at Documenso if it is a draft, cancelled if it is pending, in the
 *    background ({@see EnvelopeCleanup}): its signers, if it has any, must not be left with a document to sign.
 * 4. A request that has an envelope and has not ended (`requested`, `sending`, `awaiting`, or `failed`: see
 *    {@see SignatureStatus::unfinished()}) that nobody has looked at for {@see self::SYNC_STALE_MINUTES} minutes (or ever) is looked at
 *    again ({@see SyncSignature}), at most {@see self::SYNC_BATCH} at a time, the longest waiting first; one that is `requested` or
 *    `sending` only when it has not moved for as long (a job is working on it before that). This is the safety net of the webhook of
 *    the signature service, which cannot reach an instance behind a NAT or on a private address, and which may be lost; it is what
 *    finds a request that Documenso has sent although the call that said so was lost.
 *
 * Every step is safe to run at any time and as often as wanted: it changes the rows that match, once.
 */
final class VersionMaintenance
{
    /** Minutes a version may stay `pending` or `running` before it is given up on: the job may run for 15, and a backlog may delay it. */
    public const GENERATION_STALE_MINUTES = 60;

    /** Minutes a request for signatures may stay `requested` or `sending` without moving before it is given up on. */
    public const REQUEST_STALE_MINUTES = 120;

    /** Minutes after which a request that awaits its signers is looked at again. */
    public const SYNC_STALE_MINUTES = 30;

    /** The most requests looked at again in one run. */
    public const SYNC_BATCH = 100;

    /**
     * Gets the generator that gives up on a version and what lets go of the envelopes of the requests that are given up on.
     */
    public function __construct(
        private readonly VersionGenerator $generator,
        private readonly EnvelopeCleanup $envelopes,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Runs every step.
     *
     * @return array{generation: int, requests: int, synced: int} How many versions were given up on, how many requests for signatures were (the ones never sent and the ones stuck with an envelope), how many were queued to be looked at again.
     */
    public function run(): array
    {
        return [
            'generation' => $this->giveUpOnUnfinishedVersions(),
            'requests' => $this->giveUpOnUnsentRequests() + $this->giveUpOnStuckRequests(),
            'synced' => $this->syncAwaitingRequests(),
        ];
    }

    /**
     * Marks `failed`/`stale` the versions whose PDF was never written: `pending` or `running` for more than an hour.
     *
     * @return int How many versions were given up on.
     */
    public function giveUpOnUnfinishedVersions(): int
    {
        $given = 0;

        InventoryVersion::query()
            ->whereIn('status', [VersionStatus::Pending->value, VersionStatus::Running->value])
            ->where('updated_at', '<', now()->subMinutes(self::GENERATION_STALE_MINUTES))
            ->chunkById(100, function ($versions) use (&$given): void {
                foreach ($versions as $version) {
                    $this->generator->fail($version, VersionError::Stale);
                    $given++;
                }
            });

        return $given;
    }

    /**
     * Marks `failed`/`stale` the requests for signatures that were never sent: `requested` or `sending` for more than two hours, with
     * no envelope. The update is conditional on the state it was read in, so that a request handed over in the meantime is left alone.
     *
     * @return int How many requests were given up on.
     */
    public function giveUpOnUnsentRequests(): int
    {
        return InventoryVersion::query()
            ->whereIn('signature_status', [SignatureStatus::Requested->value, SignatureStatus::Sending->value])
            ->whereNull('documenso_envelope_id')
            ->where('updated_at', '<', now()->subMinutes(self::REQUEST_STALE_MINUTES))
            ->update([
                'signature_status' => SignatureStatus::Failed->value,
                'signature_error' => SignatureError::Stale->value,
            ]);
    }

    /**
     * Marks `failed`/`stale` the requests for signatures that have an envelope and did not move: `requested` or `sending` for more than
     * two hours. The envelope is forgotten by the request and let go of at Documenso in the background (deleted when it is a draft,
     * cancelled when it is pending), once the change is committed. Each update is conditional on the state it was read in, so that a
     * request handed over in the meantime is left alone, and its envelope with it.
     *
     * @return int How many requests were given up on.
     */
    public function giveUpOnStuckRequests(): int
    {
        $given = 0;
        $cutoff = now()->subMinutes(self::REQUEST_STALE_MINUTES);

        InventoryVersion::query()
            ->whereIn('signature_status', [SignatureStatus::Requested->value, SignatureStatus::Sending->value])
            ->whereNotNull('documenso_envelope_id')
            ->where('updated_at', '<', $cutoff)
            ->chunkById(100, function ($versions) use (&$given, $cutoff): void {
                foreach ($versions as $version) {
                    $envelope = (string) $version->documenso_envelope_id;

                    $this->db->transaction(function () use ($version, $envelope, $cutoff, &$given): void {
                        $changed = InventoryVersion::query()
                            ->whereKey($version->getKey())
                            ->whereIn('signature_status', [SignatureStatus::Requested->value, SignatureStatus::Sending->value])
                            ->where('documenso_envelope_id', $envelope)
                            ->where('updated_at', '<', $cutoff)
                            ->update([
                                'signature_status' => SignatureStatus::Failed->value,
                                'signature_error' => SignatureError::Stale->value,
                                'documenso_envelope_id' => null,
                            ]);

                        if ($changed === 1) {
                            $given++;
                            $this->envelopes->cancelAfterCommit([$envelope], deleteDrafts: true);
                        }
                    });
                }
            });

        return $given;
    }

    /**
     * Queues {@see SyncSignature} for the requests that have an envelope and have not ended (`requested`, `sending`, `awaiting` or
     * `failed`) and were last looked at more than half an hour ago, or never; a request that is `requested` or `sending` only when it
     * has not moved for half an hour either, since a job is working on it before that: those never looked at first, then the longest
     * waiting, up to {@see self::SYNC_BATCH} in all. The jobs are unique per version, so a request already waiting in the queue is not
     * queued twice.
     *
     * @return int How many requests were queued to be looked at again.
     */
    public function syncAwaitingRequests(): int
    {
        $followed = fn () => InventoryVersion::query()
            ->whereNotNull('documenso_envelope_id')
            ->where(function ($query): void {
                $query->whereIn('signature_status', [SignatureStatus::Awaiting->value, SignatureStatus::Failed->value])
                    ->orWhere(function ($moving): void {
                        $moving->whereIn('signature_status', [SignatureStatus::Requested->value, SignatureStatus::Sending->value])
                            ->where('updated_at', '<', now()->subMinutes(self::SYNC_STALE_MINUTES));
                    });
            });

        // The two groups are read apart: ordering a column that has NULLs puts them first on some engines and last on others.
        $never = $followed()->whereNull('signature_synced_at')->orderBy('id')->limit(self::SYNC_BATCH)->pluck('id')->all();
        $room = self::SYNC_BATCH - count($never);
        $stale = $room <= 0 ? [] : $followed()
            ->where('signature_synced_at', '<', now()->subMinutes(self::SYNC_STALE_MINUTES))
            ->orderBy('signature_synced_at')
            ->orderBy('id')
            ->limit($room)
            ->pluck('id')
            ->all();

        $queued = 0;

        foreach ([...$never, ...$stale] as $id) {
            SyncSignature::dispatch((int) $id);
            $queued++;
        }

        return $queued;
    }
}
