<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Fields\FieldType;
use App\Models\InventoryField;

/**
 * One column of the file and the field it fills: a field the inventory has, or the field the import creates for it (not saved: the
 * values are checked against it, and it is saved with the items).
 */
final readonly class ImportTarget
{
    /**
     * @param  int  $column  The position of the column in the file, from 0.
     * @param  string  $label  The header of the column, for the report.
     * @param  InventoryField  $field  The field, whose ULID keys the values; a ULID made up for a field that is created.
     * @param  FieldType  $type  The type of the field.
     * @param  bool  $new  Whether the field is created by the import.
     */
    public function __construct(
        public int $column,
        public string $label,
        public InventoryField $field,
        public FieldType $type,
        public bool $new,
    ) {}
}
