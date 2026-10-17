<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Inventories\Imports\ImportError;
use App\Inventories\Imports\ImportRunner;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Converts the rows of an imported file and adds them to the inventory in the background, so that ten thousand rows do not depend on
 * the time a request may take ({@see ImportRunner}).
 *
 * It is tried once: the runner records a failure on the import itself, and doing it again from the start is for the person to ask (send the
 * file again), not for the queue to repeat in silence. It may run for 15 minutes ({@see GenerateExport::TIMEOUT}); the queue connection
 * must wait longer than that before it hands a running job to another worker (`retry_after` of `config/queue.php`, 960 seconds).
 *
 * The job goes on the queue of the exports ({@see GenerateExport::QUEUE}), the one of the heavy jobs, so that it does not hold up the short
 * ones (mails, file purges) behind it: the worker that listens to `exports` does the imports too, and a single worker with
 * `--queue=default,exports` does both.
 *
 * The import is serialized by its key, not as a snapshot (`SerializesModels`) and read again when the job runs: one deleted before that
 * is no failure, the job is dropped (`deleteWhenMissingModels`). One that was killed (a timeout, a worker stopped) is recorded as failed;
 * one that was lost altogether is given up on by `inventaire:prune-imports`.
 */
final class ImportItems implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** The job is tried once. */
    public int $tries = 1;

    /** The seconds the job may run. */
    public int $timeout = GenerateExport::TIMEOUT;

    /** An import that is deleted before its job runs is not an error. */
    public bool $deleteWhenMissingModels = true;

    /**
     * Remembers which import to do, and puts the job on its queue.
     */
    public function __construct(public readonly Import $import)
    {
        $this->onQueue(GenerateExport::QUEUE);
    }

    /**
     * Does the import.
     */
    public function handle(ImportRunner $runner): void
    {
        $runner->run($this->import);
    }

    /**
     * Records the failure of a job the queue gave up on (timeout, too many tries).
     */
    public function failed(Throwable $exception): void
    {
        app(ImportRunner::class)->fail($this->import, ImportError::ImportFailed);
    }
}
