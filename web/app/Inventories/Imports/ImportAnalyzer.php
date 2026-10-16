<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Http\Requests\Inventories\SaveFieldRequest;
use App\Inventories\Exceptions\ImportFileException;
use App\Inventories\Exceptions\InvalidImportValue;
use App\Inventories\Fields\BooleanType;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\SearchText;
use App\Models\InventoryField;
use App\Rules\SingleLineText;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * Reads the beginning of a file to say what its columns are and where each one is proposed to go ({@see ImportAnalysis}).
 *
 * A column has a header (the first row, when the options say it is a header; else `Column A`, `Column B`...), three samples, and the type
 * of field its values look like, judged on the first {@see self::SAMPLE_ROWS} rows: yes/no when every value is, a date or a date and
 * time, a time of the day, a number (an amount when every value has a currency, a percentage when every value has a percent sign),
 * an email address, an address of a web page, a long text when a value has a line break or is long, else a text. A column with nothing
 * in it is a text. A number that a spreadsheet would show with its leading zeros (`007`, a phone number), or that is as long as a
 * barcode, is a text.
 *
 * A column also says whether its values look like **fractions** (`fraction_like`), because a spreadsheet cell shown as a percentage holds
 * a fraction: 12.5 % is stored as 0.125, and a file read as it is would bring 0.125 % into a percentage field. A column is fraction-like
 * when it has values, all of them numbers without a percent sign or a currency, none of them beyond 1 or below -1, and at least one that
 * is not a whole number. Nothing is multiplied: the values are imported as they are, and the person is told so before they start.
 *
 * The proposal is, for each column in this order: the field that has the same name (without the case, the accents and a unit in
 * brackets: `Price (EUR)` is `price`), and not already taken by an earlier column; ignored, when it is one of the columns an export adds
 * (the identifier and the dates of creation and change); else a new field of the type guessed, named as the header, when the person may
 * create fields, and ignored when not.
 */
final class ImportAnalyzer
{
    /** Rows read to judge the type of a column. */
    public const SAMPLE_ROWS = 50;

    /** Samples shown for a column. */
    public const SAMPLES = 3;

    /** The most digits of a whole number that a number field is guessed for: barcodes and phone numbers are longer. */
    private const MAX_GUESSED_DIGITS = 11;

    /**
     * Gets the registry of types, which tells which fields an import can fill.
     */
    public function __construct(private readonly FieldTypes $types) {}

    /**
     * Reads the beginning of the file.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory.
     * @param  bool  $canManageFields  Whether the person may create fields (else no column is proposed a new field).
     * @param  string  $locale  The language of the person, for the headers it makes up and the words of a yes/no.
     * @param  list<string>  $detect  The options to find out from the values (`decimal`, `date_order`): the ones the person chose are left out.
     *
     * @throws ImportFileException When the file holds no row, or cannot be read.
     */
    public function analyze(ImportRows $rows, ImportOptions $options, Collection $fields, bool $canManageFields, string $locale, array $detect = ['decimal', 'date_order']): ImportAnalysis
    {
        $header = null;
        $data = [];
        $width = 0;

        foreach ($rows->rows() as $cells) {
            $width = max($width, count($cells));

            if ($options->hasHeader && $header === null) {
                $header = $cells;

                continue;
            }

            $data[] = $cells;

            if (count($data) >= self::SAMPLE_ROWS) {
                break;
            }
        }

        if ($header === null && $data === []) {
            throw new ImportFileException(ImportFileException::EMPTY);
        }

        $options = $this->detected($options, $data, $detect);
        $context = ImportContext::for($options, $locale);
        $columns = [];

        for ($index = 0; $index < $width; $index++) {
            $values = [];

            foreach ($data as $cells) {
                if (isset($cells[$index]) && ! $cells[$index]->isEmpty()) {
                    $values[] = $cells[$index];
                }
            }

            $columns[] = [
                'index' => $index,
                'header' => $this->header($header[$index] ?? null, $index),
                'samples' => array_map(fn (ImportCell $cell): string => $cell->sample(), array_slice($values, 0, self::SAMPLES)),
                'guess' => $this->guess($values, $context),
                'fraction_like' => $this->fractionLike($values, $context),
            ];
        }

        return new ImportAnalysis($columns, $this->propose($columns, $fields, $canManageFields), $options);
    }

    /**
     * The options with the decimal sign and the order of the dates the values show, where they show one: a number written `1.234,56` or
     * `12,5` has commas for decimals, a date written `15/03/2026` has the day first. What is not conclusive is left as the options had it
     * (the defaults of the language of the person and of the separator).
     *
     * @param  list<list<ImportCell>>  $data  The rows read.
     * @param  list<string>  $detect
     */
    private function detected(ImportOptions $options, array $data, array $detect): ImportOptions
    {
        $changes = [];

        if (in_array('date_order', $detect, true)) {
            $changes['date_order'] = $this->dateOrderOf($data);
        }

        if (in_array('decimal', $detect, true)) {
            $changes['decimal'] = $this->decimalSignOf($data);
        }

        $changes = array_filter($changes, fn (?string $value): bool => $value !== null);

        return $changes === [] ? $options : $options->with($changes);
    }

    /**
     * `dmy` when a date of the values has a day above 12 in first place and none has it in second, `mdy` the other way round, else null.
     *
     * @param  list<list<ImportCell>>  $data
     */
    private function dateOrderOf(array $data): ?string
    {
        $dayFirst = false;
        $monthFirst = false;

        foreach ($data as $cells) {
            foreach ($cells as $cell) {
                if (is_string($cell->value) && preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](?:\d{4}|\d{2})(?!\d)/', $cell->line(), $found) === 1) {
                    $dayFirst = $dayFirst || (int) $found[1] > 12;
                    $monthFirst = $monthFirst || (int) $found[2] > 12;
                }
            }
        }

        return $dayFirst === $monthFirst ? null : ($dayFirst ? 'dmy' : 'mdy');
    }

    /**
     * `comma` or `point` when the numbers written as text in the values say which sign separates their decimals, else null: a number with
     * both signs ends with its decimal sign, and a number with one sign once and not three digits after it has it for its decimals
     * (`1.234` may be thousands, `12.5` and `0,75` may not). The sign most of them name wins.
     *
     * @param  list<list<ImportCell>>  $data
     */
    private function decimalSignOf(array $data): ?string
    {
        $votes = ['comma' => 0, 'point' => 0];

        foreach ($data as $cells) {
            foreach ($cells as $cell) {
                if (! is_string($cell->value)) {
                    continue;
                }

                $text = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}%€$£¥]|[A-Za-z]{3}/u', '', $cell->line()) ?? '';

                if (preg_match('/^[+-]?[0-9][0-9.,]*$/D', $text) !== 1) {
                    continue;
                }

                $points = substr_count($text, '.');
                $commas = substr_count($text, ',');

                if ($points > 0 && $commas > 0) {
                    $votes[strrpos($text, '.') > strrpos($text, ',') ? 'point' : 'comma']++;
                } elseif ($points + $commas === 1 && strlen(substr($text, (int) strrpos($text, $points > 0 ? '.' : ',') + 1)) !== 3) {
                    $votes[$points > 0 ? 'point' : 'comma']++;
                }
            }
        }

        return $votes['comma'] === $votes['point'] ? null : ($votes['comma'] > $votes['point'] ? 'comma' : 'point');
    }

    /**
     * The name of a column in the order of the spreadsheet: A to Z, then AA, AB...
     */
    public static function letter(int $index): string
    {
        $letters = '';

        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $letters = chr(65 + ($number - 1) % 26).$letters;
        }

        return $letters;
    }

    /**
     * The text a header shows: the cell on one line, cut to the longest name of a field, or the letter of the column when it has none.
     */
    private function header(?ImportCell $cell, int $index): string
    {
        $text = $cell === null ? '' : SingleLineText::normalize($cell->line());

        return $text === '' ? __('Column :letter', ['letter' => self::letter($index)]) : mb_substr($text, 0, SaveFieldRequest::NAME_MAX_LENGTH);
    }

    /**
     * The key of the type of field the values of a column look like (see the class).
     *
     * @param  list<ImportCell>  $cells  The cells that hold something.
     */
    private function guess(array $cells, ImportContext $context): string
    {
        if ($cells === []) {
            return 'text';
        }

        if ($this->all($cells, fn (ImportCell $cell): bool => $this->isYesNo($cell))) {
            return 'boolean';
        }

        if ($this->all($cells, fn (ImportCell $cell): bool => $this->isTime($cell))) {
            return 'time';
        }

        if ($this->all($cells, fn (ImportCell $cell): bool => $this->isDay($cell, $context))) {
            return $this->any($cells, fn (ImportCell $cell): bool => $this->hasTime($cell)) ? 'datetime' : 'date';
        }

        if ($this->all($cells, fn (ImportCell $cell): bool => $this->isNumber($cell, $context))) {
            return $this->kindOfNumber($cells, $context);
        }

        if ($this->all($cells, fn (ImportCell $cell): bool => is_string($cell->value) && filter_var($cell->line(), FILTER_VALIDATE_EMAIL) !== false)) {
            return 'email';
        }

        if ($this->all($cells, fn (ImportCell $cell): bool => preg_match('~^https?://\S+$~i', $cell->line()) === 1)) {
            return 'url';
        }

        return $this->any($cells, fn (ImportCell $cell): bool => preg_match('/[\r\n]/', $cell->text()) === 1 || mb_strlen($cell->text()) > 255) ? 'textarea' : 'text';
    }

    /**
     * Whether the values of a column look like fractions that a spreadsheet shows as percentages (see the class).
     *
     * @param  list<ImportCell>  $cells  The cells that hold something.
     */
    private function fractionLike(array $cells, ImportContext $context): bool
    {
        $fractional = false;

        foreach ($cells as $cell) {
            try {
                $read = ImportNumbers::read($cell, $context);
            } catch (InvalidImportValue) {
                return false;
            }

            if ($read['percent'] || $read['currency'] !== null) {
                return false;
            }

            $number = $read['number'];
            $whole = ltrim($number->integer, '0');
            $decimals = trim($number->fraction, '0') !== '';

            // Beyond one, either way: 1 itself is a fraction (all of it), 1.5 is not.
            if ($whole !== '' && ($whole !== '1' || $decimals)) {
                return false;
            }

            $fractional = $fractional || $decimals;
        }

        return $fractional;
    }

    /**
     * `money` when every number has a currency, `percentage` when every one has a percent sign, else `number`.
     *
     * @param  list<ImportCell>  $cells
     */
    private function kindOfNumber(array $cells, ImportContext $context): string
    {
        $currencies = true;
        $percents = true;

        foreach ($cells as $cell) {
            $read = ImportNumbers::read($cell, $context);
            $currencies = $currencies && $read['currency'] !== null;
            $percents = $percents && $read['percent'];
        }

        if ($currencies) {
            return 'money';
        }

        return $percents ? 'percentage' : 'number';
    }

    private function isYesNo(ImportCell $cell): bool
    {
        return is_bool($cell->value) || (is_string($cell->value) && BooleanType::answerOf($cell->value, plainWords: true) !== null);
    }

    /**
     * A time of the day: a duration, a time cell (a day in 1900 or before, the day a spreadsheet gives a time with no date), or a text
     * such as `9:30`.
     */
    private function isTime(ImportCell $cell): bool
    {
        $value = $cell->value;

        if ($value instanceof DateTimeImmutable) {
            return (int) $value->format('Y') <= 1900;
        }

        if ($value instanceof \DateInterval) {
            return true;
        }

        return is_string($value) && preg_match('/^\d{1,2}\s*[:h]\s*\d{2}(?::\d{2})?$/u', $cell->line()) === 1 && $this->reads(fn () => ImportDates::time($cell));
    }

    /**
     * A day, with or without a time: a date cell, or a text a date field reads.
     */
    private function isDay(ImportCell $cell, ImportContext $context): bool
    {
        if ($cell->value instanceof DateTimeImmutable) {
            return true;
        }

        return is_string($cell->value) && $this->reads(fn () => ImportDates::day($cell, $context));
    }

    /**
     * Whether a day carries a time of the day that is not midnight.
     */
    private function hasTime(ImportCell $cell): bool
    {
        if ($cell->value instanceof DateTimeImmutable) {
            return $cell->value->format('H:i:s') !== '00:00:00';
        }

        return preg_match('/\d[T\s,]+\d{1,2}[:h]\d{2}/iu', $cell->line()) === 1;
    }

    /**
     * A number: a number cell, or a text that reads as one without a leading zero and without the length of a code.
     */
    private function isNumber(ImportCell $cell, ImportContext $context): bool
    {
        $value = $cell->value;

        if (is_string($value)) {
            $text = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', '', $cell->line()) ?? '';

            // A leading zero, a plus sign before many digits and a long run of digits are how codes, phone numbers and barcodes are written.
            if (preg_match('/^0[0-9]/', $text) === 1 || preg_match('/^\+[0-9]{7,}$/', $text) === 1 || preg_match('/^[0-9]{'.(self::MAX_GUESSED_DIGITS + 1).',}$/', $text) === 1) {
                return false;
            }
        } elseif (is_int($value) || is_float($value)) {
            if (strlen((string) abs((int) $value)) > self::MAX_GUESSED_DIGITS) {
                return false;
            }
        } else {
            return false;
        }

        return $this->reads(fn () => ImportNumbers::read($cell, $context));
    }

    /**
     * Whether a reading succeeds.
     *
     * @param  callable(): mixed  $read
     */
    private function reads(callable $read): bool
    {
        try {
            $read();

            return true;
        } catch (InvalidImportValue) {
            return false;
        }
    }

    /**
     * @param  list<ImportCell>  $cells
     * @param  callable(ImportCell): bool  $test
     */
    private function all(array $cells, callable $test): bool
    {
        foreach ($cells as $cell) {
            if (! $test($cell)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<ImportCell>  $cells
     * @param  callable(ImportCell): bool  $test
     */
    private function any(array $cells, callable $test): bool
    {
        foreach ($cells as $cell) {
            if ($test($cell)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where each column is proposed to go (see the class).
     *
     * @param  list<array{index: int, header: string, samples: list<string>, guess: string, fraction_like: bool}>  $columns
     * @param  Collection<int, InventoryField>  $fields
     */
    private function propose(array $columns, Collection $fields, bool $canManageFields): ImportMapping
    {
        $byName = [];

        foreach ($fields as $field) {
            $type = $this->types->of($field);

            if ($type !== null && ! $type->filledByUpload()) {
                $byName[self::nameKey($field->name)] ??= $field;
            }
        }

        $ignored = array_map(self::nameKey(...), ['Identifier', 'Identifiant', 'Created on', 'Créé le', 'Modified on', 'Modifié le', __('Identifier'), __('Created on'), __('Modified on')]);
        $taken = [];

        foreach ($fields as $field) {
            $taken[SingleLineText::fold($field->name)] = true;
        }

        $used = [];
        $entries = [];

        foreach ($columns as $column) {
            $key = self::nameKey($column['header']);
            $field = $byName[$key] ?? null;

            if ($field !== null && ! isset($used[$field->ulid])) {
                $used[$field->ulid] = true;
                $entries[] = ['column' => $column['index'], 'target' => ImportMapping::FIELD, 'field' => $field->ulid];

                continue;
            }

            if (in_array($key, $ignored, true) || ! $canManageFields) {
                $entries[] = ['column' => $column['index'], 'target' => ImportMapping::IGNORE];

                continue;
            }

            $name = $this->freeName($column['header'], $taken);
            $taken[SingleLineText::fold($name)] = true;
            $entries[] = ['column' => $column['index'], 'target' => ImportMapping::NEW, 'type' => $column['guess'], 'name' => $name];
        }

        return ImportMapping::from($entries);
    }

    /**
     * A name for a new field that no field has, from a header: the header itself, else the header followed by 2, 3...
     *
     * @param  array<string, true>  $taken  The names (folded) the inventory and the earlier proposals already have.
     */
    private function freeName(string $header, array $taken): string
    {
        $name = $header;

        for ($number = 2; isset($taken[SingleLineText::fold($name)]); $number++) {
            $suffix = ' '.$number;
            $name = mb_substr($header, 0, SaveFieldRequest::NAME_MAX_LENGTH - mb_strlen($suffix)).$suffix;
        }

        return $name;
    }

    /**
     * What names are compared by: the case, the accents and the unit in brackets at the end are left out (`Price (EUR)` is `price`).
     */
    public static function nameKey(string $name): string
    {
        $bare = preg_replace('/\s*\([^()]*\)\s*$/u', '', $name);

        return SearchText::normalize($bare === null || trim($bare) === '' ? $name : $bare);
    }
}
