<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

/**
 * Where an import is in its life: the file was analysed and the person is choosing, it was started and waits for the queue, it is
 * being written, it is done, or it was given up.
 */
enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
