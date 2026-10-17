<?php

declare(strict_types=1);

namespace App\Models;

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Versions\OfficialVersions;
use App\Inventories\Versions\SignatureError;
use App\Inventories\Versions\SignatureStatus;
use App\Inventories\Versions\SignerStatus;
use App\Inventories\Versions\VersionError;
use App\Inventories\Versions\VersionStatus;
use App\Models\Concerns\HasUlidRouteKey;
use Database\Factories\InventoryVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An official version of an inventory: its state frozen at a date, numbered inventory by inventory (1, 2, 3...), with the PDF that
 * was written from that state and, when signatures were asked for, where they stand.
 *
 * It is created `pending` by {@see OfficialVersions::create()}, in the transaction that reads the inventory under its lock: the
 * canonical JSON of what the inventory held (`snapshot_*`, the SHA-256 is that of the bytes of the file) is the version, and
 * everything else is written from it. A queued job then writes the PDF (`running`, then `completed` or `failed` with an `error`).
 * The author's name is frozen with the version (`author_name`): deleting the account that froze it forgets the person
 * (`user_id` becomes null), not the version. `locale` is the language the PDF is written in.
 *
 * `signers` is the list of the people asked to sign: `{name, email, status, signed_at, rejection_reason, recipient_id, field}`
 * where `status` is a {@see SignerStatus}, `recipient_id` the id Documenso gave the person and `field` where the signature box
 * of that person is on the last page of the PDF, as percentages of the page, measured when the PDF was laid out:
 * `{page, x, y, width, height}`, `y` counted from the top. Without signers, `signature_status` is null.
 *
 * The files are stored with the other private files, under the folder of the version in the folder of its inventory (see
 * {@see AttachmentStorage::versionDirectory()}), so that deleting the inventory or the workspace takes them away.
 * `counted_bytes` is what the version adds to the `storage_used` of its workspace right now: nothing until the PDF is written,
 * then the snapshot and the PDF, then the signed PDF too. It is the only figure a deletion gives back. `ulid` is the identifier
 * the outside world uses ({@see HasUlidRouteKey}).
 *
 * @property int $id
 * @property string $ulid
 * @property int $inventory_id
 * @property int|null $user_id
 * @property int $number
 * @property string|null $label
 * @property string $author_name
 * @property string $locale
 * @property Carbon $frozen_at
 * @property int $items_count
 * @property int $fields_count
 * @property VersionStatus $status
 * @property VersionError|null $error
 * @property string $disk
 * @property string $snapshot_path
 * @property int $snapshot_size
 * @property string $snapshot_sha256
 * @property string|null $pdf_path
 * @property int|null $pdf_size
 * @property string|null $pdf_sha256
 * @property int|null $pdf_pages
 * @property int $counted_bytes
 * @property SignatureStatus|null $signature_status
 * @property SignatureError|null $signature_error
 * @property list<array<string, mixed>>|null $signers
 * @property string|null $documenso_envelope_id
 * @property Carbon|null $signature_requested_at
 * @property Carbon|null $signature_completed_at
 * @property Carbon|null $signature_synced_at
 * @property string|null $signed_pdf_path
 * @property int|null $signed_pdf_size
 * @property string|null $signed_pdf_sha256
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class InventoryVersion extends Model
{
    /** @use HasFactory<InventoryVersionFactory> */
    use HasFactory, HasUlidRouteKey;

    /** The name of the snapshot in the folder of its version. */
    public const SNAPSHOT_NAME = 'snapshot.json';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'frozen_at' => 'datetime',
            'items_count' => 'integer',
            'fields_count' => 'integer',
            'status' => VersionStatus::class,
            'error' => VersionError::class,
            'snapshot_size' => 'integer',
            'pdf_size' => 'integer',
            'pdf_pages' => 'integer',
            'counted_bytes' => 'integer',
            'signature_status' => SignatureStatus::class,
            'signature_error' => SignatureError::class,
            'signers' => 'array',
            'signature_requested_at' => 'datetime',
            'signature_completed_at' => 'datetime',
            'signature_synced_at' => 'datetime',
            'signed_pdf_size' => 'integer',
        ];
    }

    /**
     * The folder the files of the version are in, on its disk: the one the snapshot is in. Null when the snapshot is not where a version
     * keeps it (`workspaces/{workspace}/inventories/{inventory}/versions/{ulid}/snapshot.json`), so that nothing is ever purged by a
     * path that is not a version's own.
     */
    public function directory(): ?string
    {
        $name = '/versions/'.$this->ulid.'/'.self::SNAPSHOT_NAME;

        return str_starts_with($this->snapshot_path, 'workspaces/') && str_ends_with($this->snapshot_path, $name)
            ? substr($this->snapshot_path, 0, -strlen('/'.self::SNAPSHOT_NAME))
            : null;
    }

    /**
     * The inventory the version was frozen from.
     *
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /**
     * The person who froze the inventory, unless their account was deleted since.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
