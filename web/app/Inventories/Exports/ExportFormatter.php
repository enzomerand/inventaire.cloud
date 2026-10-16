<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\Fields\ExportKind;
use App\Inventories\Fields\ExportValue;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Writes the values of an export as a person reads them, in a language: for the formats made to be read (the PDF), as opposed to the
 * formats made to be read back by a program (the spreadsheet, the CSV and the JSON keep the values exact).
 *
 * Numbers get the separators of the language and the decimals of their field (`1 234,50` in French, `1,234.50` in English), an
 * amount its currency (`1 234,50 €`), a percentage its sign, a number its unit, a rating its maximum (`3/5`); dates and moments are
 * written the way the language writes them, a moment in the timezone of the instance; yes and no are in the language. A number a
 * float could not keep whole (more than 15 significant digits) is written digit by digit with the decimal separator of the
 * language and no grouping, rather than rounded.
 */
final class ExportFormatter
{
    /** The number of significant digits a float keeps exactly. */
    private const EXACT_DIGITS = 15;

    private IntlDateFormatter $dayFormat;

    private IntlDateFormatter $momentFormat;

    /** @var array<int, NumberFormatter> The number formatters, by number of decimals. */
    private array $numbers = [];

    /** @var array<int, NumberFormatter> The currency formatters, by number of decimals. */
    private array $amounts = [];

    /**
     * @param  string  $locale  The language the values are written in (`fr`, `en`...).
     * @param  string  $timezone  The timezone moments are shown in.
     */
    public function __construct(private readonly string $locale, string $timezone)
    {
        $this->dayFormat = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');
        $this->momentFormat = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT, new DateTimeZone($timezone));
    }

    /**
     * A value as text.
     */
    public function format(ExportValue $value): string
    {
        return match ($value->kind) {
            ExportKind::Empty => '',
            ExportKind::Text, ExportKind::Time => $value->string(),
            ExportKind::Number => $this->number($value),
            ExportKind::Date => $this->day($value->string()),
            ExportKind::DateTime => $value->moment() === null ? '' : (string) $this->momentFormat->format($value->moment()),
            ExportKind::Boolean => $value->answer() === true ? __('Yes') : __('No'),
        };
    }

    /**
     * A moment as text, in the timezone of the formatter.
     */
    public function moment(DateTimeImmutable $moment): string
    {
        return (string) $this->momentFormat->format($moment);
    }

    /**
     * A number with its currency, its sign or its unit, or a rating out of its maximum.
     */
    private function number(ExportValue $value): string
    {
        $digits = $value->numberText();

        if ($value->maximum !== null) {
            return $digits.'/'.$value->maximum;
        }

        $scale = max($value->decimals, strlen(substr($digits, (int) strpos($digits.'.', '.') + 1)));

        if ($value->significantDigits() > self::EXACT_DIGITS) {
            $text = str_replace('.', $this->decimalSeparator(), $digits);
        } elseif ($value->currency !== null) {
            $text = (string) $this->amount($scale)->formatCurrency((float) $digits, $value->currency);
        } else {
            $text = (string) $this->plain($scale)->format((float) $digits);
        }

        return match (true) {
            $value->unit === '%' => $text.($this->locale === 'fr' ? "\u{00A0}%" : '%'),
            $value->unit !== null => $text.' '.$value->unit,
            default => $text,
        };
    }

    /**
     * A day as the language writes it ("15 oct. 2026"); the text itself if it cannot be read.
     */
    private function day(string $day): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));

        return $date === false ? $day : (string) $this->dayFormat->format($date);
    }

    /**
     * The formatter of plain numbers with a number of decimals.
     */
    private function plain(int $decimals): NumberFormatter
    {
        return $this->numbers[$decimals] ??= $this->decimals(new NumberFormatter($this->locale, NumberFormatter::DECIMAL), $decimals);
    }

    /**
     * The formatter of amounts with a number of decimals (the ones of the field, not always the ones of the currency).
     */
    private function amount(int $decimals): NumberFormatter
    {
        return $this->amounts[$decimals] ??= $this->decimals(new NumberFormatter($this->locale, NumberFormatter::CURRENCY), $decimals);
    }

    /**
     * Fixes the number of decimals of a formatter.
     */
    private function decimals(NumberFormatter $formatter, int $decimals): NumberFormatter
    {
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return $formatter;
    }

    /**
     * The character the language puts between the whole part of a number and its decimals.
     */
    private function decimalSeparator(): string
    {
        return $this->plain(1)->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
    }
}
