<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use DateTimeImmutable;

/**
 * A stored value as it leaves the application in an export: what kind of thing it is ({@see ExportKind}), its raw value, and the
 * little a format needs to write it well (the decimals of a number, the currency of an amount, the unit, the maximum of a rating).
 *
 * The raw value depends on the kind: the text for a {@see ExportKind::Text}, the canonical decimal string for a
 * {@see ExportKind::Number} (never a float: it stays exact until a format decides how to write it), `YYYY-MM-DD` for a
 * {@see ExportKind::Date}, `HH:MM` for a {@see ExportKind::Time}, a moment in the timezone of the instance for a
 * {@see ExportKind::DateTime}, a bool for a {@see ExportKind::Boolean} and null for {@see ExportKind::Empty}. It is built by the
 * types ({@see FieldType::exportValue()}); a value the type does not understand is {@see self::empty()}, as it is for the API.
 */
final readonly class ExportValue
{
    /**
     * @param  int  $decimals  How many decimals a number is written with at least (the ones of its field).
     * @param  string|null  $currency  The ISO 4217 code of an amount.
     * @param  string|null  $unit  What follows a number: its unit, or the percent sign.
     * @param  int|null  $maximum  What a rating is out of.
     */
    private function __construct(
        public ExportKind $kind,
        public string|bool|DateTimeImmutable|null $value,
        public int $decimals = 0,
        public ?string $currency = null,
        public ?string $unit = null,
        public ?int $maximum = null,
    ) {}

    /**
     * Nothing to write.
     */
    public static function empty(): self
    {
        return new self(ExportKind::Empty, null);
    }

    /**
     * A text; an empty one is nothing.
     */
    public static function text(string $text): self
    {
        return $text === '' ? self::empty() : new self(ExportKind::Text, $text);
    }

    /**
     * A decimal number, from its stored form (a string, or the whole number of a rating). Anything else is nothing.
     *
     * @param  int  $decimals  The decimals of the field.
     */
    public static function number(mixed $stored, int $decimals = 0, ?string $currency = null, ?string $unit = null, ?int $maximum = null): self
    {
        $number = Decimal::tryFrom($stored);

        if ($number === null) {
            return self::empty();
        }

        return new self(ExportKind::Number, $number->format(), $decimals, $currency, $unit, $maximum);
    }

    /**
     * A day written `YYYY-MM-DD`.
     */
    public static function date(string $day): self
    {
        return new self(ExportKind::Date, $day);
    }

    /**
     * A moment, already in the timezone it is shown in.
     */
    public static function dateTime(DateTimeImmutable $moment): self
    {
        return new self(ExportKind::DateTime, $moment);
    }

    /**
     * A time of the day written `HH:MM`.
     */
    public static function time(string $time): self
    {
        return new self(ExportKind::Time, $time);
    }

    /**
     * Yes or no.
     */
    public static function boolean(bool $answer): self
    {
        return new self(ExportKind::Boolean, $answer);
    }

    /**
     * Whether there is nothing to write.
     */
    public function isEmpty(): bool
    {
        return $this->kind === ExportKind::Empty;
    }

    /**
     * The raw value as a string: the text, the digits of a number (the shortest form), the day, the time. Empty for the other
     * kinds.
     */
    public function string(): string
    {
        return is_string($this->value) ? $this->value : '';
    }

    /**
     * The moment of a datetime, in the timezone it is shown in.
     */
    public function moment(): ?DateTimeImmutable
    {
        return $this->value instanceof DateTimeImmutable ? $this->value : null;
    }

    /**
     * The answer of a boolean.
     */
    public function answer(): ?bool
    {
        return is_bool($this->value) ? $this->value : null;
    }

    /**
     * A number written with at least the decimals of its field (`12.5` is `12.50` for two decimals) and more when it holds more:
     * the digits, a point, and a minus sign when negative. Empty for the other kinds.
     */
    public function numberText(): string
    {
        $number = $this->kind === ExportKind::Number ? Decimal::tryFrom($this->value) : null;

        return $number === null ? '' : $number->format(max($this->decimals, $number->scale()));
    }

    /**
     * How many significant digits a number has: its digits without the zeros that only place the point. A spreadsheet keeps 15 of
     * them exactly; a number with more is written as text so that nothing is lost.
     */
    public function significantDigits(): int
    {
        $number = $this->kind === ExportKind::Number ? Decimal::tryFrom($this->value) : null;

        return $number === null ? 0 : strlen(ltrim($number->integer.$number->fraction, '0'));
    }
}
