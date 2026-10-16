<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Inventories\Imports\ImportCell;
use App\Inventories\Imports\ImportContext;
use App\Inventories\Imports\ImportNumbers;
use App\Models\InventoryField;

/**
 * What the number, amount and percentage fields share: a decimal number stored as a string with a fixed number of
 * decimals ("12.50"), never as a JSON number (MySQL and MariaDB would turn it into a floating point value).
 *
 * The decimals are a limit on what is accepted, not a rounding: a value with more is refused. A value with fewer is
 * written with zeros up to the decimals of the field, so that every value of a field looks alike. The subclass says
 * where the decimals come from; the bounds are the `min` and `max` options of the field, if it has them.
 */
abstract class DecimalType extends AbstractFieldType
{
    /**
     * How many decimals the values of the field have.
     */
    abstract public function decimals(InventoryField $field): int;

    /**
     * A number, sorted and compared as one.
     */
    public function queryKind(): QueryKind
    {
        return QueryKind::Number;
    }

    /**
     * A decimal number with at most the decimals of the field, within its bounds if it has any.
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return [new DecimalRule($this->decimals($field), $this->bound($field, 'min'), $this->bound($field, 'max'))];
    }

    /**
     * The number with exactly the decimals of the field.
     */
    public function normalize(mixed $value, InventoryField $field): mixed
    {
        $number = Decimal::tryFrom($value);

        return $number?->format($this->decimals($field)) ?? $value;
    }

    /**
     * A number with the decimals of the field, and the currency and the unit it is written with ({@see self::exportCurrency()},
     * {@see FieldType::exportUnit()}).
     *
     * @param  array<string, string>  $fileNames
     */
    public function exportValue(mixed $stored, InventoryField $field, array $fileNames = []): ExportValue
    {
        $currency = $this->exportCurrency($field);

        return ExportValue::number($stored, $this->decimals($field), $currency, $currency === null ? $this->exportUnit($field) : null);
    }

    /**
     * The currency of the amounts of the field: none, unless the type is an amount.
     */
    protected function exportCurrency(InventoryField $field): ?string
    {
        return null;
    }

    /**
     * The bound of an option, read back from its stored text.
     */
    private function bound(InventoryField $field, string $option): ?Decimal
    {
        $stored = $field->option($option);

        return $stored === null ? null : Decimal::tryFrom($stored);
    }

    /**
     * The number the cell holds, whichever way it is written (`1 234,56`, `12 €`, `12 %`), rounded to the decimals of the field, halves
     * away from zero.
     */
    public function importValue(ImportCell $cell, InventoryField $field, ImportContext $context): mixed
    {
        return $cell->isEmpty() ? null : ImportNumbers::decimal($cell, $context)->rounded($this->decimals($field));
    }
}
