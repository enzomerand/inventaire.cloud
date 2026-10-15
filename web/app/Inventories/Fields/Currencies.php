<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use Collator;
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
     * Every currency an amount field accepts, with its name in the given language ("euro" in French, "Euro" in English),
     * in the order of the names in that language: what a currency picker lists.
     *
     * A currency ICU has no name for in that language is listed under its code. The names come from the same ICU data
     * as the codes, so no list of currencies is copied into the application.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function named(string $locale): array
    {
        $names = ResourceBundle::create($locale, 'ICUDATA-curr')?->get('Currencies');
        $names = $names instanceof ResourceBundle ? $names : null;
        $list = [];

        foreach (array_keys(self::codes()) as $code) {
            if (! self::exists($code)) {
                continue;
            }

            $entry = $names?->get($code);
            $name = $entry instanceof ResourceBundle ? $entry->get(1) : null;
            $list[] = ['code' => $code, 'name' => is_string($name) && $name !== '' ? $name : $code];
        }

        $collator = new Collator($locale);
        $collator->setStrength(Collator::SECONDARY);
        usort($list, fn (array $first, array $second): int => $collator->compare($first['name'], $second['name']) ?: strcmp($first['code'], $second['code']));

        return $list;
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
