<?php

declare(strict_types=1);

namespace App\PhotoEntry;

/**
 * Where a photo analysis is in its life: the photos were received and wait for the queue, a worker is asking Claude, it is done, or it
 * was given up.
 */
enum PhotoAnalysisStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
