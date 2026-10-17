<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

/**
 * Where an official version is in the writing of its PDF: frozen and waiting for the queue, being written, ready, or given up.
 */
enum VersionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
