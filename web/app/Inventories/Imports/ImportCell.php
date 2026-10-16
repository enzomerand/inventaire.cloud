<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Fields\Decimal;
use App\Rules\SingleLineText;
use DateInterval;
use DateTimeImmutable;

/**
 * What one cell of the file holds, as the file says it: the raw value, typed. A spreadsheet knows numbers, yes/no, dates and times;
 * a CSV knows only text (`fromCsv`), which the types read according to the settings of the import ({@see ImportContext}).
 *
 * A date or a time comes as the wall clock the person sees in the spreadsheet: the time zone of the {@see DateTimeImmutable} is
 * meaningless, only its fields are read. A value that is nothing, or only spaces, is {@see self::isEmpty()}: the field of the item stays
 * empty.
 */
final readonly class ImportCell
{
    /** Longest text a sample of a column is cut to. */
    public const SAMPLE_LENGTH = 100;

    public function __construct(
        public string|int|float|bool|DateTimeImmutable|DateInterval|null $value = null,
        public bool $fromCsv = false,
    ) {}

    /**
     * Whether the cell holds nothing: no value, or a text of spaces and invisible characters only.
     */
    public function isEmpty(): bool
    {
        return $this->value === null || (is_string($this->value) && SingleLineText::trim($this->value) === '');
    }

    /**
     * The cell as a text, as a person reads it: a text trimmed, a number without a useless `.0`, a date as `YYYY-MM-DD` (with the time
     * when it is not midnight), a time as `HH:MM`, a yes/no in the language of the person.
     */
    public function text(): string
    {
        $value = $this->value;

        return match (true) {
            $value === null => '',
            is_string($value) => SingleLineText::trim($value),
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_int($value) => (string) $value,
            is_float($value) => Decimal::tryFrom($value)?->format() ?? (string) $value,
            $value instanceof DateTimeImmutable => $this->moment($value),
            default => self::clock($value),
        };
    }

    /**
     * The cell as a text on a single line: the line breaks and tabs inside it become one space each (a field that holds a line does
     * not take them), and the text is trimmed.
     */
    public function line(): string
    {
        $text = $this->text();

        return SingleLineText::trim(preg_replace('/\s*[\r\n\t\x{2028}\x{2029}]+\s*/u', ' ', $text) ?? $text);
    }

    /**
     * The parts of a cell that holds a list (tags, choices): the text cut at each comma, semicolon and line break, each part trimmed,
     * the empty ones left out.
     *
     * @return list<string>
     */
    public function parts(): array
    {
        $parts = [];

        foreach (preg_split('/[,;\r\n\x{2028}\x{2029}]+/u', $this->text()) ?: [] as $part) {
            $part = SingleLineText::trim($part);

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * The pieces of a cell that holds a list, as {@see self::parts()} cuts it but untouched: each is the text between two separators as it
     * is written, with the separator that follows it (empty for the last one). That is what lets a label that holds a comma be put
     * back together out of the pieces a comma cut it into.
     *
     * @return list<array{text: string, separator: string}>
     */
    public function pieces(): array
    {
        $tokens = preg_split('/([,;\r\n\x{2028}\x{2029}]+)/u', $this->text(), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $pieces = [];

        for ($position = 0; $position < count($tokens); $position += 2) {
            $pieces[] = ['text' => $tokens[$position], 'separator' => $tokens[$position + 1] ?? ''];
        }

        return $pieces;
    }

    /**
     * The cell cut to a length, for a message that quotes it.
     */
    public function excerpt(int $length = 40): string
    {
        $line = $this->line();

        return mb_strlen($line) > $length ? mb_substr($line, 0, $length - 1).'…' : $line;
    }

    /**
     * The text a sample shows: the cell on one line, cut to {@see self::SAMPLE_LENGTH} characters.
     */
    public function sample(): string
    {
        $line = $this->line();

        return mb_strlen($line) > self::SAMPLE_LENGTH ? mb_substr($line, 0, self::SAMPLE_LENGTH - 1).'…' : $line;
    }

    /**
     * A time of the day (or a duration) as `HH:MM`; the hours go past 23 for a duration longer than a day.
     */
    public static function clock(DateInterval $interval): string
    {
        return sprintf('%02d:%02d', $interval->d * 24 + $interval->h, $interval->i);
    }

    /**
     * A date as `YYYY-MM-DD`, followed by the time when it is not midnight; a time of the day alone, as `HH:MM`.
     */
    private function moment(DateTimeImmutable $moment): string
    {
        // A spreadsheet gives a time of the day without a date the first days of its calendar (1899 or 1900).
        if ((int) $moment->format('Y') <= 1900) {
            return $moment->format('H:i');
        }

        $time = $moment->format('H:i:s');

        return $moment->format('Y-m-d').($time === '00:00:00' ? '' : ' '.($moment->format('s') === '00' ? $moment->format('H:i') : $time));
    }
}
