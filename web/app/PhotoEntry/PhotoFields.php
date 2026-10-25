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
 */
final class PhotoFields
{
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
     * What Claude is told about each field: `{id, name, type, hints}`.
     *
     * @param  iterable<InventoryField>  $fields  The eligible fields ({@see self::eligible()}).
     * @return list<array{id: string, name: string, type: string, hints: array<string, mixed>}>
     */
    public function catalogue(iterable $fields): array
    {
        $catalogue = [];

        foreach ($fields as $field) {
            $catalogue[] = ['id' => $field->ulid, 'name' => $field->name, 'type' => $field->type, 'hints' => $this->hints($field)];
        }

        return $catalogue;
    }

    /**
     * How the value of a field is written, in the words of the prompt: only what the type needs and the field sets.
     *
     * @return array<string, mixed>
     */
    public function hints(InventoryField $field): array
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
            'select', 'multiselect' => ['choices' => array_column(ChoiceType::choicesOf($field->options), 'label')],
            'tags' => ['max_tags' => TagsType::MAX_TAGS, 'max_tag_length' => TagsType::TAG_MAX_LENGTH],
            'url' => ['format' => 'complete address, starting with https://'],
            'rating' => ['from' => 1, 'to' => (int) $field->option('max', 5)],
            'color' => ['format' => '#rrggbb'],
            'barcode' => ['format' => (string) $field->option('format', 'any')],
            default => [],
        };
    }

    /**
     * An option as a text, or null when the field does not set it.
     */
    private function text(mixed $option): ?string
    {
        return is_string($option) && $option !== '' ? $option : null;
    }
}
