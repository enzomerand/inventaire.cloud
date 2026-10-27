<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * The timezone of the instance, for the dates the server writes for people.
 *
 * Every date is stored, computed and sent by the API in UTC (`app.timezone` is fixed to it). The zone of the instance
 * (`app.display_timezone`, chosen at installation) is only for what the server itself puts in front of a reader, such
 * as the date in the name of a download. Dates shown in a page are formatted by the browser, in the zone of the reader.
 *
 * Since the browser and the server may show the same moment in two zones, **every time of day the server writes states its
 * zone**: after the time in a text made to be read ({@see self::offset()}), in the header of the column in a spreadsheet
 * (where the cells stay exact values, {@see self::zone()}).
 */
final class DisplayTime
{
    /**
     * The identifier of the timezone of the instance, such as "Europe/Paris".
     */
    public static function zone(): string
    {
        return config()->string('app.display_timezone');
    }

    /**
     * The same moment, expressed in the timezone of the instance. The given date is left as it is.
     */
    public static function of(CarbonInterface $moment): CarbonInterface
    {
        return $moment->copy()->setTimezone(self::zone());
    }

    /**
     * The short name of the zone a moment is written in, for the reader of a time: `UTC`, `UTC+1`, `UTC-4`, `UTC+5:30`.
     *
     * It is the offset the zone has **at that moment** (Paris is `UTC+1` in winter and `UTC+2` in summer), read from the timezone
     * the moment is in: give it the moment as it is written, already in the zone of the reader. It is built from the offset, not
     * from the abbreviations of the zone database or of ICU, which depend on the language (`GMT+1` in English and `UTC+1` in
     * French for the same place) and are ambiguous (`CST` is three zones): the same moment always gets the same name, in any
     * language.
     */
    public static function offset(DateTimeInterface $moment): string
    {
        $seconds = $moment->getOffset();

        if ($seconds === 0) {
            return 'UTC';
        }

        $minutes = intdiv(abs($seconds), 60);

        return sprintf('UTC%s%d%s', $seconds < 0 ? '-' : '+', intdiv($minutes, 60), $minutes % 60 === 0 ? '' : sprintf(':%02d', $minutes % 60));
    }
}
