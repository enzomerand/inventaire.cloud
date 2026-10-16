<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * A mapping that cannot be started: it names a column the file does not have, a field that does not exist or cannot be filled by an
 * import, a new field that cannot be made, or it leaves out something the inventory requires. The API answers 422 on `mapping`
 * with the `reason` as `code`.
 */
final class InvalidImportMapping extends RuntimeException
{
    /** A column that the file does not have. */
    public const UNKNOWN_COLUMN = 'unknown_column';

    /** The same column is given twice. */
    public const DUPLICATE_COLUMN = 'duplicate_column';

    /** A field that the inventory does not have. */
    public const UNKNOWN_FIELD = 'unknown_field';

    /** A field that is filled by uploading files, which an import cannot do. */
    public const FIELD_NOT_IMPORTABLE = 'field_not_importable';

    /** Two columns going to the same field. */
    public const DUPLICATE_FIELD = 'duplicate_field';

    /** A type that a new field cannot have (a file, a photo, a multiple choice) or that does not exist. */
    public const INVALID_FIELD_TYPE = 'invalid_field_type';

    /** A name that a field cannot have: empty, too long, with line breaks. */
    public const INVALID_FIELD_NAME = 'invalid_field_name';

    /** Two new fields with the same name. */
    public const DUPLICATE_FIELD_NAME = 'duplicate_field_name';

    /** A new field with the name of a field the inventory has already. */
    public const FIELD_NAME_TAKEN = 'field_name_taken';

    /** New fields asked for by someone who may not change the fields. */
    public const CANNOT_MANAGE_FIELDS = 'cannot_manage_fields';

    /** A field the inventory requires, left without a column. */
    public const REQUIRED_FIELD_UNMAPPED = 'required_field_unmapped';

    /** No column goes anywhere. */
    public const NOTHING_MAPPED = 'nothing_mapped';

    /**
     * Remembers why.
     *
     * @param  string  $message  What is wrong, for a person.
     * @param  string  $reason  Why, for a program: one of the constants above.
     * @param  array<string, mixed>  $extra  More machine-readable facts, such as the column or the field.
     */
    public function __construct(string $message, public readonly string $reason, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
