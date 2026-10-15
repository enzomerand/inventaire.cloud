<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use Closure;

/**
 * A time of day, with no date and no timezone: opening hours, a slot. Written HH:MM on 24 hours.
 */
final class TimeType extends AbstractFieldType
{
    public function key(): string
    {
        return 'time';
    }

    public function label(): string
    {
        return __('Time');
    }

    /**
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return [function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $value) !== 1) {
                $fail(__('The :attribute must be a time written HH:MM, on 24 hours.'));
            }
        }];
    }
}
