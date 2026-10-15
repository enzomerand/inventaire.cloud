<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * A moment in time: a delivery, a check. Sent as ISO 8601 with its offset from UTC (`2026-10-15T14:30:00+02:00`,
 * `2026-10-15T12:30:00Z`; the seconds may be left out), stored in UTC to the second (`2026-10-15T12:30:00Z`), so that the
 * same moment is the same value whoever typed it, wherever they were.
 *
 * A moment without an offset is refused: it would mean something different to each reader. So is one with a fraction of
 * a second (`12:30:45.123Z`, what `Date.toISOString()` writes): the value is kept to the second, and nothing is
 * dropped silently, so the sender must say the second it means.
 */
final class DateTimeType extends AbstractFieldType
{
    /** The form that is stored, and given back. */
    public const STORED_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * The word that identifies the type in the API and in the database: `datetime`.
     */
    public function key(): string
    {
        return 'datetime';
    }

    /**
     * What the type is called for a person ("Date and time"), in the language of the request.
     */
    public function label(): string
    {
        return __('Date and time');
    }

    /**
     * A moment written in ISO 8601 to the second, with its offset from UTC.
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return [function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || self::toUtc($value) === null) {
                $fail(__('The :attribute must be a date and time to the second with its offset from UTC, such as 2026-10-15T14:30:00+02:00.'));
            }
        }];
    }

    /**
     * The moment in UTC, to the second.
     */
    public function normalize(mixed $value, InventoryField $field): mixed
    {
        return is_string($value) ? (self::toUtc($value) ?? $value) : $value;
    }

    /**
     * Reads a moment written with an offset (and no fraction of a second), and writes it in UTC; null when the text is
     * not one, or when the moment falls outside the years 1000 to 9999 once in UTC.
     */
    public static function toUtc(string $text): ?string
    {
        $pattern = '/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?(Z|[+-][0-9]{2}:?[0-9]{2})$/D';

        if (preg_match($pattern, $text, $parts) !== 1) {
            return null;
        }

        [, $year, $month, $day, $hour, $minute] = $parts;
        $second = $parts[6] !== '' ? $parts[6] : '00';
        $offset = $parts[7] === 'Z' ? '+00:00' : (str_contains($parts[7], ':') ? $parts[7] : substr($parts[7], 0, 3).':'.substr($parts[7], 3));

        if (! checkdate((int) $month, (int) $day, (int) $year) || (int) $hour > 23 || (int) $minute > 59 || (int) $second > 59) {
            return null;
        }

        // An offset the zone database refuses (+99:99) throws instead of returning false.
        try {
            $moment = new DateTimeImmutable("{$year}-{$month}-{$day}T{$hour}:{$minute}:{$second}", new DateTimeZone($offset));
        } catch (Exception) {
            return null;
        }

        $utc = $moment->setTimezone(new DateTimeZone('UTC'));
        $years = (int) $utc->format('Y');

        return $years >= 1000 && $years <= 9999 ? $utc->format(self::STORED_FORMAT) : null;
    }
}
