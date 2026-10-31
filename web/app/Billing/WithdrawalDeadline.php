<?php

declare(strict_types=1);

namespace App\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The end of the right of withdrawal, as the consumer code counts it (article L221-19, which the terms of sale repeat in article 7): the
 * period is fourteen days; **the day the contract was concluded is not counted**; the period starts at the first hour of the first day
 * and ends at the last hour of the last day; and a period that expires on a Saturday, a Sunday or a public holiday is extended to the
 * next working day.
 *
 * Days are the days of France, the law of the contract, whatever zone the instance displays (`app.display_timezone` defaults to UTC): a
 * subscription that began at 23:30 UTC is of the next day. The days are calendar days (a day that is 23 or 25 hours long because the clocks change counts as one). The answer is the last
 * second of the last day, as a moment in UTC.
 *
 * The holidays are the French national ones: 1 January, Easter Monday, 1 May, 8 May, Ascension (39 days after Easter), Whit Monday (50
 * days after Easter), 14 July, 15 August, 1 November, 11 November and 25 December. Easter is worked out with the Gregorian algorithm (no
 * `ext-calendar`).
 */
final class WithdrawalDeadline
{
    /** The days the right lasts, the day of the conclusion not counted. */
    public const DAYS = 14;

    /** The zone the days are counted in. */
    public const ZONE = 'Europe/Paris';

    /**
     * The last moment of the right of withdrawal for a contract concluded at a moment.
     */
    public static function end(CarbonInterface $concludedAt): CarbonImmutable
    {
        $last = CarbonImmutable::instance($concludedAt)->setTimezone(self::ZONE)->startOfDay()->addDays(self::DAYS);

        while (self::isNonWorking($last)) {
            $last = $last->addDay();
        }

        return $last->endOfDay()->utc();
    }

    /**
     * Whether a day is a Saturday, a Sunday or a French public holiday.
     */
    public static function isNonWorking(CarbonInterface $day): bool
    {
        return $day->dayOfWeekIso >= 6 || isset(self::holidays((int) $day->format('Y'))[$day->format('Y-m-d')]);
    }

    /**
     * The French national holidays of a year, as `Y-m-d` keys.
     *
     * @return array<string, true>
     */
    public static function holidays(int $year): array
    {
        $easter = self::easter($year);
        $days = [
            "{$year}-01-01",
            $easter->addDay()->format('Y-m-d'),
            "{$year}-05-01",
            "{$year}-05-08",
            $easter->addDays(39)->format('Y-m-d'),
            $easter->addDays(50)->format('Y-m-d'),
            "{$year}-07-14",
            "{$year}-08-15",
            "{$year}-11-01",
            "{$year}-11-11",
            "{$year}-12-25",
        ];

        return array_fill_keys($days, true);
    }

    /**
     * Easter Sunday of a year of the Gregorian calendar (the anonymous algorithm of Meeus, Jones and Butcher), as a day.
     */
    public static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::parse(sprintf('%04d-%02d-%02d', $year, $month, $day), 'UTC');
    }
}
