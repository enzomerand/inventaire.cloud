<?php

declare(strict_types=1);

namespace App\Models;

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Imports\ImportCleanup;
use App\Inventories\Imports\ImportError;
use App\Inventories\Imports\ImportFormat;
use App\Inventories\Imports\ImportOptions;
use App\Inventories\Imports\ImportStatus;
use App\Models\Concerns\HasUlidRouteKey;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file a person brought to add items to an inventory (a spreadsheet, a CSV), from the day it was sent to the report of what became of
 * it.
 *
 * The row is created `uploaded` when the file is sent: it was read once, `columns` says what it holds (the header, a few samples and
 * the type guessed for each column) and `mapping` where each column goes (proposed, then the person's). The person adjusts it and
 * `options`, then starts the import: `pending`, then the queue takes it, `running`, and ends it, `completed` or `failed` (`error`
 * holds the code of {@see ImportError}). The counts (`rows_total`, `rows_imported`, `rows_failed`, `fields_created`) and the first
 * `errors` (row, column, message) are the report, kept until `expires_at`; so is a file that was sent and never started.
 *
 * The file is stored with the other private files, under the folder of its inventory (see {@see AttachmentStorage}), and is deleted as
 * soon as the job ends: `path` is null from then on. Deleting the row purges its folder once the deletion is committed
 * ({@see ImportCleanup}). Only the person who sent a file can see its import. `ulid` is the identifier the outside world uses
 * ({@see HasUlidRouteKey}).
 *
 * @property int $id
 * @property string $ulid
 * @property int $inventory_id
 * @property int $user_id
 * @property ImportFormat $format
 * @property ImportStatus $status
 * @property string|null $error
 * @property string $disk
 * @property string|null $path
 * @property string $file_name
 * @property int $size
 * @property string $locale
 * @property array<string, mixed> $options
 * @property list<array<string, mixed>> $columns
 * @property list<array<string, mixed>>|null $mapping
 * @property int|null $rows_total
 * @property int|null $rows_imported
 * @property int|null $rows_failed
 * @property int|null $fields_created
 * @property list<array{row: int, column: string|null, message: string}>|null $errors
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory, HasUlidRouteKey;

    /**
     * Has the folder of the file purged from the disk once the deletion of the row is committed. The rows that go with their
     * inventory, workspace or account are cascaded by the database and fire no event: their folders are purged by the deletion of
     * what holds them ({@see ImportCleanup}).
     */
    protected static function booted(): void
    {
        self::deleted(function (self $import): void {
            app(ImportCleanup::class)->purge($import);
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
            'format' => ImportFormat::class,
            'status' => ImportStatus::class,
            'size' => 'integer',
            'options' => 'array',
            'columns' => 'array',
            'mapping' => 'array',
            'rows_total' => 'integer',
            'rows_imported' => 'integer',
            'rows_failed' => 'integer',
            'fields_created' => 'integer',
            'errors' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The inventory the import adds items to.
     *
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /**
     * The imports that are not over: a file that was sent and not started (until it expires), one waiting for the queue and one being
     * written. They are what the quota of imports counts; a finished import is only a report.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [ImportStatus::Uploaded->value, ImportStatus::Pending->value, ImportStatus::Running->value])
            ->where(fn (Builder $live): Builder => $live->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * The imports whose time is not up: the ones without an end date yet and those that have not expired. They are what the person
     * is shown.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnexpired(Builder $query): Builder
    {
        return $query->where(fn (Builder $live): Builder => $live->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * What the import is read and converted with, in the language it was sent in.
     */
    public function settings(): ImportOptions
    {
        return ImportOptions::fromArray($this->options, $this->locale);
    }

    /**
     * Whether the time of the import is up (it may not have been cleared away yet).
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The failure, when the import failed with a code this version knows.
     */
    public function failure(): ?ImportError
    {
        return $this->error === null ? null : ImportError::tryFrom($this->error);
    }
}
