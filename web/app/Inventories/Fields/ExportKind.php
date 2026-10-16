<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

/**
 * What a value is once it leaves the application in a file: the genre decides how each format writes it (a number is a
 * number cell in a spreadsheet and digits in a CSV, a date is a date cell and `YYYY-MM-DD`, a yes or no is a boolean cell and
 * "Yes"/"No").
 *
 * It is told by the field type ({@see FieldType::exportValue()}), so that no format knows the types one by one.
 */
enum ExportKind: string
{
    /** Plain text, whatever it looks like: a barcode keeps its leading zeros, a phone number its plus sign. */
    case Text = 'text';

    /** A decimal number: a quantity, an amount, a percentage, a rating. */
    case Number = 'number';

    /** A calendar day, without a time. */
    case Date = 'date';

    /** A moment, in the timezone of the instance. */
    case DateTime = 'datetime';

    /** A time of the day, without a date. */
    case Time = 'time';

    /** Yes or no. */
    case Boolean = 'boolean';

    /** Nothing: the item holds no value for the field. */
    case Empty = 'empty';
}
