<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Exceptions\InvalidImportValue;
use App\Inventories\Fields\Currencies;
use App\Inventories\Fields\Decimal;

/**
 * Reads a number out of a cell, the way a person writes one: `1234.5`, `1 234,56`, `1,234.56`, `12 €`, `EUR 12`, `12 %`, `(12.50)`.
 *
 * A number cell of a spreadsheet is taken as it is. A text is stripped of its spaces (the no-break and the narrow no-break ones a
 * French spreadsheet puts between the thousands included), of a percent sign and of a currency (a symbol, or an ISO code before or
 * after the number); a minus sign, a plus sign and the parentheses of an accountant's negative are understood. What separates the
 * decimals depends on the settings of the import ({@see ImportContext}), but never so far as to mistake what is plain: when both a
 * point and a comma are there, the last one is the decimals and the other the thousands; a sign that is not the decimal sign of the
 * settings is read as the thousands only when it looks like it (a group of exactly three digits after one to three digits that do
 * not start with a zero, or several such groups), so that `12.5` in a file read with commas for decimals is 12.5, not 125.
 *
 * What is read is exact (a string of digits, never a float); rounding it to the decimals of a field is {@see Decimal::rounded()}.
 */
final class ImportNumbers
{
    /** The currency symbols, by the ISO code they stand for. */
    private const SYMBOLS = ['€' => 'EUR', '$' => 'USD', '£' => 'GBP', '¥' => 'JPY'];

    /**
     * The number a cell holds.
     *
     * @throws InvalidImportValue When the cell is not a number, or has more integer digits than a number may.
     */
    public static function decimal(ImportCell $cell, ImportContext $context): Decimal
    {
        return self::read($cell, $context)['number'];
    }

    /**
     * What a cell says about a number: the number, and the currency and the percent sign it was written with.
     *
     * @return array{number: Decimal, currency: string|null, percent: bool}
     *
     * @throws InvalidImportValue When the cell is not a number, or has more integer digits than a number may.
     */
    public static function read(ImportCell $cell, ImportContext $context): array
    {
        $value = $cell->value;

        if (is_int($value) || is_float($value)) {
            return self::checked(Decimal::tryFrom($value), $cell, null, false);
        }

        if (! is_string($value)) {
            throw self::notANumber($cell);
        }

        $text = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}\x{2007}]+/u', '', $value) ?? $value;
        $text = str_replace(['−', '–'], '-', $text);
        $negative = false;

        if (preg_match('/^\((.*)\)$/u', $text, $inside) === 1) {
            $negative = true;
            $text = $inside[1];
        }

        $percent = str_contains($text, '%');
        $text = str_replace('%', '', $text);
        $currency = null;

        foreach (self::SYMBOLS as $symbol => $code) {
            if (str_contains($text, $symbol)) {
                $currency ??= $code;
                $text = str_replace($symbol, '', $text);
            }
        }

        if (preg_match('/^([+-]?)([A-Za-z]{3})?([+-]?)([0-9.,]+)([A-Za-z]{3})?$/D', $text, $parts) !== 1) {
            throw self::notANumber($cell);
        }

        $code = strtoupper($parts[2] !== '' ? $parts[2] : ($parts[5] ?? ''));

        if ($code !== '') {
            if (! Currencies::exists($code)) {
                throw self::notANumber($cell);
            }

            $currency ??= $code;
        }

        $sign = $parts[1] !== '' ? $parts[1] : $parts[3];
        $digits = self::withDecimalPoint($parts[4], $context, $cell);
        $number = Decimal::tryFrom(($negative !== ($sign === '-') ? '-' : '').$digits);

        return self::checked($number, $cell, $currency, $percent);
    }

    /**
     * The digits of a number with a point for the decimals and nothing else.
     *
     * @throws InvalidImportValue
     */
    private static function withDecimalPoint(string $digits, ImportContext $context, ImportCell $cell): string
    {
        $points = substr_count($digits, '.');
        $commas = substr_count($digits, ',');

        if ($points + $commas === 0) {
            return $digits;
        }

        if ($points > 0 && $commas > 0) {
            // Both are there: the last one is the decimals, the other must be the thousands.
            $decimalSign = strrpos($digits, '.') > strrpos($digits, ',') ? '.' : ',';
            $groupSign = $decimalSign === '.' ? ',' : '.';
            [$integer, $fraction] = explode($decimalSign, $digits, 2) + [1 => ''];

            if (str_contains($fraction, $groupSign) || str_contains($fraction, $decimalSign) || preg_match('/^[0-9]{1,3}(?:'.preg_quote($groupSign, '/').'[0-9]{3})+$/D', $integer) !== 1) {
                throw self::notANumber($cell);
            }

            return str_replace($groupSign, '', $integer).'.'.$fraction;
        }

        $sign = $points > 0 ? '.' : ',';

        if ($points + $commas > 1) {
            // Several of the same: thousands, or nothing.
            if (preg_match('/^[0-9]{1,3}(?:'.preg_quote($sign, '/').'[0-9]{3})+$/D', $digits) !== 1) {
                throw self::notANumber($cell);
            }

            return str_replace($sign, '', $digits);
        }

        [$before, $after] = explode($sign, $digits, 2);

        if ($after === '') {
            return $before;
        }

        if ($before === '') {
            return '0.'.$after;
        }

        if ($sign === $context->decimalSign()) {
            return $before.'.'.$after;
        }

        // Not the sign of the settings: the thousands if it looks like it, else the decimals.
        return strlen($after) === 3 && preg_match('/^[1-9][0-9]{0,2}$/D', $before) === 1 ? $before.$after : $before.'.'.$after;
    }

    /**
     * The number as read, if it is one and fits.
     *
     * @return array{number: Decimal, currency: string|null, percent: bool}
     *
     * @throws InvalidImportValue
     */
    private static function checked(?Decimal $number, ImportCell $cell, ?string $currency, bool $percent): array
    {
        if ($number === null) {
            throw self::notANumber($cell);
        }

        if (! $number->fits()) {
            throw new InvalidImportValue(__('The number ":value" has too many digits.', ['value' => $cell->excerpt()]));
        }

        return ['number' => $number, 'currency' => $currency, 'percent' => $percent];
    }

    /**
     * The failure for a cell that is not a number.
     */
    private static function notANumber(ImportCell $cell): InvalidImportValue
    {
        return new InvalidImportValue(__('":value" is not a number.', ['value' => $cell->excerpt()]));
    }
}
