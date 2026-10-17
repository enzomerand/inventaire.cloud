<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

use App\Auth\EmailAddress;
use App\Database\DeadlockRetries;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Attachments\WorkspaceStorage;
use App\Inventories\Exceptions\ExportRefusedException;
use App\Inventories\Exceptions\SignatureUnavailableException;
use App\Inventories\Exceptions\StorageLimitReachedException;
use App\Inventories\Exceptions\VersionLimitReachedException;
use App\Inventories\Exports\ExportTemp;
use App\Inventories\Exports\PdfWriter;
use App\Inventories\Fields\FieldTypes;
use App\Jobs\GenerateOfficialVersion;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\PersonName;
use App\Rules\SingleLineText;
use App\Signatures\Documenso;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Freezing an inventory as an official version, and deleting one: what the API does apart from the HTTP.
 *
 * **Freezing is synchronous and consistent**, because "frozen" must mean "the state at the click". After the checks that need no lock
 * (below), one transaction locks the account of the author, then the inventory ({@see Locks}: every writer of the items, the fields and
 * the files of an inventory takes the same lock), counts the versions the inventory holds against the quota, numbers the new one (the
 * highest number plus one), writes the canonical `snapshot.json` to a scratch file ({@see SnapshotWriter}) and inserts the row
 * `pending`. Nothing can write to the inventory in between, so the file is one state of it, and its SHA-256 (of the bytes of the file)
 * is recorded. That takes the time of reading the items, a second or two for ten thousand, during which the inventory is read-only;
 * the job that writes the PDF runs later, from the file, and does not hold anything. Once the transaction is committed the file is copied
 * to the disk (a failure marks the version `failed`/`storage_failed`, with nothing stored) and the job is queued. Nothing is counted
 * against the storage of the workspace until the job has written the PDF: a version in the making weighs nothing, and one that fails
 * leaves nothing.
 *
 * **The checks that need no lock** come first and are made on what the inventory holds now (the one on the PDF is made again, under the
 * lock, on what it holds then): the people to sign (names and addresses go through the rules and the normalisation of the accounts, and
 * must differ from one another: a `ValidationException` under `signers.N.`), a PDF with more items than a PDF may hold, as for the
 * exports ({@see ExportRefusedException}), a workspace whose files already weigh its quota ({@see StorageLimitReachedException}), and
 * signatures asked for when the instance has no Documenso ({@see SignatureUnavailableException}).
 *
 * **Deleting** a version takes the lock of the workspace, then the one of the inventory, gives its `counted_bytes` back to the storage
 * counter, deletes the row and purges its folder once that is committed.
 */
final class OfficialVersions
{
    /** The longest label of a version, in characters. */
    public const LABEL_MAX_LENGTH = 120;

    /** The most signers the page of signatures holds, whatever `inventories.max_signers` says. */
    public const SIGNERS_MAX = 10;

    /**
     * Gets the database, the locks, the writer of the snapshot and the storage.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly SnapshotWriter $snapshots,
        private readonly VersionGenerator $generator,
        private readonly FieldTypes $types,
        private readonly AttachmentStorage $storage,
        private readonly WorkspaceStorage $usage,
    ) {}

    /**
     * The most people a version may ask a signature from: `inventories.max_signers`, and never more than the page of signatures holds.
     */
    public static function maxSigners(): int
    {
        return max(1, min(self::SIGNERS_MAX, config()->integer('inventories.max_signers')));
    }

    /**
     * Freezes the inventory as the next official version on behalf of a person.
     *
     * @param  string|null  $label  What the person calls the version, at most {@see self::LABEL_MAX_LENGTH} characters; none when it is null or empty.
     * @param  array<array-key, mixed>  $signers  The people to ask a signature from, each `['name' => ..., 'email' => ...]`; none for no signature.
     * @return InventoryVersion The version, `pending` while its job writes the PDF (`failed` when the data could not be stored).
     *
     * @throws ValidationException When the label or a signer is not valid (`label`, `signers`, `signers.N.name`, `signers.N.email`).
     * @throws ExportRefusedException With `PDF_TOO_LARGE` when the inventory holds more items than a PDF may.
     * @throws StorageLimitReachedException When the files of the workspace already weigh its quota.
     * @throws SignatureUnavailableException When signers are given and the instance has no Documenso.
     * @throws VersionLimitReachedException When the inventory already holds as many versions as it may.
     * @throws ModelNotFoundException When the account of the person or the inventory was deleted in the meantime.
     */
    public function create(Inventory $inventory, User $author, ?string $label, array $signers): InventoryVersion
    {
        [$label, $signers] = $this->validated($label, $signers);
        $this->check($inventory, $signers);

        $scratch = ExportTemp::file('versions', 'snapshot-');

        try {
            $version = DeadlockRetries::run(fn (): InventoryVersion => $this->db->transaction(fn (): InventoryVersion => $this->freeze($inventory, $author, $label, $signers, $scratch)));

            return $this->store($version, $scratch);
        } finally {
            @unlink($scratch);
        }
    }

    /**
     * Deletes a version, and its files once that is committed: the bytes it counted go back to the storage of its workspace.
     *
     * @throws ModelNotFoundException When the version, its inventory or its workspace was deleted in the meantime.
     */
    public function delete(InventoryVersion $version): void
    {
        // Read before the transaction: a plain read opens the snapshot the rest of a transaction reads from, which must come after the locks.
        $workspaceId = Inventory::query()->whereKey($version->inventory_id)->value('workspace_id');

        if ($workspaceId === null) {
            throw (new ModelNotFoundException)->setModel(Inventory::class, [$version->inventory_id]);
        }

        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($version, $workspaceId): void {
            $workspace = $this->locks->workspace($workspaceId);
            $inventory = $this->locks->inventory($version->inventory_id);

            // As it is now, under the locks: the job that completes a version moves the counter under the lock of the workspace.
            $locked = InventoryVersion::query()->where('inventory_id', $inventory->getKey())->whereKey($version->getKey())->first()
                ?? throw (new ModelNotFoundException)->setModel(InventoryVersion::class, [$version->getKey()]);

            $this->usage->move($workspace, -$locked->counted_bytes);
            $directory = $locked->directory();
            $locked->delete();

            if ($directory !== null) {
                $this->storage->purgeAfterCommit($locked->disk, [], [$directory]);
            }
        }));
    }

    /**
     * The label and the signers as they are kept: the label trimmed (none when it is empty), the names and the addresses in their
     * canonical form, each one checked.
     *
     * @param  array<array-key, mixed>  $signers
     * @return array{0: string|null, 1: list<array{name: string, email: string}>}
     *
     * @throws ValidationException
     */
    private function validated(?string $label, array $signers): array
    {
        $label = $label === null ? null : SingleLineText::normalize($label);
        $label = $label === '' ? null : $label;
        $prepared = [];

        foreach (array_values($signers) as $index => $signer) {
            $prepared[$index] = is_array($signer)
                ? [
                    'name' => is_string($signer['name'] ?? null) ? SingleLineText::normalize($signer['name']) : ($signer['name'] ?? null),
                    'email' => is_string($signer['email'] ?? null) ? EmailAddress::normalize($signer['email']) : ($signer['email'] ?? null),
                ]
                : $signer;
        }

        $validator = Validator::make(['label' => $label, 'signers' => $prepared], [
            'label' => ['nullable', 'string', 'max:'.self::LABEL_MAX_LENGTH, new SingleLineText],
            'signers' => ['array', 'max:'.self::maxSigners()],
            'signers.*' => ['array'],
            'signers.*.name' => PersonName::rules(),
            'signers.*.email' => [...EmailAddress::rules(), 'distinct'],
        ], [
            'signers.*.email.distinct' => __('Each signer needs a different email address.'),
        ]);

        $validator->validate();

        $clean = [];

        foreach ($prepared as $signer) {
            $clean[] = ['name' => (string) $signer['name'], 'email' => (string) $signer['email']];
        }

        return [$label, $clean];
    }

    /**
     * The checks that need no lock.
     *
     * @param  list<array{name: string, email: string}>  $signers
     *
     * @throws ExportRefusedException
     * @throws StorageLimitReachedException
     * @throws SignatureUnavailableException
     */
    private function check(Inventory $inventory, array $signers): void
    {
        $limit = PdfWriter::limitFor($inventory->fields()->get(), $this->types);

        if ($inventory->items()->count() > $limit) {
            throw new ExportRefusedException(ExportRefusedException::PDF_TOO_LARGE, $limit);
        }

        $quota = config()->integer('inventories.storage_per_workspace');

        if ((int) Workspace::query()->whereKey($inventory->workspace_id)->value('storage_used') >= $quota) {
            throw new StorageLimitReachedException($quota);
        }

        if ($signers !== [] && ! Documenso::configured()) {
            throw new SignatureUnavailableException;
        }
    }

    /**
     * Numbers the version, writes its snapshot to `$scratch` and inserts it, under the lock of the inventory, in the transaction of the
     * caller.
     *
     * @param  list<array{name: string, email: string}>  $signers
     *
     * @throws VersionLimitReachedException
     * @throws ExportRefusedException
     * @throws ModelNotFoundException
     */
    private function freeze(Inventory $inventory, User $author, ?string $label, array $signers, string $scratch): InventoryVersion
    {
        // The account first, then the inventory (see Locks): the row records its author, and saving it locks the account's row.
        $this->locks->author($author);
        $locked = $this->locks->inventory($inventory->getKey());

        $limit = config()->integer('inventories.max_versions_per_inventory');
        $versions = InventoryVersion::query()->where('inventory_id', $locked->getKey());

        if ((clone $versions)->count() >= $limit) {
            throw new VersionLimitReachedException($limit);
        }

        $workspace = Workspace::query()->findOrFail($locked->workspace_id);
        $fields = $locked->fields()->get();
        $pdfLimit = PdfWriter::limitFor($fields, $this->types);

        // The items counted by the check before the lock may have grown since; the counter of the inventory is exact under its lock.
        if ($locked->items_count > $pdfLimit) {
            throw new ExportRefusedException(ExportRefusedException::PDF_TOO_LARGE, $pdfLimit);
        }

        $ulid = strtolower((string) Str::ulid());

        $version = new InventoryVersion;
        $version->forceFill([
            'ulid' => $ulid,
            'inventory_id' => $locked->getKey(),
            'user_id' => $author->getKey(),
            'number' => 1 + (int) (clone $versions)->max('number'),
            'label' => $label,
            'author_name' => $author->name,
            'locale' => mb_substr(app()->getLocale(), 0, 8),
            'frozen_at' => now()->startOfSecond(),
            'fields_count' => $fields->count(),
            'status' => VersionStatus::Pending,
            'disk' => $this->storage->diskName(),
            'counted_bytes' => 0,
            'signature_status' => $signers === [] ? null : SignatureStatus::Requested,
            'signers' => $signers === [] ? null : array_map(fn (array $signer): array => $signer + [
                'status' => SignerStatus::Pending->value,
                'signed_at' => null,
                'rejection_reason' => null,
                'recipient_id' => null,
            ], $signers),
        ]);

        $items = $this->snapshots->write($scratch, $version, $workspace, $locked, $fields);

        $version->forceFill([
            'items_count' => $items,
            'snapshot_path' => AttachmentStorage::versionDirectory($workspace->ulid, $locked->ulid, $ulid).'/'.InventoryVersion::SNAPSHOT_NAME,
            'snapshot_size' => (int) filesize($scratch),
            'snapshot_sha256' => (string) hash_file('sha256', $scratch),
        ])->save();

        return $version;
    }

    /**
     * Copies the snapshot to the disk once the version is committed, then queues the job that writes the PDF. A disk that refuses the
     * file marks the version `failed`/`storage_failed` instead and queues nothing; a queue that is down is only told in the log, and the
     * version is given up on by `inventaire:sync-versions` if no worker ever picks it up.
     */
    private function store(InventoryVersion $version, string $scratch): InventoryVersion
    {
        try {
            $this->storage->writeFrom($version->disk, $version->snapshot_path, $scratch);
        } catch (Throwable $exception) {
            Log::error('The snapshot of an official version could not be stored.', ['version' => $version->ulid, 'exception' => $exception]);
            $this->generator->fail($version, VersionError::StorageFailed);

            return $version->refresh();
        }

        try {
            GenerateOfficialVersion::dispatch($version);
        } catch (Throwable $exception) {
            Log::error('The job that writes the PDF of an official version could not be queued.', ['version' => $version->ulid, 'exception' => $exception]);
        }

        return $version;
    }
}
