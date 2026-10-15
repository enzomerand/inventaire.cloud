<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use Closure;

/**
 * A calendar day, with no time and no timezone: a purchase date, a warranty end. Written YYYY-MM-DD, years 1000 to 9999.
 */
final class DateType extends AbstractFieldType
{
    public function key(): string
    {
        return 'date';
    }

    public function label(): string
    {
        return __('Date');
    }

    /**
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return [function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! self::isDate($value)) {
                $fail(__('The :attribute must be a date written YYYY-MM-DD.'));
            }
        }];
    }

    /**
     * Whether the text is a day that exists, written YYYY-MM-DD, in the years 1000 to 9999.
     */
    public static function isDate(string $text): bool
    {
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $text, $parts) !== 1) {
            return false;
        }

        return (int) $parts[1] >= 1000 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
