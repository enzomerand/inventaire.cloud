<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;

/**
 * What the number, amount and percentage fields share: a decimal number stored as a string with a fixed number of
 * decimals ("12.50"), never as a JSON number (MySQL and MariaDB would turn it into a floating point value).
 *
 * The decimals are a limit on what is accepted, not a rounding: a value with more is refused. A value with fewer is
 * written with zeros up to the decimals of the field, so that every value of a field looks alike. The subclass says
 * where the decimals (and the bounds, if it has any) come from.
 */
abstract class DecimalType extends AbstractFieldType
{
    /**
     * How many decimals the values of the field have.
     */
    abstract protected function decimals(InventoryField $field): int;

    /**
     * The smallest value the field accepts, or null.
     */
    protected function min(InventoryField $field): ?Decimal
    {
        return null;
    }

    /**
     * The largest value the field accepts, or null.
     */
    protected function max(InventoryField $field): ?Decimal
    {
        return null;
    }

    /**
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return [new DecimalRule($this->decimals($field), $this->min($field), $this->max($field))];
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
     * The bound of an option, read back from its stored text.
     */
    protected function bound(InventoryField $field, string $option): ?Decimal
    {
        $stored = $field->option($option);

        return $stored === null ? null : Decimal::tryFrom($stored);
    }
}
