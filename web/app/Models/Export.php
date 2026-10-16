<?php

declare(strict_types=1);

namespace App\Models;

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Exports\ExportCleanup;
use App\Inventories\Exports\ExportError;
use App\Inventories\Exports\ExportFormat;
use App\Inventories\Exports\ExportScope;
use App\Inventories\Exports\ExportStatus;
use App\Models\Concerns\HasUlidRouteKey;
use Database\Factories\ExportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file a person asked for to take the items of an inventory out of the application (a spreadsheet, a CSV, a JSON, a PDF, a
 * zip of the photos), from the request to the download.
 *
 * The row is created `pending` by the request and the queue writes the file: `running` while it does, then `completed`
 * (the file is stored on `disk` at `path`, named `file_name`, until `expires_at`) or `failed` (`error` holds the code of
 * {@see ExportError}). `options` keeps what the format needs and the request chose: the view the grid showed (`query`, for the
 * scope `view`), the `delimiter` of a CSV and the `files` of a zip. `locale` is the language the file is written in, the one the
 * person was reading the application in.
 *
 * The file is stored with the other private files, under the folder of its inventory (see {@see AttachmentStorage}), so that
 * deleting the inventory or the workspace takes it away; deleting the row purges its folder once the deletion is committed
 * ({@see ExportCleanup}). Only the person who asked for an export can read it. `ulid` is the identifier the outside world uses
 * ({@see HasUlidRouteKey}).
 *
 * @property int $id
 * @property string $ulid
 * @property int $inventory_id
 * @property int $user_id
 * @property ExportFormat $format
 * @property ExportScope $scope
 * @property array<string, mixed>|null $options
 * @property string $locale
 * @property ExportStatus $status
 * @property string|null $error
 * @property string $disk
 * @property string|null $path
 * @property string|null $file_name
 * @property string|null $mime
 * @property int|null $size
 * @property int|null $items_count
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Export extends Model
{
    /** @use HasFactory<ExportFactory> */
    use HasFactory, HasUlidRouteKey;

    /**
     * Has the folder of the file purged from the disk once the deletion of the row is committed. The rows that go with their
     * inventory, workspace or account are cascaded by the database and fire no event: their folders are purged by the deletion of
     * what holds them ({@see ExportCleanup}).
     */
    protected static function booted(): void
    {
        self::deleted(function (self $export): void {
            app(ExportCleanup::class)->purge($export);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'format' => ExportFormat::class,
            'scope' => ExportScope::class,
            'status' => ExportStatus::class,
            'options' => 'array',
            'size' => 'integer',
            'items_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The inventory the export was made from.
     *
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /**
     * The person who asked for it, and the only one who can read it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The exports that still count: those without an end date yet (being written) and those that have not expired.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $active): Builder => $active->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Whether the file can be downloaded: the export is completed and its file has not expired.
     */
    public function isDownloadable(): bool
    {
        return $this->status === ExportStatus::Completed
            && $this->path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * The failure, when the export failed with a code this version knows.
     */
    public function failure(): ?ExportError
    {
        return ExportError::fromCode($this->error);
    }

    /**
     * What the request chose for the format: an option of `options`, or the default when it was not given.
     */
    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }
}
