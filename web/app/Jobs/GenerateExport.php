<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Inventories\Exports\ExportError;
use App\Inventories\Exports\ExportGenerator;
use App\Models\Export;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Writes the file of an export in the background, so that a spreadsheet, a PDF or a zip of photos does not depend on the time a
 * request may take ({@see ExportGenerator}).
 *
 * It is tried once: the generator records a failure on the export itself, and writing a file again from the start is for the person
 * to ask (a new export), not for the queue to repeat in silence. It may run for 15 minutes; the queue connection must wait longer
 * than that before it hands a running job to another worker (`retry_after` of `config/queue.php`). An export deleted before the job
 * runs is no failure: the job is dropped. One that was killed (a timeout, a worker stopped) is recorded as failed; one that was
 * lost altogether is given up on by `inventaire:prune-exports`.
 */
final class GenerateExport implements ShouldQueue
{
    use Queueable;

    /** The job is tried once. */
    public int $tries = 1;

    /** The seconds the job may run. */
    public int $timeout = 900;

    /** An export that is deleted before its job runs is not an error. */
    public bool $deleteWhenMissingModels = true;

    /**
     * Remembers which export to write.
     */
    public function __construct(public readonly Export $export) {}

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
