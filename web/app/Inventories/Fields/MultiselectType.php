<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use Closure;

/**
 * Several choices among a list the field defines: the features of a product, the rooms a thing is used in. The value
 * is the list of the ids of the choices, each one once, in the order they were given.
 */
final class MultiselectType extends ChoiceType
{
    /**
     * The word that identifies the type in the API and in the database: `multiselect`.
     */
    public function key(): string
    {
        return 'multiselect';
    }

    /**
     * What the type is called for a person ("Multiple choice"), in the language of the request.
     */
    public function label(): string
    {
        return __('Multiple choice');
    }

    /**
     * Trims the ids and keeps each one once.
     */
    public function prepare(mixed $value, InventoryField $field): mixed
    {
        if (! is_array($value)) {
            return parent::prepare($value, $field);
        }

        $ids = [];

        foreach ($value as $id) {
            $ids[] = is_string($id) ? trim($id) : $id;
        }

        return array_values(array_unique(array_filter($ids, fn (mixed $id): bool => $id !== ''), SORT_REGULAR));
    }

    /**
     * A list in which every item is the id of a choice the field offers.
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        $ids = $this->ids($field);

        return ['array', function (string $attribute, mixed $value, Closure $fail) use ($ids): void {
            if (! is_array($value)) {
                return;
            }

            foreach ($value as $id) {
                if (! is_string($id) || ! in_array($id, $ids, true)) {
                    $fail(__('The :attribute contains a choice that does not exist.'));

                    return;
                }
            }
        }];
    }

    /**
     * The ids of the choices that still exist; an empty list when none does.
     *
     * @return list<string>
     */
    public function present(mixed $stored, InventoryField $field): mixed
    {
        $ids = $this->ids($field);

        return is_array($stored)
            ? array_values(array_filter($stored, fn (mixed $id): bool => is_string($id) && in_array($id, $ids, true)))
            : [];
    }
}
