<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use NumberFormatter;
use ResourceBundle;
use RuntimeException;

/**
 * The currencies an amount field can be in, and how many decimals each one has, from the ICU data of the `intl`
 * extension (so the list and the decimals follow ISO 4217 without being copied into the application).
 */
final class Currencies
{
    /** @var array<string, true>|null Every code ICU knows, read once per process. */
    private static ?array $codes = null;

    /** @var array<string, int> */
    private static array $digits = [];

    /** The codes ISO 4217 reserves for "no currency" and for tests: ICU lists them, an inventory has no use for them. */
    private const RESERVED = ['XXX', 'XTS'];

    /**
     * Whether the code is the ISO 4217 code of a currency, in capital letters ("EUR").
     */
    public static function exists(string $code): bool
    {
        return isset(self::codes()[$code]) && ! in_array($code, self::RESERVED, true);
    }

    /**
     * How many decimals the amounts of the currency have: 2 for the euro, 0 for the yen, 3 for the Kuwaiti dinar.
     */
    public static function fractionDigits(string $code): int
    {
        return self::$digits[$code] ??= self::readFractionDigits($code);
    }

    /**
     * @return array<string, true>
     */
    private static function codes(): array
    {
        if (self::$codes === null) {
            $bundle = ResourceBundle::create('en', 'ICUDATA-curr');
            $currencies = $bundle?->get('Currencies');

            if (! $currencies instanceof ResourceBundle) {
                throw new RuntimeException('The ICU data of the intl extension holds no list of currencies.');
            }

            self::$codes = [];

            foreach ($currencies as $code => $_) {
                self::$codes[(string) $code] = true;
            }
        }

        return self::$codes;
    }

    /**
     * Asks ICU for the decimals of a currency: a currency formatter for a locale that carries the currency knows them.
     */
    private static function readFractionDigits(string $code): int
    {
        $formatter = new NumberFormatter('en@currency='.$code, NumberFormatter::CURRENCY);

        return $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }
}
