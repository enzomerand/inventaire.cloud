<?php

declare(strict_types=1);

namespace App\Inventories\Templates;

use App\Inventories\Exports\ExportFormatter;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\RatingType;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Turns a template into what a public page shows: the table of its fields and the table of its example rows, as plain
 * text and badges, in a language.
 *
 * The browser draws the values of an inventory (resources/js/fields); a public page is drawn by the server, for search
 * engines, so the same ideas are written here: a choice is a badge in the color of the choice, a rating is stars, an amount
 * is written the way the language writes it. Nothing here is HTML: the pages escape every text.
 */
final class TemplatePresenter
{
    /** The Tabler badge of each color of a choice, written out so that the stylesheet keeps them (see resources/js/fields/palette.js). */
    private const BADGES = [
        'gray' => 'badge bg-secondary-lt',
        'red' => 'badge bg-red-lt',
        'orange' => 'badge bg-orange-lt',
        'yellow' => 'badge bg-yellow-lt',
        'green' => 'badge bg-green-lt',
        'teal' => 'badge bg-teal-lt',
        'blue' => 'badge bg-blue-lt',
        'indigo' => 'badge bg-indigo-lt',
        'purple' => 'badge bg-purple-lt',
        'pink' => 'badge bg-pink-lt',
    ];

    /**
     * Gets the registry that names the types.
     */
    public function __construct(private readonly FieldTypes $types) {}

    /**
     * The fields of the template, in order, ready for a table: the name, the label of the type, whether it is required, the
     * badges of its choices and a few words about its other options (the currency of an amount, the unit of a number).
     *
     * @return list<array{name: string, type: string, required: bool, badges: list<array{text: string, class: string}>, details: list<string>}>
     */
    public function fields(Template $template, string $locale): array
    {
        return array_map(function (TemplateField $field): array {
            $details = [];

            if (is_string($field->options['currency'] ?? null)) {
                $details[] = $field->options['currency'];
            }

            if (is_string($field->options['unit'] ?? null) && $field->options['unit'] !== '') {
                $details[] = $field->options['unit'];
            }

            return [
                'name' => $field->name,
                'type' => $this->types->find($field->type)?->label() ?? $field->type,
                'required' => $field->required,
                'badges' => array_map(fn (array $choice): array => ['text' => $choice['label'], 'class' => self::badge($choice['color'])], $field->choices),
                'details' => $details,
            ];
        }, $template->fields($locale));
    }

    /**
     * The example rows as a table: a column for each field that at least one row has a value for (a field filled by
     * uploading files has none), and a cell for each value.
     *
     * A cell is `kind` `text` (plain text), `mono` (a code), `badges` (a list of badges), `stars` (a rating: its stars and
     * the sentence that says the grade), `swatch` (a color: its code) or `empty`.
     *
     * @return array{columns: list<string>, rows: list<list<array<string, mixed>>>}
     */
    public function examples(Template $template, string $locale): array
    {
        $fields = array_values(array_filter(
            $template->fields($locale),
            fn (TemplateField $field): bool => array_filter($template->examples($locale), fn (array $row): bool => array_key_exists($field->key, $row)) !== [],
        ));
        $rows = [];

        foreach ($template->examples($locale) as $row) {
            $rows[] = array_map(
                fn (TemplateField $field): array => array_key_exists($field->key, $row) ? $this->cell($field, $row[$field->key], $locale) : ['kind' => 'empty'],
                $fields,
            );
        }

        return ['columns' => array_map(fn (TemplateField $field): string => $field->name, $fields), 'rows' => $rows];
    }

    /**
     * The cell for a value of a field.
     *
     * @return array<string, mixed>
     */
    private function cell(TemplateField $field, mixed $value, string $locale): array
    {
        $text = is_scalar($value) ? (string) $value : '';

        return match ($field->type) {
            'select', 'multiselect' => ['kind' => 'badges', 'badges' => $this->choiceBadges($field, is_array($value) ? $value : [$value])],
            'tags' => ['kind' => 'badges', 'badges' => array_map(fn (mixed $tag): array => ['text' => is_scalar($tag) ? (string) $tag : '', 'class' => self::badge(null)], is_array($value) ? $value : [])],
            'boolean' => ['kind' => 'badges', 'badges' => [$value === true ? ['text' => __('Yes'), 'class' => self::badge('green')] : ['text' => __('No'), 'class' => self::badge('gray')]]],
            'rating' => $this->stars((int) $text, (int) ($field->options['max'] ?? RatingType::DEFAULT_MAX)),
            'color' => ['kind' => 'swatch', 'text' => $text],
            'barcode' => ['kind' => 'mono', 'text' => $text],
            'money' => ['kind' => 'text', 'text' => $this->money($text, is_string($field->options['currency'] ?? null) ? $field->options['currency'] : 'EUR', $locale)],
            'number' => ['kind' => 'text', 'text' => $this->number($text, $locale).(is_string($field->options['unit'] ?? null) && $field->options['unit'] !== '' ? ' '.$field->options['unit'] : '')],
            'percentage' => ['kind' => 'text', 'text' => $this->percentage($text, $locale)],
            'date' => ['kind' => 'text', 'text' => $this->date($text, $locale)],
            'datetime' => ['kind' => 'text', 'text' => $this->dateTime($text, $locale)],
            default => ['kind' => 'text', 'text' => $text],
        };
    }

    /**
     * The badges of the choices a value names (by key).
     *
     * @param  array<array-key, mixed>  $keys
     * @return list<array{text: string, class: string}>
     */
    private function choiceBadges(TemplateField $field, array $keys): array
    {
        $badges = [];

        foreach ($keys as $key) {
            foreach ($field->choices as $choice) {
                if ($choice['key'] === $key) {
                    $badges[] = ['text' => $choice['label'], 'class' => self::badge($choice['color'])];
                }
            }
        }

        return $badges;
    }

    /**
     * A rating: filled and empty stars, and the sentence that says the grade (":grade out of :max"), for those who do not see them.
     *
     * @return array<string, mixed>
     */
    private function stars(int $grade, int $max): array
    {
        $grade = max(0, min($grade, $max));

        return ['kind' => 'stars', 'text' => str_repeat('★', $grade).str_repeat('☆', $max - $grade), 'label' => __(':grade out of :max', ['grade' => $grade, 'max' => $max])];
    }

    /**
     * The Tabler badge classes of a color of the palette of choices (gray for none, or for a name that is not in it).
     */
    public static function badge(?string $color): string
    {
        return self::BADGES[$color ?? 'gray'] ?? self::BADGES['gray'];
    }

    /**
     * An amount written the way the language writes it ("649,00 €" or "€649.00").
     */
    private function money(string $amount, string $currency, string $locale): string
    {
        $formatted = (new NumberFormatter($locale, NumberFormatter::CURRENCY))->formatCurrency((float) $amount, $currency);

        return $formatted === false ? $amount : $formatted;
    }

    /**
     * A number with the decimals it was written with, and no thousands separator (a year is not a quantity).
     */
    private function number(string $number, string $locale): string
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::GROUPING_USED, 0);
        $decimals = $this->decimalsOf($number);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        $formatted = $formatter->format((float) $number);

        return $formatted === false ? $number : $formatted;
    }

    /**
     * A percentage written the way the language writes it ("20,0 %" or "20.0%").
     */
    private function percentage(string $number, string $locale): string
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::PERCENT);
        $decimals = $this->decimalsOf($number);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        $formatted = $formatter->format((float) $number / 100);

        return $formatted === false ? $number : $formatted;
    }

    /**
     * How many digits a decimal text has after its point.
     */
    private function decimalsOf(string $number): int
    {
        $point = strpos($number, '.');

        return $point === false ? 0 : strlen($number) - $point - 1;
    }

    /**
     * A day (written YYYY-MM-DD) in the medium form of the language ("14 sept. 2023"), as it is: a day has no timezone.
     * The validator refuses anything else, so the value is always one.
     */
    private function date(string $date, string $locale): string
    {
        $formatted = IntlDateFormatter::create($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC')
            ?->format(new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC')));

        return is_string($formatted) ? $formatted : $date;
    }

    /**
     * A moment (written with its offset from UTC) in the medium form of the language, in the timezone of the instance and followed
     * by that zone ("May 18, 2024, 10:30 AM UTC+2"), as the exports and the shared pages write it. The validator refuses anything
     * else, so the value is always one.
     */
    private function dateTime(string $moment, string $locale): string
    {
        return (new ExportFormatter($locale, DisplayTime::zone()))->moment(CarbonImmutable::parse($moment));
    }
}
