<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use App\Rules\SingleLineText;

/**
 * What most field types share: no options, strings trimmed, and a value that is stored and given back as it came.
 *
 * A type only writes what is different from this. Empty is `null`, `""` (once trimmed) and `[]`; never `false` or `0`.
 */
abstract class AbstractFieldType implements FieldType
{
    /**
     * No options.
     *
     * @return array<string, list<mixed>>
     */
    public function optionRules(): array
    {
        return [];
    }

    /**
     * No options.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public function normalizeOptions(array $options, ?array $previous): array
    {
        return [];
    }

    /**
     * A string loses the spaces, invisible characters and control characters at both ends; anything else is left alone.
     */
    public function prepare(mixed $value, InventoryField $field): mixed
    {
        return is_string($value) ? SingleLineText::trim($value) : $value;
    }

    /**
     * Nothing, an empty text or an empty list.
     */
    public function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * The value as it was prepared.
     */
    public function normalize(mixed $value, InventoryField $field): mixed
    {
        return $value;
    }

    /**
     * The value as it was stored.
     */
    public function present(mixed $stored, InventoryField $field): mixed
    {
        return $stored;
    }
}
