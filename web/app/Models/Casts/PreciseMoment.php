<?php

declare(strict_types=1);

namespace App\Models\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A moment kept with its microseconds, for a `timestamp(6)` column.
 *
 * The framework's date casts write a moment with the format of the connection (`Y-m-d H:i:s`), which drops the fraction of the second.
 * A column that orders two events of the same second (the reads of Stripe in `billing_accounts.synced_at`) needs it. The moment is
 * written in UTC, as every date of the application is stored, and read back as an immutable moment in UTC.
 *
 * @implements CastsAttributes<CarbonImmutable|null, DateTimeInterface|string|null>
 */
final class PreciseMoment implements CastsAttributes
{
    /** The format a moment is written in: the fraction of the second has the six digits `timestamp(6)` keeps. */
    public const FORMAT = 'Y-m-d H:i:s.u';

    /**
     * The moment the column holds, or null for none.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    /**
     * The text to store for a moment, with its microseconds, in UTC.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $moment = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value, 'UTC');

        return $moment->utc()->format(self::FORMAT);
    }
}
