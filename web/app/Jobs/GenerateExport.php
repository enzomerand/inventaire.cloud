<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Inventories\Exports\ExportError;
use App\Inventories\Exports\ExportGenerator;
use App\Models\Export;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Writes the file of an export in the background, so that a spreadsheet, a PDF or a zip of photos does not depend on the time a
 * request may take ({@see ExportGenerator}).
 *
 * It is tried once: the generator records a failure on the export itself, and writing a file again from the start is for the person
 * to ask (a new export), not for the queue to repeat in silence. It may run for 15 minutes ({@see self::TIMEOUT}); the queue
 * connection must wait longer than that before it hands a running job to another worker (`retry_after` of `config/queue.php`,
 * 960 seconds).
 *
 * The job goes on its own queue, `exports` ({@see self::QUEUE}), so that a long export does not hold up the short jobs (mails,
 * file purges) behind it. The Docker example runs a worker for each: `worker` on `default` and `export-worker` on `exports`
 * (`QUEUE_WORKER_QUEUES` of the image's entrypoint). A single worker, with that variable unset, listens to both, `default` first
 * (`queue:work --queue=default,exports`), as `composer dev` does: the exports are written, but a long one holds up the short jobs
 * that arrive behind it.
 *
 * The export is serialized by its key, not as a snapshot (`SerializesModels`) and read again when the job runs: one deleted before
 * that is no failure, the job is dropped (`deleteWhenMissingModels`). One that was killed (a timeout, a worker stopped) is recorded
 * as failed; one that was lost altogether is given up on by `inventaire:prune-exports`.
 */
final class GenerateExport implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** The queue the jobs are put on. */
    public const QUEUE = 'exports';

    /** The seconds the job may run. */
    public const TIMEOUT = 900;

    /** The job is tried once. */
    public int $tries = 1;

    /** The seconds the job may run. */
    public int $timeout = self::TIMEOUT;

    /** An export that is deleted before its job runs is not an error. */
    public bool $deleteWhenMissingModels = true;

    /**
     * Remembers which export to write, and puts the job on its queue.
     */
    public function __construct(public readonly Export $export)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Writes the export.
     */
    public function handle(ExportGenerator $generator): void
    {
        $generator->run($this->export);
    }

    /**
     * Records the failure of a job the queue gave up on (timeout, too many tries).
     */
    public function failed(Throwable $exception): void
    {
        app(ExportGenerator::class)->fail($this->export, ExportError::GenerationFailed);
    }
}
