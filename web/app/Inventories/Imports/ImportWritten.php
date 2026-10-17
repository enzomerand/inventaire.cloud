<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

/**
 * What the writer did: how many items it added and fields it created, and the rows it had to leave out after all.
 */
final readonly class ImportWritten
{
    /**
     * @param  int  $created  Items added.
     * @param  int  $fields  Fields created.
     * @param  list<array{row: int, column: string|null, message: string}>  $skipped  The errors of rows that were not valid any more under the lock, and left out.
     * @param  int  $skippedRows  How many rows were left out (the errors are only the first ones).
     */
    public function __construct(public int $created, public int $fields, public array $skipped = [], public int $skippedRows = 0) {}
}
