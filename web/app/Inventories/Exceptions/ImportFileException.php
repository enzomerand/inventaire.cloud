<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A file that cannot be imported, whatever the rows say: not a format the import reads, damaged, empty, unpacking to more than
 * the import allows, or using more columns than it takes. The API answers 422 on `file` with the `reason` as `code`.
 */
final class ImportFileException extends RuntimeException
{
    /** The extension or the content is not one of a spreadsheet or a text file the import reads. */
    public const UNRECOGNIZED = 'unrecognized_format';

    /** The file is recognised but cannot be read (damaged, protected). */
    public const UNREADABLE = 'unreadable_file';

    /** There is nothing in the file. */
    public const EMPTY = 'empty_file';

    /** The parts of a spreadsheet weigh too much once unpacked: a zip bomb, or a very large spreadsheet. */
    public const UNPACKED_TOO_LARGE = 'unpacked_too_large';

    /** A row uses more columns than the import takes. */
    public const TOO_MANY_COLUMNS = 'too_many_columns';

    /**
     * Remembers why, and says it to a person.
     */
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct(match ($reason) {
            self::UNRECOGNIZED => __('This file is not a spreadsheet or a text file the import can read. Use an XLSX, ODS, CSV, TSV or TXT file.'),
            self::EMPTY => __('This file holds no row.'),
            self::UNPACKED_TOO_LARGE => __('This spreadsheet is too large once unpacked.'),
            self::TOO_MANY_COLUMNS => trans_choice('This file uses more than :count column.|This file uses more than :count columns.', config()->integer('inventories.import_max_columns')),
            default => __('This file could not be read. It may be damaged or protected by a password.'),
        }, 0, $previous);
    }
}
