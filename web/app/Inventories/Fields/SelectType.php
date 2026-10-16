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
     * How the list sorts and filters the values (one choice, sorted in the order of the choices of the field).
     */
    public function queryKind(): QueryKind
    {
        return QueryKind::Choice;
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

    /**
     * The label of the choice (the value holds its id), or nothing when the choice was deleted from the field.
     */
    public function searchText(mixed $stored, InventoryField $field): ?string
    {
        return is_string($stored) ? $this->labelsText([$stored], $field) : null;
    }

    /**
     * The label of the choice (the value holds its id); a choice the field no longer has is nothing.
     *
     * @param  array<string, string>  $fileNames
     */
    public function exportValue(mixed $stored, InventoryField $field, array $fileNames = []): ExportValue
    {
        return ExportValue::text(implode(', ', is_string($stored) ? $this->choiceLabels([$stored], $field) : []));
    }
}
