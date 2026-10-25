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
            'number', 'money', 'percentage' => $this->decimal($field, $first),
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
     * A number as a canonical decimal string, or null when the text is not one number and nothing else. Nothing is guessed: what could be
     * read two ways is refused, and Claude is asked for a plain number anyway (a dot as the decimal sign, no unit).
     *
     * What is accepted, and nothing else:
     *
     * - an optional sign (`+`, `-` or `−`) and the digits of one number, with at most one decoration, before or after it: **the unit of
     *   the field** for a `number` (`2.5 kg` in a field whose unit is `kg`: another unit, or none declared, is refused, for `250 g` is no
     *   `250` of a field in kilograms), `%` for a `percentage`, a **currency symbol** (`€`, `$`...) or **the code of the currency of the
     *   field** (`EUR`; another code is refused) for a `money`. `about 5`, `Model 200 128GB`, `ISO 9001`, `10-12`, `1e3`, `3 and 4` are no
     *   number: refused;
     * - thousands groups of **exactly three digits** after a first group of one to three, separated by one kind of separator: a space
     *   (also a non-breaking, a narrow non-breaking and a thin one), a dot or a comma (`1 234 567`, `1.234.567`, `1,234,567`);
     * - when **both a dot and a comma** appear, the last of them is the decimal sign and the other kind is the thousands separator
     *   (`1.234,56` and `1,234.56` are 1234.56); `1.234,567.89` is not a number;
     * - when only **one** dot or comma appears, it is the decimal sign (`12.5`, `12,50`, `1 234,5`, `0.125`) — except for one followed by
     *   **exactly three digits** after a whole part of one to three digits that does not start with a zero and no other separator
     *   (`1.234`, `2,500`): is it a thousand two hundred and thirty-four, or one and 234 thousandths? That is refused, unless the field
     *   keeps no decimals, where only the thousands reading can hold. After a space-grouped whole part (`1 234.567`) it is a decimal sign.
     *
     * Nothing is rounded: the field refuses a number with more decimals than it keeps.
     */
    private function decimal(InventoryField $field, string $text): ?string
    {
        $hints = $this->photoFields->hints($field);
        $decimals = is_int($hints['decimals'] ?? null) ? $hints['decimals'] : 2;
        $unit = is_string($hints['unit'] ?? null) && $hints['unit'] !== '' ? $hints['unit'] : null;
        $money = $field->type === 'money';
        $decoration = $money ? '\p{Sc}|[A-Za-z]{3}' : ($unit === null ? '(?!)' : preg_quote($unit, '/'));
        $sign = '[+\-\x{2212}]';

        $pattern = "/^(?<s1>{$sign})?\\s*(?:(?<pre>{$decoration})\\s*)?(?<s2>{$sign})?\\s*(?<core>[0-9](?:[0-9 .,\\x{00A0}\\x{202F}\\x{2009}]*[0-9])?)(?:\\s*(?<post>{$decoration}))?$/Diu";

        if (preg_match($pattern, trim($text), $parts) !== 1) {
            return null;
        }

        $decorations = array_values(array_filter([$parts['pre'] ?? '', $parts['post'] ?? ''], fn (string $word): bool => $word !== ''));
        $signs = array_values(array_filter([$parts['s1'] ?? '', $parts['s2'] ?? ''], fn (string $mark): bool => $mark !== ''));

        // One sign, one decoration: `€ 5 EUR` and `--5` are not a number.
        if (count($decorations) > 1 || count($signs) > 1) {
            return null;
        }

        // A code of currency must be the one of the field: `12 USD` in a field in euros is not 12 euros. A symbol is not checked.
        if ($money && $decorations !== [] && preg_match('/^[A-Za-z]{3}$/D', $decorations[0]) === 1) {
            $currency = $hints['currency'] ?? null;

            if (is_string($currency) && $currency !== '' && strtoupper($decorations[0]) !== strtoupper($currency)) {
                return null;
            }
        }

        $number = $this->plainNumber($parts['core'], $decimals);

        if ($number === null) {
            return null;
        }

        $negative = $signs !== [] && $signs[0] !== '+';

        return Decimal::tryFrom(($negative ? '-' : '').$number)?->format();
    }

    /**
     * The digits of a number written with its separators as `whole` or `whole.fraction`, or null when they cannot be read with certainty.
     * The rules are the ones of {@see self::decimal()}.
     *
     * @param  string  $core  Digits, spaces, dots and commas, starting and ending with a digit.
     * @param  int  $decimals  How many decimals the field keeps.
     */
    private function plainNumber(string $core, int $decimals): ?string
    {
        $pieces = preg_split('/([ .,\x{00A0}\x{202F}\x{2009}])/u', $core, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $runs = [];
        $separators = [];

        foreach ($pieces as $index => $piece) {
            if ($index % 2 === 0) {
                $runs[] = $piece;
            } else {
                // Every kind of space is one.
                $separators[] = $piece === '.' || $piece === ',' ? $piece : ' ';
            }
        }

        // Two separators in a row leave a run with no digit.
        if (in_array('', $runs, true)) {
            return null;
        }

        if ($separators === []) {
            return $runs[0];
        }

        $marks = array_keys(array_filter($separators, fn (string $separator): bool => $separator !== ' '));
        $dots = count(array_keys($separators, '.', true));
        $commas = count(array_keys($separators, ',', true));

        // Where the decimal sign is, if there is one: the last dot or comma when there are both kinds, the only one when there is one,
        // none when the same kind is repeated (those are thousands separators) or when there are only spaces.
        $at = $marks !== [] && ($dots + $commas === 1 || ($dots > 0 && $commas > 0)) ? max($marks) : null;

        // The fraction is the last run: nothing may follow the decimal sign but its digits.
        if ($at !== null && $at !== count($separators) - 1) {
            return null;
        }

        $grouping = $at === null ? $separators : array_values(array_diff_key($separators, [$at => true]));
        $whole = $at === null ? $runs : array_slice($runs, 0, $at + 1);
        $fraction = $at === null ? null : $runs[$at + 1];

        if ($grouping !== []) {
            // One kind of separator, a first group of one to three digits (no zero in front) and every other group of exactly three.
            if (count(array_unique($grouping)) !== 1 || preg_match('/^[1-9][0-9]{0,2}$/D', $whole[0]) !== 1) {
                return null;
            }

            foreach (array_slice($whole, 1) as $group) {
                if (strlen($group) !== 3) {
                    return null;
                }
            }
        } elseif ($fraction !== null && strlen($fraction) === 3 && preg_match('/^[1-9][0-9]{0,2}$/D', $whole[0]) === 1) {
            // `1.234`: a thousand and more, or one and a bit? Only a field that keeps no decimals settles it.
            return $decimals === 0 ? $whole[0].$fraction : null;
        }

        return implode('', $whole).($fraction === null ? '' : '.'.$fraction);
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
