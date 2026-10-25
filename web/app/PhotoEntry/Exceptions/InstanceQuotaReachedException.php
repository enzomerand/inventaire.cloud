<?php

declare(strict_types=1);

namespace App\PhotoEntry\Exceptions;

use App\PhotoEntry\PhotoAnalysisQuota;
use RuntimeException;

/**
 * The instance has run as many photo analyses today as its cap allows (`inventories.photo_analyses_per_day_instance`, see
 * {@see PhotoAnalysisQuota}), whoever is asking. The API answers it with a 429 of its own, `Retry-After` included, so that the person is
 * told it is the instance that is out of analyses, not their own count.
 */
final class InstanceQuotaReachedException extends RuntimeException
{
    /** The machine-readable code of the 429. */
    public const CODE = 'photo_analyses_instance_limit';

    /**
     * Builds the refusal.
     *
     * @param  int  $limit  The cap of the instance.
     * @param  int  $retryAfter  The seconds until the count starts again.
     */
    public function __construct(public readonly int $limit, public readonly int $retryAfter)
    {
        parent::__construct('The instance reached its daily number of photo analyses.');
    }
}
