<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Models\Export;

/**
 * Clears out the exports that are over: the ones that expired are deleted with their file, and the ones that never ended are given
 * up on. Run every hour by `inventaire:prune-exports`.
 *
 * An export that is still `pending` or `running` after `inventories.export_stale_minutes` has lost its worker (the queue was not
 * running, the process was killed): it is marked `failed` (`stale`) so that the person is told, and kept as long as any other
 * failed export before it expires in turn. The scratch files of the exports that were being written when a worker died are
 * deleted when a day old.
 */
final class ExportPruner
{
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

        Export::query()
            ->whereIn('status', [ExportStatus::Pending->value, ExportStatus::Running->value])
            ->where('created_at', '<', now()->subMinutes(config()->integer('inventories.export_stale_minutes')))
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
