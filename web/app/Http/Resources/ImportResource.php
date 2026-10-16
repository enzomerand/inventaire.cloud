<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Inventories\Imports\ImportError;
use App\Inventories\Imports\ImportMapping;
use App\Inventories\Imports\ImportStatus;
use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An import as the API gives it: the file that was sent, how it is read, what it holds and where each column goes, then where it is up to
 * and, once it ends, the report.
 *
 * ```
 * {"id", "format", "status", "error", "file_name", "size", "options", "columns", "mapping", "rows_total", "rows_imported",
 *  "rows_failed", "fields_created", "errors", "created_at", "completed_at", "expires_at"}
 * ```
 *
 * `status` is `uploaded` (read, waiting for the person to start it), `pending`, `running`, `completed` or `failed`. `error` is the
 * message to show for a failed import, in the language of the request, and null otherwise. `options` is
 * `{has_header, delimiter, encoding, decimal, date_order, on_error}` (the first two of a text file are null for a spreadsheet),
 * `columns` is a list of `{index, header, samples, guess}` (`guess`: the key of the type of field the values look like) and `mapping` a list of
 * `{column, target, field, type, name}`: the proposal while the import is `uploaded`, what was started after. The counts and `errors`
 * (a list of `{row, column, message}`, the first 100) are null until the import ends. The ids are the ULIDs; nothing numeric is given out.
 *
 * @property Import $resource
 */
final class ImportResource extends JsonResource
{
    /**
     * The import as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $import = $this->resource;

        return [
            'id' => $import->ulid,
            'format' => $import->format->value,
            'status' => $import->status->value,
            // A code a later version wrote is told as the general failure.
            'error' => $import->status === ImportStatus::Failed ? ($import->failure() ?? ImportError::ImportFailed)->message() : null,
            'file_name' => $import->file_name,
            'size' => $import->size,
            'options' => $import->settings()->toArray(),
            'columns' => $import->columns,
            'mapping' => ImportMapping::from($import->mapping ?? [])->toArray(),
            'rows_total' => $import->rows_total,
            'rows_imported' => $import->rows_imported,
            'rows_failed' => $import->rows_failed,
            'fields_created' => $import->fields_created,
            'errors' => $import->errors,
            'created_at' => $import->created_at?->toIso8601String(),
            'completed_at' => $import->completed_at?->toIso8601String(),
            'expires_at' => $import->expires_at?->toIso8601String(),
        ];
    }
}
