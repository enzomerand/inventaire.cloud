<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Number;

/**
 * A size in bytes as a person reads it, in the language of the request.
 *
 * It keeps what `Number::fileSize()` does for English ("193 B", "1.7 KB", one decimal at most, units by 1024, the next
 * unit from 0.9 of the one below), but writes the number the way the locale does ("1,7") and the unit the way the
 * language does: French counts in octets ("193 o", "1,7 ko", "20 Mo"), like the front does (see formatSize() in
 * resources/js/fields/files.js). The units are translated as whole strings (`:size KB`), so that a language can put
 * the unit before the number or join them differently.
 *
 * The scale stops at terabytes: no file, no storage quota of this application comes near a petabyte.
 */
final class FileSize
{
    /** The most decimals shown ("1.5 MB", never "1.53 MB"). */
    private const MAX_DECIMALS = 1;

    /** Where the next unit starts, as a share of the unit below: 0.9 of a kilobyte is already written as one. */
    private const NEXT_UNIT_FROM = 0.9;

    /**
     * The size in the unit that suits it, in the current locale.
     *
     * @param  int  $bytes  A number of bytes.
     */
    public static function format(int $bytes): string
    {
        $value = (float) $bytes;
        $unit = 0;

        while (abs($value) / 1024 > self::NEXT_UNIT_FROM && $unit < 4) {
            $value /= 1024;
            $unit++;
        }

        $size = Number::format($value, maxPrecision: self::MAX_DECIMALS, locale: app()->getLocale());

        return match ($unit) {
            0 => __(':size B', ['size' => $size]),
            1 => __(':size KB', ['size' => $size]),
            2 => __(':size MB', ['size' => $size]),
            3 => __(':size GB', ['size' => $size]),
            default => __(':size TB', ['size' => $size]),
        };
    }
}
