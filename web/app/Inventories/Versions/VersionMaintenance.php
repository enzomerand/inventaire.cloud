<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

use App\Models\InventoryVersion;

/**
 * Keeps the official versions moving when a worker or the queue was lost: what `inventaire:sync-versions` does, every ten minutes.
 *
 * Each step gives up on the rows that did not move for too long, judged on `updated_at` (the moment the row last changed state: it is
 * written when a version is frozen, claimed by a worker, completed, or when a request for signatures is handed over), so that a queue with
 * a backlog is not mistaken for a failure as long as the work started in time:
 *
 * 1. A version `pending` or `running` for more than {@see self::GENERATION_STALE_MINUTES} minutes will never end: the job was lost
 *    (the queue was not running, the worker was killed). It is marked `failed`/`stale` and its files are purged
 *    ({@see VersionGenerator::fail()}); a job that still turns up finds it failed and does nothing.
 * 2. A request for signatures still `requested` or `sending` after {@see self::REQUEST_STALE_MINUTES} minutes, with no envelope at the
 *    signature service, will never be sent: it is marked `failed`/`stale`, so that the person can send it again. One that has an
 *    envelope is not lost: the service knows it, and it is resynchronised instead (not by this class yet).
 *
 * Every step is safe to run at any time and as often as wanted: it changes the rows that match, once.
 */
final class VersionMaintenance
{
    /** Minutes a version may stay `pending` or `running` before it is given up on: the job may run for 15, and a backlog may delay it. */
    public const GENERATION_STALE_MINUTES = 60;

    /** Minutes a request for signatures may stay `requested` or `sending` without an envelope before it is given up on. */
    public const REQUEST_STALE_MINUTES = 120;

    /**
     * Gets the generator that gives up on a version.
     */
    public function __construct(private readonly VersionGenerator $generator) {}

    /**
     * Runs every step.
     *
     * @return array{generation: int, requests: int} How many versions were given up on, how many requests for signatures were.
     */
    public function run(): array
    {
        return [
            'generation' => $this->giveUpOnUnfinishedVersions(),
            'requests' => $this->giveUpOnUnsentRequests(),
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
}
