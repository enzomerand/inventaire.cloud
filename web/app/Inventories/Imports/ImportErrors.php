<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Models\InventoryField;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The report of the rows that are not valid: the first {@see self::MAX} errors, each with the number of the row in the file, the header
 * of the column and what is wrong, and a count of all the rows in error. The report is stored with the import (`imports.errors`).
 */
final class ImportErrors
{
    /** Most errors kept in the report: a file where every row is wrong must not make a report as large as the file. */
    public const MAX = 100;

    /** @var list<array{row: int, column: string|null, message: string}> */
    private array $errors = [];

    private int $rows = 0;

    /**
     * Notes the errors of a row that is not valid.
     *
     * @param  list<array{row: int, column: string|null, message: string}>  $errors  One or more, for the same row.
     */
    public function add(array $errors): void
    {
        $this->rows++;

        foreach ($errors as $error) {
            if (count($this->errors) < self::MAX) {
                $this->errors[] = $error;
            }
        }
    }

    /**
     * The errors kept: the first ones.
     *
     * @return list<array{row: int, column: string|null, message: string}>
     */
    public function all(): array
    {
        return $this->errors;
    }

    /**
     * How many rows are not valid, all of them and not only the ones reported.
     */
    public function rows(): int
    {
        return $this->rows;
    }

    /**
     * Whether any row is not valid.
     */
    public function any(): bool
    {
        return $this->rows > 0;
    }

    /**
     * The errors a row's values were refused with, as report lines: one for each value refused, under the header of its column. The
     * messages are those of the rules of the field, which name the field.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields the values were checked against.
     * @param  array<string, string>  $labels  The headers of the columns by the ULID of the field they fill.
     * @return list<array{row: int, column: string|null, message: string}>
     */
    public static function fromValidation(ValidationException $exception, int $row, Collection $fields, array $labels): array
    {
        $names = $fields->pluck('name', 'ulid');
        $errors = [];

        foreach ($exception->errors() as $key => $messages) {
            $ulid = str_starts_with($key, 'values.') ? substr($key, 7) : null;

            foreach ($messages as $message) {
                $errors[] = ['row' => $row, 'column' => $ulid === null ? null : ($labels[$ulid] ?? $names[$ulid] ?? null), 'message' => $message];
            }
        }

        return $errors;
    }
}
