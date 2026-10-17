<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Exceptions\ImportFileException;
use Illuminate\Support\Number;

/**
 * Why an import failed: the code stored with it (`imports.error`) and what a person is told.
 */
enum ImportError: string
{
    /** A row is not valid and the person chose to cancel everything in that case: nothing was written. */
    case InvalidRows = 'invalid_rows';

    /** The file holds more rows than an import may take. */
    case TooManyRows = 'too_many_rows';

    /** A column that becomes a list of choices holds more different values than a field may offer. */
    case TooManyChoices = 'too_many_choices';

    /** A column that becomes a list of choices holds no value at all, so the field would have no choice. */
    case NoChoices = 'no_choices';

    /** A field the mapping uses was deleted, changed its type or took a name meanwhile. */
    case FieldsChanged = 'fields_changed';

    /** The items would take the inventory past the number of items it may hold. */
    case ItemLimitReached = 'item_limit_reached';

    /** The new fields would take the inventory past the number of fields it may have. */
    case FieldLimitReached = 'field_limit_reached';

    /** The person who started the import may no longer change the items of the inventory. */
    case NotAllowed = 'not_allowed';

    /** The file has more lines than a spreadsheet holds, the empty ones counted: the analysis of the first rows did not reach them. */
    case TooManyLines = 'too_many_lines';

    /** A row of the file, past the first ones that were looked at, uses more columns than an import takes. */
    case TooManyColumns = 'too_many_columns';

    /** The file stored for the import cannot be read any more. */
    case FileUnreadable = 'file_unreadable';

    /** The import could not be done. The cause is in the log of the server, not in front of the person. */
    case ImportFailed = 'import_failed';

    /** The import never ended: its worker stopped, or the queue was not running. */
    case Stale = 'stale';

    /**
     * Why a file that was read for the analysis cannot be read for the import. The analysis looks at the first rows only, so a file can
     * turn out to have too many lines, or a row too wide, further down: the person is told which, not that the file is unreadable.
     */
    public static function forFile(ImportFileException $failure): self
    {
        return match ($failure->reason) {
            ImportFileException::TOO_MANY_LINES => self::TooManyLines,
            ImportFileException::TOO_MANY_COLUMNS => self::TooManyColumns,
            default => self::FileUnreadable,
        };
    }

    /**
     * What a person is told, in the language of the request.
     */
    public function message(): string
    {
        return match ($this) {
            self::InvalidRows => __('Some rows are not valid, so nothing was imported. Correct them in the file, or choose to skip the rows in error, and import it again.'),
            self::TooManyRows => __('The file holds more rows than an import may take.'),
            self::TooManyChoices => __('A column you made a choice field of holds too many different values for a list of choices.'),
            self::NoChoices => __('A column you made a choice field of holds no value, so the field would have no choice.'),
            self::FieldsChanged => __('The fields of the inventory changed while the import was waiting, so nothing was imported. The file was deleted: send it again to start a new import.'),
            self::ItemLimitReached => __('The import would take the inventory past the number of items it may hold, so nothing was imported.'),
            self::FieldLimitReached => __('The import would take the inventory past the number of fields it may have, so nothing was imported.'),
            self::NotAllowed => __('You are no longer allowed to change the items of this inventory, so nothing was imported.'),
            self::TooManyLines => __('The file has more than :count lines, the empty ones included, so nothing was imported. Delete the empty lines at the end of the file, then send it again.', ['count' => Number::format(ImportRows::MAX_LINES, locale: app()->getLocale())]),
            self::TooManyColumns => trans_choice('A row of the file uses more than :count column, so nothing was imported. Remove the columns you do not need, then send it again.|A row of the file uses more than :count columns, so nothing was imported. Remove the columns you do not need, then send it again.', config()->integer('inventories.import_max_columns')),
            self::FileUnreadable => __('The file could not be read any more, so nothing was imported. Send it again.'),
            self::ImportFailed => __('The import could not be done. Try again.'),
            self::Stale => __('The import did not finish and was cancelled. Try again.'),
        };
    }
}
