<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;

/**
 * A color written as six hexadecimal digits after a `#` (`#1a2b3c`), stored in lowercase.
 */
final class ColorType extends AbstractFieldType
{
    public function key(): string
    {
        return 'color';
    }

    public function label(): string
    {
        return __('Color');
    }

    /**
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return ['string', 'regex:/^#[0-9a-fA-F]{6}$/D'];
    }

    /**
     * In lowercase, so that the same color is always the same text.
     */
    public function normalize(mixed $value, InventoryField $field): mixed
    {
        return is_string($value) ? strtolower($value) : $value;
    }
}
