<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Inventories\Imports\ImportCell;
use App\Inventories\Imports\ImportContext;
use App\Inventories\Imports\ImportDates;
use App\Models\InventoryField;
use Closure;

/**
 * A calendar day, with no time and no timezone: a purchase date, a warranty end. Written YYYY-MM-DD, years 1000 to 9999.
 */
final class DateType extends AbstractFieldType
{
    /**
     * The word that identifies the type in the API and in the database: `date`.
     */
    public function key(): string
    {
        return 'date';
    }

    /**
     * What the type is called for a person ("Date"), in the language of the request.
     */
    public function label(): string
    {
        return __('Date');
    }

    /**
     * How the list sorts and filters the values (fixed-width text, sorted in the order of time).
     */
    public function queryKind(): QueryKind
    {
        return QueryKind::Temporal;
    }

    /**
     * A calendar day written YYYY-MM-DD.
     *
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

    /**
     * The day, as it was stored (`YYYY-MM-DD`).
     *
     * @param  array<string, string>  $fileNames
     */
    public function exportValue(mixed $stored, InventoryField $field, array $fileNames = []): ExportValue
    {
        return is_string($stored) && self::isDate($stored) ? ExportValue::date($stored) : ExportValue::empty();
    }

    /**
     * The day the cell holds, as `YYYY-MM-DD`: a date cell, an ISO date, or a day written in the order of the settings of the import.
     */
    public function importValue(ImportCell $cell, InventoryField $field, ImportContext $context): mixed
    {
        return $cell->isEmpty() ? null : ImportDates::day($cell, $context);
    }
}
