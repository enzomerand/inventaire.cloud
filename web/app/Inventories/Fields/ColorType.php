<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;

/**
 * A color written as six hexadecimal digits after a `#` (`#1a2b3c`), stored in lowercase.
 */
final class ColorType extends AbstractFieldType
{
    /**
     * The word that identifies the type in the API and in the database: `color`.
     */
    public function key(): string
    {
        return 'color';
    }

    /**
     * What the type is called for a person ("Color"), in the language of the request.
     */
    public function label(): string
    {
        return __('Color');
    }

    /**
     * How the list sorts and filters the values (text: sorted as text, filtered on being empty).
     */
    public function queryKind(): QueryKind
    {
        return QueryKind::Text;
    }

    /**
     * A color written `#rrggbb`.
     *
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
