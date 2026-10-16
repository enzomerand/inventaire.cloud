<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;

/**
 * A yes or a no: true or false (a client may also send 1 and 0, as the framework's `boolean` rule does).
 *
 * `false` is a value like any other, it is stored; only an absent value (null or empty text) clears the field. So a
 * required yes/no field asks to be answered, not to be ticked.
 */
final class BooleanType extends AbstractFieldType
{
    /**
     * The word that identifies the type in the API and in the database: `boolean`.
     */
    public function key(): string
    {
        return 'boolean';
    }

    /**
     * What the type is called for a person ("Yes / no"), in the language of the request.
     */
    public function label(): string
    {
        return __('Yes / no');
    }

    /**
     * How the list sorts and filters the values (a yes or no).
     */
    public function queryKind(): QueryKind
    {
        return QueryKind::Boolean;
    }

    /**
     * A boolean, or 1 or 0.
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return ['boolean'];
    }

    /**
     * Always a real boolean, whatever way it was sent.
     */
    public function normalize(mixed $value, InventoryField $field): mixed
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Yes or no. `false` is a value: only a missing one is nothing.
     *
     * @param  array<string, string>  $fileNames
     */
    public function exportValue(mixed $stored, InventoryField $field, array $fileNames = []): ExportValue
    {
        return is_bool($stored) ? ExportValue::boolean($stored) : ExportValue::empty();
    }
}
