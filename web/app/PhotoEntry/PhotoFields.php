<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Inventories\Fields\ChoiceType;
use App\Inventories\Fields\DecimalType;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\TagsType;
use App\Inventories\Fields\TextareaType;
use App\Inventories\Fields\TextType;
use App\Models\InventoryField;

/**
 * The fields of an inventory as the photo entry sees them: which ones a photo can fill, and what to tell Claude about each.
 *
 * A photo can fill every field whose value is typed: not the file and photo fields, which are filled by uploading files (the original
 * photos are attached through the usual flow once the person has reviewed the proposals). The catalogue gives, for each eligible field,
 * its ULID (the identifier Claude answers with: the answer is a list of `{field, values, ...}` rather than one property per field, so
 * that an inventory with many fields does not run into the limits of structured outputs), its name, its type, and hints that say how the
 * value is written (the labels of the choices, the unit, the decimals, the format of a date...). The server converts and checks every
 * value it gets back ({@see Proposals}): the hints are help, not trust.
 *
 * **The catalogue has a budget** ({@see self::CATALOGUE_BUDGET}, in characters of the JSON that is sent): an inventory may have a hundred
 * fields with two hundred choices of a hundred characters each, which would be hundreds of thousands of tokens billed for every
 * analysis. The fields are listed in order while the budget lasts (a field that does not fit even with its choices summarised is left
 * out of the catalogue, so Claude is never offered it); then the choices are written out, field after field, while the budget still
 * lasts. The choices of a field that do not fit are summarised ("one of 180 predefined values: write the label as read"). That is only
 * a shortcut for the prompt: {@see Proposals} matches the label Claude wrote against the complete list of choices, and drops what is
 * no choice.
 */
final class PhotoFields
{
    /** The room the catalogue may take in the request, in characters of JSON (about 7 000 tokens). */
    public const CATALOGUE_BUDGET = 24000;

    /**
     * Gets the registry of field types.
     */
    public function __construct(private readonly FieldTypes $types) {}

    /**
     * The fields a photo can fill, in the order they are given.
     *
     * @param  iterable<InventoryField>  $fields  The fields of the inventory.
     * @return list<InventoryField>
     */
    public function eligible(iterable $fields): array
    {
        $eligible = [];

        foreach ($fields as $field) {
            $type = $this->types->of($field);

            if ($type !== null && ! $type->filledByUpload()) {
                $eligible[] = $field;
            }
        }

        return $eligible;
    }

    /**
     * What Claude is told about each field: `{id, name, type, hints}`, within {@see self::CATALOGUE_BUDGET}.
     *
     * @param  iterable<InventoryField>  $fields  The eligible fields ({@see self::eligible()}).
     * @return list<array{id: string, name: string, type: string, hints: array<string, mixed>}>
     */
    public function catalogue(iterable $fields): array
    {
        // First every field with its choices summarised, in order, for as long as the budget lasts: those are the fields Claude is offered.
        $listed = [];
        $spent = 0;

        foreach ($fields as $field) {
            $entry = $this->entry($field, false);
            $cost = self::size($entry);

            if ($spent + $cost > self::CATALOGUE_BUDGET) {
                break;
            }

            $listed[] = ['field' => $field, 'entry' => $entry, 'cost' => $cost];
            $spent += $cost;
        }

        // Then the choices written out, field after field, as long as they fit; a field whose choices are too many for what is left
        // keeps its summary, and a smaller one further down may still be written out.
        foreach ($listed as $index => $line) {
            if (! $this->hasChoices($line['field'])) {
                continue;
            }

            $full = $this->entry($line['field'], true);
            $extra = self::size($full) - $line['cost'];

            if ($spent + $extra <= self::CATALOGUE_BUDGET) {
                $listed[$index]['entry'] = $full;
                $spent += $extra;
            }
        }

        return array_column($listed, 'entry');
    }

    /**
     * A field of the catalogue as the line of JSON it is sent as (compact, one field a line): what {@see self::size()} counts.
     *
     * @param  array{id: string, name: string, type: string, hints: array<string, mixed>}  $entry
     */
    public static function line(array $entry): string
    {
        return json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The room a field of the catalogue takes in the request, in characters: its line, and the line break after it.
     *
     * @param  array{id: string, name: string, type: string, hints: array<string, mixed>}  $entry
     */
    public static function size(array $entry): int
    {
        return mb_strlen(self::line($entry)) + 1;
    }

    /**
     * A field of the catalogue, with its choices written out or summarised.
     *
     * @return array{id: string, name: string, type: string, hints: array<string, mixed>}
     */
    private function entry(InventoryField $field, bool $listChoices): array
    {
        return ['id' => $field->ulid, 'name' => $field->name, 'type' => $field->type, 'hints' => $this->hints($field, $listChoices)];
    }

    /**
     * Whether the value of a field is one of choices, or several.
     */
    private function hasChoices(InventoryField $field): bool
    {
        return in_array($field->type, ['select', 'multiselect'], true);
    }

    /**
     * How the value of a field is written, in the words of the prompt: only what the type needs and the field sets.
     *
     * @param  bool  $listChoices  Whether the labels of the choices of a `select` or `multiselect` are listed; if not, they are told by how many there are.
     * @return array<string, mixed>
     */
    public function hints(InventoryField $field, bool $listChoices = true): array
    {
        $type = $this->types->of($field);

        return match ($field->type) {
            'text' => ['max_length' => (int) $field->option('max_length', TextType::DEFAULT_MAX_LENGTH)],
            'textarea' => ['max_length' => TextareaType::MAX_LENGTH],
            'number' => array_filter([
                'decimals' => $type instanceof DecimalType ? $type->decimals($field) : null,
                'unit' => $this->text($field->option('unit')),
                'min' => $this->text($field->option('min')),
                'max' => $this->text($field->option('max')),
            ], fn (mixed $hint): bool => $hint !== null),
            'money' => ['currency' => $type instanceof DecimalType ? $type->exportUnit($field) : null, 'decimals' => $type instanceof DecimalType ? $type->decimals($field) : 2],
            'percentage' => ['decimals' => $type instanceof DecimalType ? $type->decimals($field) : 2, 'unit' => '%'],
            'boolean' => ['answer' => 'true or false'],
            'date' => ['format' => 'YYYY-MM-DD'],
            'datetime' => ['format' => 'YYYY-MM-DD HH:MM, 24 hours, local time'],
            'time' => ['format' => 'HH:MM, 24 hours'],
            'select', 'multiselect' => ['choices' => $this->choices($field, $listChoices)],
            'tags' => ['max_tags' => TagsType::MAX_TAGS, 'max_tag_length' => TagsType::TAG_MAX_LENGTH],
            'url' => ['format' => 'complete address, starting with https://'],
            'rating' => ['from' => 1, 'to' => (int) $field->option('max', 5)],
            'color' => ['format' => '#rrggbb'],
            'barcode' => ['format' => (string) $field->option('format', 'any')],
            default => [],
        };
    }

    /**
     * The choices of a field for the prompt: their labels, or, when they are not listed, a sentence that says how many there are.
     *
     * @return list<string>|string
     */
    private function choices(InventoryField $field, bool $list): array|string
    {
        $labels = array_column(ChoiceType::choicesOf($field->options), 'label');

        if ($list) {
            return $labels;
        }

        return $field->type === 'multiselect'
            ? 'any of '.count($labels).' predefined values: write each label as read'
            : 'one of '.count($labels).' predefined values: write the label as read';
    }

    /**
     * An option as a text, or null when the field does not set it.
     */
    private function text(mixed $option): ?string
    {
        return is_string($option) && $option !== '' ? $option : null;
    }
}
