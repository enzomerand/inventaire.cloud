<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Inventories\Imports\ImportCell;
use App\Inventories\Imports\ImportContext;
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
     * @param  array<string, mixed>|null  $previous
     * @return array<string, list<mixed>>
     */
    public function optionRules(?array $previous = null): array
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
     * Options never take a stored value away, unless the type says so.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $options
     */
    public function mayInvalidateValues(array $previous, array $options): bool
    {
        return false;
    }

    /**
     * Options change nothing in the search, unless the type says so.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $options
     */
    public function mayChangeSearchText(array $previous, array $options): bool
    {
        return false;
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

    /**
     * A text, as it was stored (a reference, an address, a color, a barcode: written as text whatever it looks like, so that a
     * leading zero or a plus sign survives); anything else is nothing.
     *
     * @param  array<string, string>  $fileNames
     */
    public function exportValue(mixed $stored, InventoryField $field, array $fileNames = []): ExportValue
    {
        return is_string($stored) ? ExportValue::text($stored) : ExportValue::empty();
    }

    /**
     * A text on one line (a text, an address, a reference, a code: what is in the cell, as written; the line breaks and the tabs a
     * spreadsheet allows in a cell become spaces); a number is its digits, a date is `YYYY-MM-DD`.
     */
    public function importValue(ImportCell $cell, InventoryField $field, ImportContext $context): mixed
    {
        return $cell->isEmpty() ? null : $cell->line();
    }

    /**
     * No unit, unless the type says so.
     */
    public function exportUnit(InventoryField $field): ?string
    {
        return null;
    }

    /**
     * Not searched.
     */
    public function searchText(mixed $stored, InventoryField $field): ?string
    {
        return null;
    }

    /**
     * Not added up, unless the type says so.
     */
    public function summable(): bool
    {
        return false;
    }

    /**
     * Sent with the item, unless the type says otherwise.
     */
    public function filledByUpload(): bool
    {
        return false;
    }

    /**
     * What a type that is stored as a plain string gives to the search: the string.
     */
    protected function stringSearchText(mixed $stored): ?string
    {
        return is_string($stored) ? $stored : null;
    }
}
