<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Exports\CsvWriter;

/**
 * What an import is read and converted with: whether the first row is a header, how a CSV is cut up and decoded, which sign
 * separates the decimals of the numbers, in which order the days, months and years of the dates are written, and what to do with
 * a row that is not valid. Stored with the import (`imports.options`).
 *
 * The separator and the encoding of a file are detected when it is sent and kept as the names this class knows, so that the
 * person sees what was understood and can correct it; they are null for a spreadsheet, which has neither. The decimal sign and the
 * order of the dates have a default that follows the language of the person (and the separator of a CSV): a file written for a French
 * spreadsheet is full of `12,5` and `15/03/2024`.
 */
final readonly class ImportOptions
{
    /** The sign that separates the decimals of a number written with a comma, and one written with a point. */
    public const DECIMALS = ['comma', 'point'];

    /** The orders of the day, the month and the year in a date written with numbers: day first, month first, year first. */
    public const DATE_ORDERS = ['dmy', 'mdy', 'ymd'];

    /** What to do with a row that is not valid: cancel the whole import, or leave the row out and report it. */
    public const ON_ERRORS = ['abort', 'skip'];

    /** The encodings of a CSV the import reads. */
    public const ENCODINGS = ['utf-8', 'windows-1252', 'utf-16'];

    /**
     * @param  string|null  $delimiter  A key of {@see CsvWriter::DELIMITERS} (`comma`, `semicolon`, `tab`); null for a spreadsheet.
     * @param  string|null  $encoding  One of {@see self::ENCODINGS}; null for a spreadsheet.
     */
    public function __construct(
        public bool $hasHeader = true,
        public ?string $delimiter = null,
        public ?string $encoding = null,
        public string $decimal = 'point',
        public string $dateOrder = 'dmy',
        public string $onError = 'abort',
    ) {}

    /**
     * The decimal sign a file is most likely written with: a comma when the CSV is separated by semicolons (what a French
     * spreadsheet saves) or the person reads the application in French, a point otherwise.
     */
    public static function defaultDecimal(?string $delimiter, string $locale): string
    {
        return $delimiter === 'semicolon' || $locale === 'fr' ? 'comma' : 'point';
    }

    /**
     * The order of the dates a person most likely writes: month first in English, day first elsewhere.
     */
    public static function defaultDateOrder(string $locale): string
    {
        return $locale === 'en' ? 'mdy' : 'dmy';
    }

    /**
     * The options as they were stored: anything missing or unknown is the default, so that a row written by an older version still
     * reads.
     *
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromArray(?array $stored, string $locale = 'en'): self
    {
        $stored ??= [];
        $delimiter = is_string($stored['delimiter'] ?? null) && isset(CsvWriter::DELIMITERS[$stored['delimiter']]) ? $stored['delimiter'] : null;
        $encoding = is_string($stored['encoding'] ?? null) && in_array($stored['encoding'], self::ENCODINGS, true) ? $stored['encoding'] : null;

        return new self(
            hasHeader: ($stored['has_header'] ?? true) !== false,
            delimiter: $delimiter,
            encoding: $encoding,
            decimal: in_array($stored['decimal'] ?? null, self::DECIMALS, true) ? $stored['decimal'] : self::defaultDecimal($delimiter, $locale),
            dateOrder: in_array($stored['date_order'] ?? null, self::DATE_ORDERS, true) ? $stored['date_order'] : self::defaultDateOrder($locale),
            onError: in_array($stored['on_error'] ?? null, self::ON_ERRORS, true) ? $stored['on_error'] : 'abort',
        );
    }

    /**
     * The options as they are stored and given by the API.
     *
     * @return array{has_header: bool, delimiter: string|null, encoding: string|null, decimal: string, date_order: string, on_error: string}
     */
    public function toArray(): array
    {
        return [
            'has_header' => $this->hasHeader,
            'delimiter' => $this->delimiter,
            'encoding' => $this->encoding,
            'decimal' => $this->decimal,
            'date_order' => $this->dateOrder,
            'on_error' => $this->onError,
        ];
    }

    /**
     * The same options with some changed.
     *
     * @param  array<string, mixed>  $changes  Keyed as {@see self::toArray()}.
     */
    public function with(array $changes): self
    {
        $current = $this->toArray();

        return self::fromArray([...$current, ...$changes]);
    }
}
