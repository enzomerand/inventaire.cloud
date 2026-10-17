<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use App\Inventories\Imports\ImportError;
use RuntimeException;

/**
 * An import that cannot go through, with the reason that goes on the import and, when rows are the reason, the report of those rows.
 * Thrown inside the transaction that writes the items so that nothing of it is kept, and caught by the runner, which records the
 * failure.
 */
final class ImportFailure extends RuntimeException
{
    /**
     * @param  ImportError  $error  Why.
     * @param  list<array{row: int, column: string|null, message: string}>  $errors  The rows in error, when that is the reason.
     * @param  int  $failedRows  How many rows are in error (the errors are only the first ones).
     * @param  int|null  $rowsTotal  How many rows the file holds, when the failure comes after they were counted.
     */
    public function __construct(public readonly ImportError $error, public readonly array $errors = [], public readonly int $failedRows = 0, public readonly ?int $rowsTotal = null)
    {
        parent::__construct("The import failed: {$error->value}.");
    }
}
