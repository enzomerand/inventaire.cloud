<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUlidRouteKey;
use App\PhotoEntry\PhotoAnalysisCleanup;
use App\PhotoEntry\PhotoAnalysisError;
use App\PhotoEntry\PhotoAnalysisStatus;
use App\PhotoEntry\PhotoImages;
use Database\Factories\PhotoAnalysisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The photos of one object that a person sent so that Claude proposes the values of the fields of an inventory, from the day they were
 * received to the proposals (or the failure).
 *
 * The row is created `pending` when the photos are received: `photo_count` of them were re-encoded (see {@see PhotoImages})
 * and stored with the other private files, under the folder of the inventory, on `disk`. The queue takes it, `running`, asks Claude and
 * ends it, `completed` (`proposals`: a list of `{field, value, confidence, evidence}`, the value in the canonical form the field stores,
 * and `model` and the tokens the request was billed) or `failed` (`error` holds the code of {@see PhotoAnalysisError}). The photos are
 * deleted as soon as the job ends; the proposals are kept until `expires_at`. Deleting the row purges its folder once the deletion is
 * committed ({@see PhotoAnalysisCleanup}). Only the person who sent the photos can see their analysis. `ulid` is the identifier the
 * outside world uses ({@see HasUlidRouteKey}).
 *
 * @property int $id
 * @property string $ulid
 * @property int $inventory_id
 * @property int $user_id
 * @property PhotoAnalysisStatus $status
 * @property string|null $error
 * @property string $disk
 * @property string $locale
 * @property int $photo_count
 * @property string|null $model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property list<array{field: string, value: mixed, confidence: string, evidence: string}>|null $proposals
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PhotoAnalysis extends Model
{
    /** @use HasFactory<PhotoAnalysisFactory> */
    use HasFactory, HasUlidRouteKey;

    /**
     * The table is named for the plural of "analysis", which the framework would not guess.
     *
     * @var string
     */
    protected $table = 'photo_analyses';

    /**
     * Has the folder of the photos purged from the disk once the deletion of the row is committed. The rows that go with their
     * inventory, workspace or account are cascaded by the database and fire no event: their folders are purged by the deletion of
     * what holds them ({@see PhotoAnalysisCleanup}).
     */
    protected static function booted(): void
    {
        self::deleted(function (self $analysis): void {
            app(PhotoAnalysisCleanup::class)->purge($analysis);
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
            'status' => PhotoAnalysisStatus::class,
            'photo_count' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'proposals' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The inventory the photos are for.
     *
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /**
     * Whether the time of the analysis is up (it may not have been cleared away yet).
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The failure, when the analysis failed with a code this version knows.
     */
    public function failure(): ?PhotoAnalysisError
    {
        return $this->error === null ? null : PhotoAnalysisError::tryFrom($this->error);
    }
}
