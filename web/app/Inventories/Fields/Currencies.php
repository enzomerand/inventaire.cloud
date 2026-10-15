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
 *
 * A currency can be chosen when it is legal tender somewhere *today*: ICU's `CurrencyMap` (the supplemental data of
 * CLDR) lists, for each region, its currencies with the dates they were in use, and the ones with no end date are the
 * ones in use. That leaves the euro, the dollar, the yen... and none of the pesetas, francs, drachmas and old
 * Afghanis ICU still has names for, nor the codes that are no money (the "no currency" code, the test code, the units
 * of precious metals, the funds codes). The decimals, on the other hand, are read for any code ICU knows, so that an
 * amount in a currency that went out of use after a field was created is still written the way it always was.
 */
final class Currencies
{
    /** @var array<string, true>|null Every code in use somewhere, read once per process. */
    private static ?array $current = null;

    /** @var array<string, int> */
    private static array $digits = [];

    /**
     * Whether the code is the ISO 4217 code of a currency in use somewhere today, in capital letters ("EUR").
     */
    public static function exists(string $code): bool
    {
        return isset(self::current()[$code]);
    }

    /**
     * How many decimals the amounts of the currency have: 2 for the euro, 0 for the yen, 3 for the Kuwaiti dinar.
     * Known for any code ICU has, in use or not.
     */
    public static function fractionDigits(string $code): int
    {
        return self::$digits[$code] ??= self::readFractionDigits($code);
    }

    /**
     * Every currency an amount field can be given, with its name in the given language ("euro" in French, "Euro" in
     * English), in the order of the names in that language: what a currency picker lists.
     *
     * A currency ICU has no name for in that language is listed under its code. The names come from the same ICU data
     * as the codes, so no list of currencies is copied into the application.
     *
     * @param  list<string>  $also  Codes to list even if they are no longer in use: the currencies the fields being
     *                              edited already have, so that the picker can show them. Unknown codes are skipped.
     * @return list<array{code: string, name: string}>
     */
    public static function named(string $locale, array $also = []): array
    {
        $names = ResourceBundle::create($locale, 'ICUDATA-curr')?->get('Currencies');
        $names = $names instanceof ResourceBundle ? $names : null;
        $codes = array_keys(self::current());

        foreach ($also as $code) {
            if (! in_array($code, $codes, true) && self::fractionDigitsKnown($code)) {
                $codes[] = $code;
            }
        }

        $list = [];

        foreach ($codes as $code) {
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
     * The codes in use somewhere today: those of the currency map that have no end date and are legal tender (the
     * entries marked `tender: false` are funds codes and units of account, such as the Chilean unit of account or the
     * "next day" dollar).
     *
     * @return array<string, true>
     *
     * @throws RuntimeException When the ICU data of the server has no currency map.
     */
    private static function current(): array
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $map = ResourceBundle::create('supplementalData', 'ICUDATA-curr', false)?->get('CurrencyMap');

        if (! $map instanceof ResourceBundle) {
            throw new RuntimeException('The ICU data of the intl extension holds no map of the currencies in use.');
        }

        $current = [];

        foreach ($map as $entries) {
            if (! $entries instanceof ResourceBundle) {
                continue;
            }

            foreach ($entries as $entry) {
                $attributes = [];

                foreach ($entry instanceof ResourceBundle ? $entry : [] as $name => $value) {
                    $attributes[(string) $name] = $value instanceof ResourceBundle ? true : $value;
                }

                $code = $attributes['id'] ?? null;

                if (is_string($code) && ! array_key_exists('to', $attributes) && ($attributes['tender'] ?? 'true') !== 'false') {
                    $current[$code] = true;
                }
            }
        }

        ksort($current);

        return self::$current = $current;
    }

    /**
     * Whether ICU has this code at all (in use or not): it has its decimals.
     */
    private static function fractionDigitsKnown(string $code): bool
    {
        $currencies = ResourceBundle::create('en', 'ICUDATA-curr')?->get('Currencies');

        return $currencies instanceof ResourceBundle && $currencies->get($code) instanceof ResourceBundle;
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
