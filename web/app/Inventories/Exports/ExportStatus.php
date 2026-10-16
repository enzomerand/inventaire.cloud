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
}
