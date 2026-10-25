<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Inventories\Fields\ChoiceType;
use App\Inventories\Fields\Decimal;
use App\Inventories\Fields\TagsType;
use App\Inventories\ItemValues;
use App\Inventories\SearchText;
use App\Models\InventoryField;
use App\Rules\SingleLineText;
use App\Support\DisplayTime;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Validation\ValidationException;

/**
 * Turns what Claude read into values the inventory can store.
 *
 * Claude answers with texts: for each field it filled, a list of `values` (strings as written on the object), a `confidence` and the
 * `evidence`. Nothing of it is trusted. For each proposal this:
 *
 * 1. keeps only a field the photos can fill ({@see PhotoFields::eligible()}): an unknown ULID, a file or photo field, is dropped; a field
 *    proposed twice keeps its first proposal that holds;
 * 2. converts the strings to what the API takes for that type of field: a choice by its label (case and accents do not matter) to its
 *    id, `oui` and `yes` to true, `12,50 €` to `12.50`, `15h30` to `15:30`; tags and multiple choices take every string, the other
 *    types the first;
 * 3. judges the result by the rules of the field itself ({@see ItemValues::apply()}, the path every item takes) and keeps the canonical
 *    form it stores: a number with the decimals of the field, a moment in UTC, the id of a choice. Whatever the field refuses (a number
 *    out of its range, a barcode whose check digit is wrong, a date that does not exist, a text too long) is dropped, not corrected.
 *
 * The result is what the person is shown to review, in the order of the fields of the inventory, `value` being the canonical form (so
 * the front puts it in the form like any stored value). Nothing here guesses: a value that cannot be read with certainty is left out.
 */
final class Proposals
{
    /** Longest evidence kept, in characters: it is a line under a proposal, not a paragraph. */
    private const EVIDENCE_LENGTH = 200;

    /** The levels of confidence Claude is asked for. */
    private const CONFIDENCES = ['low', 'medium', 'high'];

    /**
     * Gets the code that tells which fields a photo fills, and the one that judges a value.
     */
    public function __construct(
        private readonly PhotoFields $photoFields,
        private readonly ItemValues $values,
    ) {}

    /**
     * The proposals that hold, converted and in canonical form.
     *
     * @param  iterable<InventoryField>  $fields  The fields of the inventory, in order.
     * @param  list<array{field: string, values: list<string>, confidence: string, evidence: string}>  $raw  What the analyzer gave.
     * @return list<array{field: string, value: mixed, confidence: string, evidence: string}>
     */
    public function normalize(iterable $fields, array $raw): array
    {
        $eligible = [];

        foreach ($this->photoFields->eligible($fields) as $field) {
            $eligible[$field->ulid] = $field;
        }

        $kept = [];

        foreach ($raw as $proposal) {
            $ulid = strtolower($proposal['field']);

            if (! isset($eligible[$ulid]) || isset($kept[$ulid])) {
                continue;
            }

            $values = $this->strings($proposal['values']);
            $value = $values === [] ? null : $this->canonical($eligible[$ulid], $values);

            if ($value === null) {
                continue;
            }

            $kept[$ulid] = [
                'field' => $ulid,
                'value' => $value['value'],
                'confidence' => in_array(strtolower(trim($proposal['confidence'])), self::CONFIDENCES, true) ? strtolower(trim($proposal['confidence'])) : 'low',
                'evidence' => $this->evidence($proposal['evidence']),
            ];
        }

        // The order of the fields of the inventory, which is the order of the form, not the one Claude happened to write them in.
        $ordered = [];

        foreach (array_keys($eligible) as $ulid) {
            if (isset($kept[$ulid])) {
                $ordered[] = $kept[$ulid];
            }
        }

        return $ordered;
    }

    /**
     * The canonical value of a field from the strings read for it, or null when they do not make a value the field accepts. Wrapped in
     * an array so that a value that is false (a "no") is not mistaken for none.
     *
     * @param  non-empty-list<string>  $strings
     * @return array{value: mixed}|null
     */
    private function canonical(InventoryField $field, array $strings): ?array
    {
        $input = $this->input($field, $strings);

        if ($input === null) {
            return null;
        }

        try {
            $stored = $this->values->apply(collect([$field]), [$field->ulid => $input], [], true);
        } catch (ValidationException) {
            return null;
        }

        return array_key_exists($field->ulid, $stored) ? ['value' => $stored[$field->ulid]] : null;
    }

    /**
     * What the API takes for the field, read from the strings: null when they cannot be read as a value of that type.
     *
     * @param  non-empty-list<string>  $strings
     */
    private function input(InventoryField $field, array $strings): mixed
    {
        $first = $strings[0];

        return match ($field->type) {
            'text' => preg_replace('/\s+/u', ' ', $first),
            'textarea' => $first,
            'email' => preg_replace('/^mailto:/i', '', $first),
            'number', 'money', 'percentage' => $this->decimal($first),
            'boolean' => $this->boolean($first),
            'date' => $this->date($first),
            'datetime' => $this->moment($first),
            'time' => $this->time($first),
            'select' => $this->choiceId($field, $first),
            'multiselect' => $this->choiceIds($field, $strings),
            'tags' => $this->tags($strings),
            'url' => $this->url($first),
            'phone' => preg_replace('/^tel:/i', '', $first),
            'rating' => $this->rating($first),
            'color' => $this->color($first),
            'barcode' => preg_replace('/\s+/u', '', $first),
            default => null,
        };
    }

    /**
     * The strings, trimmed, without the ones that are not text or are empty.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && SingleLineText::trim($value) !== '') {
                $strings[] = SingleLineText::trim($value);
            }
        }

        return $strings;
    }

    /**
     * The evidence as one short line: the line breaks and control characters gone, cut to {@see self::EVIDENCE_LENGTH} characters.
     */
    private function evidence(string $text): string
    {
        $line = SingleLineText::trim(preg_replace('/[\s\p{C}]+/u', ' ', $text) ?? '');

        return mb_strlen($line) > self::EVIDENCE_LENGTH ? rtrim(mb_substr($line, 0, self::EVIDENCE_LENGTH - 1)).'…' : $line;
    }

    /**
     * A number as a canonical decimal string, from the ways it is written around the world: `12.50`, `12,50 €`, `1 234,5`, `1.234,56`,
     * `1,234.56`, `2.5 kg`, `-3`, `45 %`. The last separator is the decimal sign when there are two kinds; one that appears several
     * times is a thousands separator; a single one is the decimal sign. Null for what is not one number (`10-12`, `about 5`, `1e3`).
     * Nothing is rounded: the field refuses a number with more decimals than it keeps.
     */
    private function decimal(string $text): ?string
    {
        $compact = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}\']+/u', '', $text) ?? '';

        if (preg_match('/^[^0-9+\-.,]*([+\-]?[0-9][0-9.,]*)[^0-9.,]*$/u', $compact, $parts) !== 1) {
            return null;
        }

        $negative = str_starts_with($parts[1], '-');
        $number = ltrim($parts[1], '+-');
        $dot = strrpos($number, '.');
        $comma = strrpos($number, ',');
        // Where the decimal sign is, if there is one.
        $at = match (true) {
            $dot !== false && $comma !== false => max($dot, $comma),
            $dot !== false => substr_count($number, '.') === 1 ? $dot : null,
            $comma !== false => substr_count($number, ',') === 1 ? $comma : null,
            default => null,
        };

        // What comes before the decimal sign is the whole part: digits, grouped by thousands if the number is long. A number that is only
        // groups (`1.234.567`) has no decimal sign. `12.5.3` is no number.
        $whole = $at === null ? $number : substr($number, 0, $at);
        $fraction = $at === null ? '' : substr($number, $at + 1);

        if (preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:[.,][0-9]{3})+)$/D', $whole) !== 1 || ($at !== null && preg_match('/^[0-9]+$/D', $fraction) !== 1)) {
            return null;
        }

        $number = str_replace(['.', ','], '', $whole).($at === null ? '' : '.'.$fraction);

        return Decimal::tryFrom(($negative ? '-' : '').$number)?->format();
    }

    /**
     * Yes or no, in the words a label or a sheet uses; null for anything else.
     */
    private function boolean(string $text): ?bool
    {
        return match (mb_strtolower(trim($text))) {
            'true', 'yes', 'y', 'oui', 'o', 'vrai', 'on', '1' => true,
            'false', 'no', 'n', 'non', 'faux', 'off', '0' => false,
            default => null,
        };
    }

    /**
     * A day written `YYYY-MM-DD` (`/` and `.` also pass for dashes, and a time after the day is ignored); null for anything else. A month
     * without a day is not a date, and no day is invented for it.
     */
    private function date(string $text): ?string
    {
        if (preg_match('/^([0-9]{4})[-\/.]([0-9]{1,2})[-\/.]([0-9]{1,2})(?:[T ].*)?$/D', $text, $parts) !== 1) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $parts[1], $parts[2], $parts[3]);
    }

    /**
     * A moment written `YYYY-MM-DD HH:MM` (or `T`, seconds optional, an offset or `Z` optional) as the API takes it: with its offset. A
     * moment with none is read in the timezone of the instance, the one its people read their dates in. Null when there is no time.
     */
    private function moment(string $text): ?string
    {
        $pattern = '/^([0-9]{4})[-\/.]([0-9]{1,2})[-\/.]([0-9]{1,2})[T ]+([0-9]{1,2})[:h]([0-9]{2})(?::([0-9]{2}))?\s*(Z|[+-][0-9]{2}:?[0-9]{2})?$/Di';

        if (preg_match($pattern, $text, $parts) !== 1) {
            return null;
        }

        $naive = sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $parts[1], $parts[2], $parts[3], $parts[4], $parts[5], ($parts[6] ?? '') !== '' ? $parts[6] : 0);

        if (($parts[7] ?? '') !== '') {
            return $naive.strtoupper($parts[7]);
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        try {
            return (new DateTimeImmutable($naive, new DateTimeZone(DisplayTime::zone())))->format('Y-m-d\TH:i:sP');
        } catch (Exception) {
            return null;
        }
    }

    /**
     * A time of day as `HH:MM`, from `15:30`, `9:05`, `15h30` or `15:30:12`; null for anything else.
     */
    private function time(string $text): ?string
    {
        if (preg_match('/^([0-9]{1,2})[:h]([0-9]{2})(?::[0-9]{2})?$/Di', trim($text), $parts) !== 1) {
            return null;
        }

        return sprintf('%02d:%02d', $parts[1], $parts[2]);
    }

    /**
     * The id of the choice of a field that a text stands for: its label (case does not matter, nor do accents), or its id. Null when the
     * field has no such choice.
     */
    private function choiceId(InventoryField $field, string $text): ?string
    {
        $choices = ChoiceType::choicesOf($field->options);
        $folded = SingleLineText::fold($text);
        $plain = SearchText::normalize($text);

        foreach ($choices as $choice) {
            if (SingleLineText::fold($choice['label']) === $folded) {
                return $choice['id'];
            }
        }

        foreach ($choices as $choice) {
            if (SearchText::normalize($choice['label']) === $plain) {
                return $choice['id'];
            }
        }

        foreach ($choices as $choice) {
            if ($choice['id'] === strtolower(trim($text))) {
                return $choice['id'];
            }
        }

        return null;
    }

    /**
     * The ids of the choices that the texts stand for, once each, in the order they were read; the texts that match no choice are left
     * out. Null when none matches.
     *
     * @param  list<string>  $strings
     * @return list<string>|null
     */
    private function choiceIds(InventoryField $field, array $strings): ?array
    {
        $ids = [];

        foreach ($strings as $text) {
            $id = $this->choiceId($field, $text);

            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return $ids === [] ? null : array_values($ids);
    }

    /**
     * The tags among the texts: the ones that are short enough, no more than a field may hold.
     *
     * @param  list<string>  $strings
     * @return list<string>|null
     */
    private function tags(array $strings): ?array
    {
        $tags = array_values(array_filter($strings, fn (string $tag): bool => mb_strlen($tag) <= TagsType::TAG_MAX_LENGTH));

        return $tags === [] ? null : array_slice($tags, 0, TagsType::MAX_TAGS);
    }

    /**
     * An address, with `https://` put in front of a bare domain (`www.example.com/page`): no other scheme is guessed.
     */
    private function url(string $text): string
    {
        return preg_match('~^(?:www\.)?[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:[/?#]\S*)?$~i', $text) === 1 ? 'https://'.$text : $text;
    }

    /**
     * A grade as a whole number, from `4` or `4/5` (the scale after the slash is not checked: the field judges the grade); null for
     * anything else (`4.5`, `★★★★`).
     */
    private function rating(string $text): ?int
    {
        return preg_match('~^([0-9]{1,2})(?:\s*/\s*[0-9]{1,2})?$~D', trim($text), $parts) === 1 ? (int) $parts[1] : null;
    }

    /**
     * A color as `#rrggbb`, from `#rrggbb`, `rrggbb` or `#rgb`; null for anything else (the name of a color is no hexadecimal code).
     */
    private function color(string $text): ?string
    {
        if (preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/Di', trim($text), $parts) !== 1) {
            return null;
        }

        $digits = strlen($parts[1]) === 3 ? preg_replace('/(.)/', '$1$1', $parts[1]) : $parts[1];

        return '#'.strtolower((string) $digits);
    }
}
