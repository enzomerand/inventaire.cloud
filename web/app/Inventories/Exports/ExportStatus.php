<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

/**
 * Where an export is in its life: asked for, being written, ready, or given up.
 */
enum ExportStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Whether the export is over, one way or the other: nothing will change it any more.
     */
    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
