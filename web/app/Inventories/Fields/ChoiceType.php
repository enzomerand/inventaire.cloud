<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use App\Rules\SingleLineText;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What the choice and multiple choice fields share: a list of choices, each with an id that does not change, a label and
 * an optional color.
 *
 * The values hold the *ids*, never the labels, so a choice can be renamed without touching a single item. Labels are
 * kept normalized (see {@see SingleLineText::normalize()}) and compared with {@see SingleLineText::fold()}: two choices whose
 * labels differ only by the case, or by how an accented letter is written, are one label. The id of a
 * choice is made by the server (8 random characters) when a choice is sent without one, and kept when the choice
 * already exists. An id that the field never had is not taken from the client: the choice gets a new one, so a choice
 * that was deleted cannot come back with its old id and bring old values back with it. A value whose choice was deleted
 * is dropped when read ({@see self::present()}); and since a deleted choice is a change of the field's options, it is
 * also removed from the items in the same transaction ({@see FieldType::mayInvalidateValues()}).
 */
abstract class ChoiceType extends AbstractFieldType
{
    /** Most choices a field may offer. */
    public const MAX_CHOICES = 200;

    /** Longest label of a choice, in characters. */
    public const LABEL_MAX_LENGTH = 100;

    /** The colors a choice may have: names the interface maps to its palette. */
    public const COLORS = ['gray', 'red', 'orange', 'yellow', 'green', 'teal', 'blue', 'indigo', 'purple', 'pink'];

    /** The characters of the id of a choice. */
    private const ID_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /** Length of the id of a choice. */
    private const ID_LENGTH = 8;

    /**
     * The list of choices, and what each one must be: an id, a label, a color.
     *
     * @param  array<string, mixed>|null  $previous
     * @return array<string, list<mixed>>
     */
    public function optionRules(?array $previous = null): array
    {
        return [
            'choices' => ['nullable', 'array', 'max:'.self::MAX_CHOICES, function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_array($value)) {
                    return;
                }

                if (! array_is_list($value)) {
                    $fail(__('The :attribute must be a list.'));

                    return;
                }

                $labels = [];

                foreach ($value as $choice) {
                    $label = is_array($choice) && is_string($choice['label'] ?? null) ? SingleLineText::fold($choice['label']) : null;

                    if ($label === null) {
                        continue;
                    }

                    if (isset($labels[$label])) {
                        $fail(__('The :attribute must not have two choices with the same label.'));

                        return;
                    }

                    $labels[$label] = true;
                }
            }],
            'choices.*' => ['array'],
            'choices.*.id' => ['nullable', 'string', 'regex:/^[a-z0-9]{'.self::ID_LENGTH.'}$/D'],
            'choices.*.label' => ['required', 'string', 'max:'.self::LABEL_MAX_LENGTH, new SingleLineText],
            'choices.*.color' => ['nullable', Rule::in(self::COLORS)],
        ];
    }

    /**
     * The list of choices with the ids the server decides: kept for a choice the field already had, new for the others.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     *
     * @throws ValidationException When there is no choice at all.
     */
    public function normalizeOptions(array $options, ?array $previous): array
    {
        $sent = $options['choices'] ?? null;

        if (! is_array($sent) || $sent === []) {
            throw ValidationException::withMessages(['options.choices' => [__('At least one choice is required.')]]);
        }

        $known = [];

        foreach (self::choicesOf($previous) as $choice) {
            $known[$choice['id']] = true;
        }

        $taken = $known;
        $choices = [];

        foreach (array_values($sent) as $choice) {
            $choice = is_array($choice) ? $choice : [];
            $id = $choice['id'] ?? null;

            if (! is_string($id) || ! isset($known[$id]) || in_array($id, array_column($choices, 'id'), true)) {
                $id = $this->freshId($taken);
            }

            $taken[$id] = true;
            $entry = ['id' => $id, 'label' => SingleLineText::normalize((string) ($choice['label'] ?? ''))];

            if (is_string($choice['color'] ?? null)) {
                $entry['color'] = $choice['color'];
            }

            $choices[] = $entry;
        }

        return ['choices' => $choices];
    }

    /**
     * Whether a choice that the field had is gone from its options: the values that hold its id no longer mean anything.
     * Renaming, recoloring, adding and reordering choices leave every value as it is.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $options
     */
    public function mayInvalidateValues(array $previous, array $options): bool
    {
        return array_diff(array_column(self::choicesOf($previous), 'id'), array_column(self::choicesOf($options), 'id')) !== [];
    }

    /**
     * The ids of the choices the field offers now.
     *
     * @return list<string>
     */
    protected function ids(InventoryField $field): array
    {
        return array_column(self::choicesOf($field->options), 'id');
    }

    /**
     * The choices stored in some options, whatever state they are in: an entry that is not a choice is skipped.
     *
     * @param  array<string, mixed>|null  $options
     * @return list<array{id: string, label: string, color?: string}>
     */
    public static function choicesOf(?array $options): array
    {
        $found = [];

        foreach (is_array($options['choices'] ?? null) ? $options['choices'] : [] as $choice) {
            if (is_array($choice) && is_string($choice['id'] ?? null) && is_string($choice['label'] ?? null)) {
                $found[] = $choice;
            }
        }

        return $found;
    }

    /**
     * A new id that no choice of the field has now or had before this change.
     *
     * @param  array<string, true>  $taken
     */
    private function freshId(array $taken): string
    {
        do {
            $id = '';

            for ($position = 0; $position < self::ID_LENGTH; $position++) {
                $id .= self::ID_ALPHABET[random_int(0, strlen(self::ID_ALPHABET) - 1)];
            }
        } while (isset($taken[$id]));

        return $id;
    }
}
