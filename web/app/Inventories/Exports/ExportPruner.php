<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Jobs\GenerateExport;
use App\Models\Export;
use Illuminate\Database\Eloquent\Builder;

/**
 * Clears out the exports that are over: the ones that expired are deleted with their file, and the ones that never ended are given
 * up on. Run every hour by `inventaire:prune-exports`.
 *
 * An export that never ended has lost its worker (the queue was not running, the process was killed): it is marked `failed`
 * (`stale`) so that the person is told, and kept as long as any other failed export before it expires in turn. What "never ended"
 * means depends on where it is stuck, because a queue with a backlog makes an export wait without anything being wrong:
 *
 * - `pending` is stale when it was **asked for** more than `inventories.export_stale_minutes` ago: it is marked failed even
 *   though its job may still be queued behind a long backlog. That job then exits without writing anything, because the export is
 *   no longer pending ({@see ExportGenerator::run()});
 * - `running` is stale when it was **started** more than the time a job may run ({@see GenerateExport::TIMEOUT}) plus a margin
 *   ago, whenever it was asked for: one that waited for hours behind others and started a minute ago is left to finish.
 *
 * The scratch files of the exports that were being written when a worker died are deleted when an hour old.
 */
final class ExportPruner
{
    /** Seconds added to the time a job may run before a running export is given up on: the queue's own timeout kills the job first. */
    public const RUNNING_MARGIN_SECONDS = 300;

    /**
     * Gets the generator that gives up on an export.
     */
    public function __construct(private readonly ExportGenerator $generator) {}

    /**
     * Prunes.
     *
     * @return array{expired: int, stale: int, scratch: int} How many exports were deleted, how many were given up on, how many scratch files were deleted.
     */
    public function prune(): array
    {
        $stale = 0;
        $expired = 0;

        $waitingSince = now()->subMinutes(config()->integer('inventories.export_stale_minutes'));
        $runningSince = now()->subSeconds(GenerateExport::TIMEOUT + self::RUNNING_MARGIN_SECONDS);

        Export::query()
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $pending): Builder => $pending
                    ->where('status', ExportStatus::Pending->value)
                    ->where('created_at', '<', $waitingSince))
                ->orWhere(fn (Builder $running): Builder => $running
                    ->where('status', ExportStatus::Running->value)
                    // A running row always has its start; one without (a row written by hand) is judged on the day it was asked for.
                    ->where(fn (Builder $started): Builder => $started
                        ->where('started_at', '<', $runningSince)
                        ->orWhere(fn (Builder $unknown): Builder => $unknown->whereNull('started_at')->where('created_at', '<', $runningSince)))))
            ->chunkById(100, function ($exports) use (&$stale): void {
                foreach ($exports as $export) {
                    $this->generator->fail($export, ExportError::Stale);
                    $stale++;
                }
            });

        Export::query()
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($exports) use (&$expired): void {
                foreach ($exports as $export) {
                    $export->delete();
                    $expired++;
                }
            });

        return ['expired' => $expired, 'stale' => $stale, 'scratch' => ExportTemp::prune()];
    }
}
