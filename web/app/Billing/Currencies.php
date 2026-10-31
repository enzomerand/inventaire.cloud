<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * How many decimals the smallest unit of a currency is behind, for the amounts Stripe gives (an integer in that unit): two for most
 * currencies (cents), none for the yen or the won, three for the Kuwaiti dinar. What the mails that write an amount share, so that none
 * divides by a hundred a currency that has no hundredths.
 */
final class Currencies
{
    /** The currencies whose amount is not counted in hundredths but in whole units (Stripe gives it as such). */
    public const WHOLE_UNIT = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    /** The currencies whose amount is counted in thousandths. */
    public const THOUSANDTH = ['bhd', 'jod', 'kwd', 'omr', 'tnd'];

    /**
     * The decimals of a currency: 0, 2 or 3.
     *
     * @param  string  $currency  The ISO code, in any case.
     */
    public static function decimals(string $currency): int
    {
        $code = strtolower($currency);

        return match (true) {
            in_array($code, self::WHOLE_UNIT, true) => 0,
            in_array($code, self::THOUSANDTH, true) => 3,
            default => 2,
        };
    }

    /**
     * An amount in the smallest unit of a currency, as a number of its main unit (`1250` euro cents is `12.5`, `1250` yen is `1250`).
     */
    public static function major(int $amount, string $currency): float
    {
        return $amount / 10 ** self::decimals($currency);
    }
}
