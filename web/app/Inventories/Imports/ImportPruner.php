<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Exports\ExportTemp;
use App\Jobs\GenerateExport;
use App\Models\Import;
use Illuminate\Database\Eloquent\Builder;

/**
 * Clears out the imports that are over: the ones that expired are deleted with their file, and the ones that never ended are given up
 * on. Run every hour by `inventaire:prune-imports`.
 *
 * An import that expired is a report nobody looked at for a day, or a file that was sent and never started. One that never ended has lost
 * its worker (the queue was not running, the process was killed): it is marked `failed` (`stale`), its source file deleted, so that the
 * person is told, and kept as long as any other failed import before it expires in turn. What "never ended" means depends on where it is
 * stuck, because a queue with a backlog makes an import wait without anything being wrong:
 *
 * - `pending` is stale when it was **started** more than `inventories.import_stale_minutes` ago: it is marked failed even though its
 *   job may still be queued behind a long backlog. That job then exits without doing anything, because the import is no longer pending
 *   ({@see ImportRunner::run()}). The time counts from the day the person started it, not from the day they sent the file, which may be
 *   a day earlier;
 * - `running` is stale when it began more than the time a job may run ({@see GenerateExport::TIMEOUT}) plus a margin ago.
 *
 * The scratch files of the imports that were being read when a worker died are deleted when an hour old.
 */
final class ImportPruner
{
    /** Seconds added to the time a job may run before a running import is given up on: the queue's own timeout kills the job first. */
    public const RUNNING_MARGIN_SECONDS = 300;

    /**
     * Gets the runner that gives up on an import.
     */
    public function __construct(private readonly ImportRunner $runner) {}

    /**
     * Prunes.
     *
     * @return array{expired: int, stale: int, scratch: int} How many imports were deleted, how many were given up on, how many scratch files were deleted.
     */
    public function prune(): array
    {
        $stale = 0;
        $expired = 0;

        $waitingSince = now()->subMinutes(config()->integer('inventories.import_stale_minutes'));
        $runningSince = now()->subSeconds(GenerateExport::TIMEOUT + self::RUNNING_MARGIN_SECONDS);

        Import::query()
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $pending): Builder => $pending
                    ->where('status', ImportStatus::Pending->value)
                    // Started when the person started it; a row without (written by hand) is judged on the day it was sent.
                    ->where(fn (Builder $started): Builder => $started
                        ->where('started_at', '<', $waitingSince)
                        ->orWhere(fn (Builder $unknown): Builder => $unknown->whereNull('started_at')->where('created_at', '<', $waitingSince))))
                ->orWhere(fn (Builder $running): Builder => $running
                    ->where('status', ImportStatus::Running->value)
                    ->where(fn (Builder $started): Builder => $started
                        ->where('started_at', '<', $runningSince)
                        ->orWhere(fn (Builder $unknown): Builder => $unknown->whereNull('started_at')->where('created_at', '<', $runningSince)))))
            ->chunkById(100, function ($imports) use (&$stale): void {
                foreach ($imports as $import) {
                    $this->runner->fail($import, ImportError::Stale);
                    $stale++;
                }
            });

        Import::query()
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($imports) use (&$expired): void {
                foreach ($imports as $import) {
                    $import->delete();
                    $expired++;
                }
            });

        return ['expired' => $expired, 'stale' => $stale, 'scratch' => ExportTemp::prune('imports')];
    }
}
