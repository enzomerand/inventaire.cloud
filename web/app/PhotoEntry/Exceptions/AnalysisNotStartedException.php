<?php

declare(strict_types=1);

namespace App\PhotoEntry\Exceptions;

use RuntimeException;

/**
 * The photos were received but the analysis could not be started: they could not be written to the disk, or the job could not be queued.
 * Nothing is left behind (the analysis is recorded as failed and the photos are deleted), the cause is reported, and the API answers with
 * a 503: trying again in a moment may work.
 */
final class AnalysisNotStartedException extends RuntimeException
{
    /** The machine-readable code of the 503. */
    public const CODE = 'photo_analysis_unavailable';

    /**
     * Builds the refusal.
     */
    public function __construct()
    {
        parent::__construct('The photo analysis could not be started.');
    }
}
