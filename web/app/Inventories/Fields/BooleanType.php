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
    public function key(): string
    {
        return 'boolean';
    }

    public function label(): string
    {
        return __('Yes / no');
    }

    /**
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
}
