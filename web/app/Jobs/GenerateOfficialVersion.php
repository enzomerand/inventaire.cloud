<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Inventories\Versions\VersionError;
use App\Inventories\Versions\VersionGenerator;
use App\Models\InventoryVersion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Writes the PDF of an official version in the background, from its snapshot ({@see VersionGenerator}), so that laying out thousands of
 * cells does not depend on the time a request may take.
 *
 * It is tried once: the generator records a failure on the version itself, and writing a document again from the start is for the
 * person to ask (a new version), not for the queue to repeat in silence. It may run for 15 minutes ({@see GenerateExport::TIMEOUT}); the queue
 * connection must wait longer than that before it hands a running job to another worker (`retry_after` of `config/queue.php`, 960
 * seconds). It goes on the queue of the exports ({@see GenerateExport::QUEUE}): a heavy job like theirs, which must not hold up the
 * short ones.
 *
 * The version is serialized by its key, not as a snapshot (`SerializesModels`) and read again when the job runs: one deleted before
 * that is no failure, the job is dropped (`deleteWhenMissingModels`). One that was killed (a timeout, a worker stopped) is recorded
 * as failed; one that was lost altogether is given up on by `inventaire:sync-versions`.
 */
final class GenerateOfficialVersion implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** The job is tried once. */
    public int $tries = 1;

    /** The seconds the job may run. */
    public int $timeout = GenerateExport::TIMEOUT;

    /** A version that is deleted before its job runs is not an error. */
    public bool $deleteWhenMissingModels = true;

    /**
     * Remembers which version to write, and puts the job on its queue.
     */
    public function __construct(public readonly InventoryVersion $version)
    {
        $this->onQueue(GenerateExport::QUEUE);
    }

    /**
     * Writes the PDF.
     */
    public function handle(VersionGenerator $generator): void
    {
        $generator->run($this->version);
    }

    /**
     * Records the failure of a job the queue gave up on (timeout, too many tries).
     */
    public function failed(Throwable $exception): void
    {
        app(VersionGenerator::class)->fail($this->version, VersionError::GenerationFailed);
    }
}
