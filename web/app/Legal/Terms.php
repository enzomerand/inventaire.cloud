<?php

declare(strict_types=1);

namespace App\Legal;

use Carbon\CarbonImmutable;

/**
 * The terms of sale: the version in force, and the date it is shown under. Change {@see self::VERSION} whenever the wording of
 * `resources/views/legal/terms-fr.blade.php` (or its translation) changes.
 */
final class Terms
{
    /** The date of the version in force (`YYYY-MM-DD`). */
    public const VERSION = '2026-10-31';

    /**
     * The date of the version as a person reads it, in the language of the request ("31 octobre 2026", "October 31, 2026").
     */
    public static function versionDate(): string
    {
        return CarbonImmutable::parse(self::VERSION)->settings(['locale' => app()->getLocale()])->isoFormat('LL');
    }
}
