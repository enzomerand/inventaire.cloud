<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Turns the value of an environment variable into a setting of `config/billing.php`, where a variable that is **blank** is a variable that
 * is not set.
 *
 * `env('BILLING_PRO_ITEMS', 10000)` hands back the default only for a variable that does not exist: a line `BILLING_PRO_ITEMS=` in an
 * environment file (what an `.env.example` copied as it is, or a Docker Compose file with an empty value, leaves) gives an empty text,
 * which a plain `(int)` turns into 0, and a limit of 0 refuses everything. Here a blank value, and a value that is not a number where a
 * number is expected, falls back to the default; an explicit `0` is a number like another (a limit of nothing is a choice).
 *
 * It is given what `env()` returned: the environment is only read in the configuration files (`env()` answers nothing once the
 * configuration is cached), which is where it is called.
 */
final class EnvironmentSetting
{
    /**
     * A whole number.
     *
     * @param  mixed  $raw  What `env()` returned for the variable.
     */
    public static function integer(mixed $raw, int $default): int
    {
        $value = self::trimmed($raw);

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    /**
     * A number that may have decimals.
     *
     * @param  mixed  $raw  What `env()` returned for the variable.
     */
    public static function number(mixed $raw, float|int $default): float
    {
        $value = self::trimmed($raw);

        return $value !== null && is_numeric($value) ? (float) $value : (float) $default;
    }

    /**
     * A yes or a no, written as the other booleans of the application are (`true`, `1`, `yes`, `on` for yes). Any other word is a no.
     *
     * @param  mixed  $raw  What `env()` returned for the variable.
     */
    public static function boolean(mixed $raw, bool $default): bool
    {
        $value = self::trimmed($raw);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * A text.
     *
     * @param  mixed  $raw  What `env()` returned for the variable.
     */
    public static function text(mixed $raw, string $default): string
    {
        return self::trimmed($raw) ?? $default;
    }

    /**
     * What `env()` returned without the blanks around it; null when the variable is not set or blank.
     */
    private static function trimmed(mixed $value): ?string
    {
        if (is_bool($value)) {
            // `env()` turns `true` and `false` into booleans.
            $value = $value ? 'true' : 'false';
        }

        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
