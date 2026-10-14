<?php

declare(strict_types=1);

namespace App\Installer\Probes;

use App\Installer\Settings\StorageSettings;

/**
 * Checks that file storage settings work.
 *
 * An interface because the real check talks to a disk or to a remote S3
 * service: tests and callers can swap in a fake instead of reaching a bucket.
 */
interface StorageProbe
{
    /**
     * Verifies the storage can be written, read and cleaned up.
     */
    public function check(StorageSettings $settings): ProbeResult;
}
