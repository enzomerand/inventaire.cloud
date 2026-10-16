<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Exceptions\InvalidImportValue;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Reads a day, a moment or a time of the day out of a cell.
 *
 * A date cell of a spreadsheet is the wall clock the person sees: its day is the day, its time (read in the time zone of the
 * application) the time. A text is read as `YYYY-MM-DD` (with `/` or `.` for `-`), or as three numbers in the order of the settings
 * ({@see ImportContext}): `15/03/2024` for day first, `03/15/2024` for month first, `24-03-15` for year first (two digits), with `/`,
 * `.` or `-` between them. Years of two digits are 1970 to 2069. A time may follow, after a space, a `T` or a comma: `HH:MM`, `HH:MM:SS`
 * or `9h30`, and an offset (`Z`, `+02:00`, `+0200`, `+02`) when the moment is a date and time; without one a moment is in the time zone of
 * the application, and a day alone is midnight. A number cell is not a date: the year `2024` would be taken for a day of 1905.
 *
 * A moment written without an offset is a wall clock reading, and the clocks of a time zone skip one hour in spring and show another
 * twice in autumn: a reading in the hour that is skipped (`02:30` the night the clocks of Paris go forward) names no instant and is refused,
 * and a reading in the hour that is shown twice is the first of the two (the summer time, an hour earlier than the winter one), so that the
 * same file always gives the same moment.
 */
final class ImportDates
{
    /** The part of a text that may follow the day: a time, and an offset. */
    private const TIME = '(?:[T\s,]+(?<hour>\d{1,2})[:h](?<minute>\d{2})(?::(?<second>\d{2})(?:[.,]\d+)?)?\s*(?<offset>Z|[+-]\d{2}(?::?\d{2})?)?)?';

    /**
     * The day a cell holds, as `YYYY-MM-DD`.
     *
     * @throws InvalidImportValue When the cell is not a date, or the day does not exist.
     */
    public static function day(ImportCell $cell, ImportContext $context): string
    {
        $value = $cell->value;

        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d');
        }

        $parts = self::parse($cell, $context);

        if ($parts === null) {
            throw new InvalidImportValue(__('":value" is not a date.', ['value' => $cell->excerpt()]));
        }

        return sprintf('%04d-%02d-%02d', $parts['year'], $parts['month'], $parts['day']);
    }

    /**
     * The moment a cell holds, as `YYYY-MM-DDTHH:MM:SS` followed by its offset from UTC (`+02:00`): the one it says, else the one of
     * the time zone of the application at that moment.
     *
     * @throws InvalidImportValue When the cell is not a date and time, or the moment does not exist.
     */
    public static function moment(ImportCell $cell, ImportContext $context): string
    {
        $value = $cell->value;
        $failure = new InvalidImportValue(__('":value" is not a date and time.', ['value' => $cell->excerpt()]));

        if ($value instanceof DateTimeImmutable) {
            $parts = ['year' => (int) $value->format('Y'), 'month' => (int) $value->format('n'), 'day' => (int) $value->format('j'), 'hour' => (int) $value->format('G'), 'minute' => (int) $value->format('i'), 'second' => (int) $value->format('s'), 'offset' => null];
        } else {
            $parts = self::parse($cell, $context) ?? throw $failure;
        }

        $wall = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $parts['year'], $parts['month'], $parts['day'], $parts['hour'], $parts['minute'], $parts['second']);

        try {
            $zone = new DateTimeZone($parts['offset'] ?? $context->zone);
            $reading = (new DateTimeImmutable($wall, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception) {
            throw $failure;
        }

        $instant = self::instantOf($reading, $zone);

        if ($instant === null) {
            throw new InvalidImportValue(__('":value" does not exist: the clocks skip that hour.', ['value' => $cell->excerpt()]));
        }

        return (new DateTimeImmutable('@'.$instant))->setTimezone($zone)->format('Y-m-d\TH:i:sP');
    }

    /**
     * The instant a wall clock reading names in a time zone, as a Unix timestamp: the reading as if it were UTC less an offset of the
     * zone, the one that was in force at that instant. Around a change of offset two offsets are possible: when both fit the reading is shown
     * twice and the earlier instant is taken, when neither does the clocks skipped it and there is none (null).
     *
     * @param  int  $reading  The reading as a timestamp of the same figures in UTC.
     */
    private static function instantOf(int $reading, DateTimeZone $zone): ?int
    {
        // The offsets a day either side of the reading: no zone changes twice in two days.
        $offsets = array_unique([
            $zone->getOffset(new DateTimeImmutable('@'.($reading - 86400))),
            $zone->getOffset(new DateTimeImmutable('@'.($reading + 86400))),
        ]);
        $instants = [];

        foreach ($offsets as $offset) {
            $instant = $reading - $offset;

            if ($zone->getOffset(new DateTimeImmutable('@'.$instant)) === $offset) {
                $instants[] = $instant;
            }
        }

        return $instants === [] ? null : min($instants);
    }

    /**
     * The time of the day a cell holds, as `HH:MM` (seconds are cut off).
     *
     * @throws InvalidImportValue When the cell is not a time of day.
     */
    public static function time(ImportCell $cell): string
    {
        $value = $cell->value;
        $failure = new InvalidImportValue(__('":value" is not a time of day.', ['value' => $cell->excerpt()]));

        if ($value instanceof DateTimeImmutable) {
            return $value->format('H:i');
        }

        if ($value instanceof DateInterval) {
            $hours = $value->d * 24 + $value->h;

            return $hours > 23 ? throw $failure : sprintf('%02d:%02d', $hours, $value->i);
        }

        if (is_int($value) || is_float($value)) {
            if ($value < 0 || $value >= 1) {
                throw $failure;
            }

            // A time in a spreadsheet is a fraction of a day. The seconds are cut off, as those of a text are, and not rounded up to the next
            // minute (9:30:50 is 09:30, and 23:59:59 must not become 24:00). A float cannot hold most times exactly (07:05 is 424.99999999999994
            // minutes): the small number keeps a time that falls just short of its minute in it.
            $minutes = min(1439, (int) floor($value * 1440 + 1e-7));

            return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        if (! is_string($value) || preg_match('/^(\d{1,2})\s*[:h]\s*(\d{2})?(?::(\d{2})(?:[.,]\d+)?)?$/iu', self::spaces($value), $found) !== 1) {
            throw $failure;
        }

        $hour = (int) $found[1];
        $minute = (int) ($found[2] ?? 0);

        return $hour > 23 || $minute > 59 || (int) ($found[3] ?? 0) > 59 ? throw $failure : sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * The numbers of a date written as text, with its time and offset when it has them; null when it is not one.
     *
     * @return array{year: int, month: int, day: int, hour: int, minute: int, second: int, offset: string|null}|null
     */
    private static function parse(ImportCell $cell, ImportContext $context): ?array
    {
        if (! is_string($cell->value)) {
            return null;
        }

        $text = self::spaces($cell->value);

        if (preg_match('/^(?<year>\d{4})[-\/.](?<month>\d{1,2})[-\/.](?<day>\d{1,2})'.self::TIME.'$/iu', $text, $found) === 1) {
            $numbers = [(int) $found['year'], (int) $found['month'], (int) $found['day']];
        } elseif (preg_match('/^(?<a>\d{1,2})[-\/.](?<b>\d{1,2})[-\/.](?<c>\d{4}|\d{2})'.self::TIME.'$/iu', $text, $found) === 1) {
            $numbers = self::inOrder((int) $found['a'], (int) $found['b'], (int) $found['c'], strlen($found['c']) === 2, $context->dateOrder);
        } else {
            return null;
        }

        if ($numbers === null) {
            return null;
        }

        [$year, $month, $day] = $numbers;
        $hour = (int) ($found['hour'] ?? 0);
        $minute = (int) ($found['minute'] ?? 0);
        $second = (int) ($found['second'] ?? 0);

        if ($year < 1000 || ! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return ['year' => $year, 'month' => $month, 'day' => $day, 'hour' => $hour, 'minute' => $minute, 'second' => $second, 'offset' => self::offset($found['offset'] ?? '')];
    }

    /**
     * The year, month and day of three numbers written in an order. Year first needs a year of two digits (a year of four digits comes
     * first only in an ISO date, which is read before); the year of two digits is 1970 to 2069.
     *
     * @return array{int, int, int}|null
     */
    private static function inOrder(int $first, int $second, int $third, bool $shortYear, string $order): ?array
    {
        if ($order === 'ymd') {
            return $shortYear ? [self::fullYear($first), $second, $third] : null;
        }

        $year = $shortYear ? self::fullYear($third) : $third;

        return $order === 'mdy' ? [$year, $first, $second] : [$year, $second, $first];
    }

    /**
     * A year of two digits as four: 70 to 99 are 1970 to 1999, 0 to 69 are 2000 to 2069.
     */
    private static function fullYear(int $year): int
    {
        return $year >= 70 ? 1900 + $year : 2000 + $year;
    }

    /**
     * An offset as `+HH:MM`, or null for none.
     */
    private static function offset(string $offset): ?string
    {
        if ($offset === '') {
            return null;
        }

        if (strtoupper($offset) === 'Z') {
            return '+00:00';
        }

        $digits = str_replace(':', '', substr($offset, 1));

        return $offset[0].substr($digits, 0, 2).':'.(strlen($digits) > 2 ? substr($digits, 2, 2) : '00');
    }

    /**
     * A text with its kinds of spaces made plain and trimmed.
     */
    private static function spaces(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', ' ', $text) ?? $text);
    }
}
