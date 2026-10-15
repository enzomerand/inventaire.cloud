<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The timezone of the instance, for the dates the server writes for people.
 *
 * Every date is stored, computed and sent by the API in UTC (`app.timezone` is fixed to it). The zone of the instance
 * (`app.display_timezone`, chosen at installation) is only for what the server itself puts in front of a reader, such
 * as the date in the name of a download. Dates shown in a page are formatted by the browser, in the zone of the reader.
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
}
