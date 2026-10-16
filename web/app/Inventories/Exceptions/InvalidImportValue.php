<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * A cell that a field cannot read at all (a word where a number is expected, a date that does not exist): the message says why, in
 * the language of the person, and goes into the report of the import with the row and the column. What can be read but is not
 * acceptable (a value out of the range of the field) is the business of the rules of the field instead.
 */
final class InvalidImportValue extends RuntimeException {}
