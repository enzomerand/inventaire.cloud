<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use Illuminate\Validation\Rule;

/**
 * One choice among a list the field defines: a status, a condition, a category. The value is the id of the choice.
 */
final class SelectType extends ChoiceType
{
    /**
     * The word that identifies the type in the API and in the database: `select`.
     */
    public function key(): string
    {
        return 'select';
    }

    /**
     * What the type is called for a person ("Choice"), in the language of the request.
     */
    public function label(): string
    {
        return __('Choice');
    }

    /**
     * A text that is the id of one of the choices the field offers.
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return ['string', Rule::in($this->ids($field))];
    }

    /**
     * The id, unless the choice was deleted from the field since: then there is nothing to show.
     */
    public function present(mixed $stored, InventoryField $field): mixed
    {
        return is_string($stored) && in_array($stored, $this->ids($field), true) ? $stored : null;
    }
}
