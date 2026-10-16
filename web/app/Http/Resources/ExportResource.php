<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Inventories\Exports\ExportError;
use App\Inventories\Exports\ExportStatus;
use App\Models\Export;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An export as the API gives it: what it is, where it is up to, and the address of the file once it is ready.
 *
 * ```
 * {"id", "format", "scope", "status", "error", "file_name", "size", "items_count", "created_at", "completed_at", "expires_at",
 *  "download_url"}
 * ```
 *
 * `status` is `pending`, `running`, `completed` or `failed`. `error` is the message to show for a failed export, in the language
 * of the request, and null otherwise. `file_name`, `size` and `items_count` are null until the export is completed;
 * `download_url` (a path of the application, to be followed with the session of the reader) is null until then and after the
 * export expired. The ids are the ULIDs; nothing numeric is given out.
 *
 * @property Export $resource
 */
final class ExportResource extends JsonResource
{
    /**
     * Remembers the export and the ULID of its inventory, which the address of its file is made of.
     */
    public function __construct($resource, private readonly string $inventoryUlid)
    {
        parent::__construct($resource);
    }

    /**
     * The export as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $export = $this->resource;

        return [
            'id' => $export->ulid,
            'format' => $export->format->value,
            'scope' => $export->scope->value,
            'status' => $export->status->value,
            // A code a later version wrote is told as the general failure.
            'error' => $export->status === ExportStatus::Failed ? ($export->failure() ?? ExportError::GenerationFailed)->message() : null,
            'file_name' => $export->file_name,
            // Before the file is written the size of a zip is only an estimate, kept to count it against the room of the person.
            'size' => $export->status === ExportStatus::Completed ? $export->size : null,
            'items_count' => $export->items_count,
            'created_at' => $export->created_at?->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'download_url' => $export->isDownloadable() ? route('api.v1.inventories.exports.download', ['inventory' => $this->inventoryUlid, 'export' => $export->ulid], false) : null,
        ];
    }
}
