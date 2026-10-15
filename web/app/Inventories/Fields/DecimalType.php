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
 * where the decimals come from; the bounds are the `min` and `max` options of the field, if it has them.
 */
abstract class DecimalType extends AbstractFieldType
{
    /**
     * How many decimals the values of the field have.
     */
    abstract protected function decimals(InventoryField $field): int;

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
     * The bound of an option, read back from its stored text.
     */
    private function bound(InventoryField $field, string $option): ?Decimal
    {
        $stored = $field->option($option);

        return $stored === null ? null : Decimal::tryFrom($stored);
    }
}
