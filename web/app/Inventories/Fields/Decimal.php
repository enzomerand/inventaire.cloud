<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

/**
 * A decimal number read from what a client sent, kept as digits so that nothing is ever rounded.
 *
 * Numbers are stored as decimal *strings* ("12.50"): a JSON number would become a floating point value in MySQL and
 * MariaDB, and 0.1 would not stay 0.1. This reads the forms a client may send (a JSON integer, a JSON number, a string
 * with a dot) into sign, integer digits and fraction digits, tells how many decimals the number really has, and
 * writes it back with the decimals a field asks for. It needs no extension (bcmath is not required by the application).
 *
 * What it reads: an optional minus sign, digits, and a fraction after a dot. Nothing else: no plus sign, no exponent,
 * no comma, no spaces. A float is first written with the shortest text that reads back as the same float, and read from
 * that text with its exponent, if it has one, shifted away: nothing is ever rounded.
 */
final readonly class Decimal
{
    /** Most digits before the dot: a number is below 1e15 in absolute value. */
    public const MAX_INTEGER_DIGITS = 15;

    /** Farthest exponent from zero a float written with one is shifted back from (see {@see self::floatText()}). */
    private const MAX_EXPONENT = 40;

    /**
     * @param  bool  $negative  Never true for zero.
     * @param  string  $integer  Digits, without leading zeros ("0" for none).
     * @param  string  $fraction  Digits, without trailing zeros ("" for none).
     */
    private function __construct(
        public bool $negative,
        public string $integer,
        public string $fraction,
    ) {}

    /**
     * Reads a number, or returns null when the value is not one in the forms above.
     */
    public static function tryFrom(mixed $value): ?self
    {
        $text = match (true) {
            is_int($value) => (string) $value,
            is_float($value) => is_finite($value) ? self::floatText($value) : null,
            is_string($value) => $value,
            default => null,
        };

        if ($text === null || preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/D', $text, $parts) !== 1) {
            return null;
        }

        $integer = ltrim($parts[2], '0');
        $fraction = rtrim($parts[3] ?? '', '0');
        $integer = $integer === '' ? '0' : $integer;

        return new self($parts[1] === '-' && ($integer !== '0' || $fraction !== ''), $integer, $fraction);
    }

    /**
     * How many decimals the number really has: "12.50" has one, "12.0" none.
     */
    public function scale(): int
    {
        return strlen($this->fraction);
    }

    /**
     * Whether the number is within what is accepted (below 1e15 in absolute value).
     */
    public function fits(): bool
    {
        return strlen($this->integer) <= self::MAX_INTEGER_DIGITS;
    }

    /**
     * The number as text: with exactly this many decimals (padded with zeros, so the number must not have more), or
     * with only the decimals it needs when none is given.
     */
    public function format(?int $decimals = null): string
    {
        $fraction = $decimals === null ? $this->fraction : str_pad($this->fraction, $decimals, '0');

        return ($this->negative ? '-' : '').$this->integer.($fraction === '' ? '' : '.'.$fraction);
    }

    /**
     * Compares with another number: -1, 0 or 1.
     */
    public function compareTo(self $other): int
    {
        if ($this->negative !== $other->negative) {
            return $this->negative ? -1 : 1;
        }

        $length = max(strlen($this->fraction), strlen($other->fraction));
        $magnitude = [strlen($this->integer), $this->integer, str_pad($this->fraction, $length, '0')]
            <=> [strlen($other->integer), $other->integer, str_pad($other->fraction, $length, '0')];

        return $this->negative ? -$magnitude : $magnitude;
    }

    /**
     * A float as plain digits: the shortest text that reads back as the same float, which is what a JSON number carried
     * (0.1 is "0.1", not 0.1000000000000000055...).
     *
     * That text uses an exponent for the very small and the very large (1.0e-5, 2.5e+20): the digits are then shifted
     * to where the point belongs, exactly, never rounded. Null when that cannot be done honestly: an exponent so far from
     * zero (beyond 40) that no number this application accepts looks like it. A client that needs such a number sends it
     * as a string.
     */
    private static function floatText(float $value): ?string
    {
        $text = json_encode($value);

        if ($text === false) {
            return null;
        }

        if (! str_contains($text, 'e')) {
            return $text;
        }

        if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?e([+-]?[0-9]+)$/D', $text, $parts) !== 1 || abs((int) $parts[4]) > self::MAX_EXPONENT) {
            return null;
        }

        $digits = $parts[2].$parts[3];
        $point = strlen($parts[2]) + (int) $parts[4];

        return $parts[1].match (true) {
            $point <= 0 => '0.'.str_repeat('0', -$point).$digits,
            $point >= strlen($digits) => $digits.str_repeat('0', $point - strlen($digits)),
            default => substr($digits, 0, $point).'.'.substr($digits, $point),
        };
    }
}
