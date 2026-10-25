<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use RuntimeException;

/**
 * The photos could not be analysed. `error` is the reason the person is told, and the code stored with the failed analysis.
 *
 * It carries no trace of the request that failed: the photos and the key of the instance must not end up in a log or in the failed jobs.
 */
final class PhotoAnalysisException extends RuntimeException
{
    /**
     * Says why the analysis failed.
     */
    public function __construct(public readonly PhotoAnalysisError $error, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $error->value);
    }
}
