<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

use App\Database\DeadlockRetries;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Attachments\WorkspaceStorage;
use App\Inventories\Exceptions\PdfTooLargeException;
use App\Inventories\Exports\ExportTemp;
use App\Jobs\GenerateOfficialVersion;
use App\Jobs\RequestSignatures;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Writes the PDF of an official version and records how it went: what {@see GenerateOfficialVersion} does, apart from the queue.
 *
 * The version is claimed first, `pending` to `running` in one conditional update, so that two workers handed the same job (or a job
 * handed again) cannot both write it; the one that loses simply stops. The snapshot is read back from the disk and must be the very
 * bytes that were frozen (size and SHA-256), the PDF is written from it to a local scratch file, stored next to it, and the version
 * is then completed: under the lock of the workspace, the conditional update to `completed` and the move of the storage counter by the
 * weight of the two files are one decision, so that the counter and `counted_bytes` never disagree. If the version is gone by then
 * (it was deleted while it was being written, or its inventory or workspace was), nothing is counted and the folder just written is
 * purged instead, by the path it was written to: the row that says where the folder is is gone.
 *
 * Anything that goes wrong marks the version `failed` with a code ({@see VersionError}) and deletes what was stored: a version that
 * holds nothing is not counted for anything, and one that cannot be read back would only confuse. The PDF is written in the language
 * of the version, whatever the language of the worker. When signers were asked for, the position of each one's signature area, measured
 * on the laid-out page ({@see OfficialPdfWriter}), is recorded with them in `signers` (`field`), and once the version is completed
 * the request for signatures is queued ({@see RequestSignatures}).
 */
final class VersionGenerator
{
    /** The name of the PDF in the folder of its version. */
    public const PDF_NAME = 'official.pdf';

    /**
     * Gets what the snapshot is read and the PDF is written with, the storage and the locks of the counter.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly AttachmentStorage $storage,
        private readonly WorkspaceStorage $usage,
        private readonly SnapshotReader $reader,
        private readonly OfficialPdfWriter $writer,
    ) {}

    /**
     * Writes the PDF of the version, unless another worker has already taken it. Never throws for what goes wrong with the version
     * itself: it is recorded on the version.
     */
    public function run(InventoryVersion $version): void
    {
        $claimed = InventoryVersion::query()
            ->whereKey($version->getKey())
            ->where('status', VersionStatus::Pending->value)
            ->update(['status' => VersionStatus::Running->value, 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $locale = App::getLocale();
        $scratch = null;

        try {
            // Read inside the try: the row may have been deleted since it was claimed.
            $version->refresh();
            App::setLocale($version->locale);
            $scratch = ExportTemp::file('versions', 'version-');
            $this->generate($version, $scratch);
        } catch (ModelNotFoundException) {
            // The version, its inventory or its workspace was deleted while the PDF was written: nothing to report, only what was
            // stored to purge (by the folder of the version, worked out from its ULID).
            $this->purge($version);
        } catch (PdfTooLargeException $exception) {
            // What the inventory held when it was frozen was fine for the count: its texts are long. Nothing is wrong with the
            // server, so it is not an error of the log.
            Log::warning('The PDF of an official version was too large for the library.', ['version' => $version->ulid, 'message' => $exception->getMessage()]);
            $this->fail($version, VersionError::PdfTooLarge);
        } catch (Throwable $exception) {
            Log::error('The PDF of an official version could not be generated.', ['version' => $version->ulid, 'exception' => $exception]);
            $this->fail($version, VersionError::GenerationFailed);
        } finally {
            if ($scratch !== null) {
                @unlink($scratch);
            }

            App::setLocale($locale);
        }
    }

    /**
     * Gives up on a version that has not ended: `failed` with the code, nothing counted, its folder purged. A version that has
     * completed in the meantime is left as it is, files included: a late call (a job reported failed after it ended, the schedule
     * acting on a row it read before it completed) must never delete a file somebody can download. For a version that is gone, or
     * already failed, the folder is purged all the same: nothing of a failed attempt may stay.
     */
    public function fail(InventoryVersion $version, VersionError $error): void
    {
        InventoryVersion::query()
            ->whereKey($version->getKey())
            ->whereIn('status', [VersionStatus::Pending->value, VersionStatus::Running->value])
            ->update([
                'status' => VersionStatus::Failed->value,
                'error' => $error->value,
                'updated_at' => now(),
            ]);

        // Whatever the update did, the row is now completed (it won the race: keep its files), failed or gone (purge).
        if (InventoryVersion::query()->whereKey($version->getKey())->where('status', VersionStatus::Completed->value)->exists()) {
            return;
        }

        $this->purge($version);
    }

    /**
     * Reads the snapshot, writes the PDF in `$scratch`, stores it and completes the version.
     *
     * @throws PdfTooLargeException When the PDF would be too big for the library.
     * @throws ModelNotFoundException When the version was deleted meanwhile.
     * @throws RuntimeException When the snapshot is not the one that was frozen, or a file cannot be read or stored.
     */
    private function generate(InventoryVersion $version, string $scratch): void
    {
        $directory = $version->directory() ?? throw new RuntimeException('The snapshot of the version is not where a version keeps it.');
        $contents = $this->storage->disk($version->disk)->get($version->snapshot_path);

        if (! is_string($contents)) {
            throw new RuntimeException('The snapshot of the version could not be read from the disk.');
        }

        $content = $this->reader->read($contents, $version->snapshot_size, $version->snapshot_sha256);
        unset($contents);

        $written = $this->writer->write($version, $content, $scratch);
        $path = $directory.'/'.self::PDF_NAME;
        $this->storage->writeFrom($version->disk, $path, $scratch);

        $size = (int) filesize($scratch);
        $sha256 = (string) hash_file('sha256', $scratch);

        if (! $this->complete($version, $path, $size, $sha256, $written)) {
            // Deleted, or given up on, while it was being written: nobody will ever ask for this file. Purged by its folder, not
            // through the row, which is gone with the inventory or the workspace that may be gone too.
            $this->storage->purgeAfterCommit($version->disk, [], [$directory]);

            return;
        }

        if ($version->signature_status === SignatureStatus::Requested) {
            $this->requestSignatures($version);
        }
    }

    /**
     * Queues the sending of the request for signatures of a version that has just been completed. A queue that cannot be reached is
     * written in the log and does not fail the version, which is fine as it is: the schedule gives up on a request nothing picked up
     * (`failed`/`stale`), and the person can send it again.
     */
    private function requestSignatures(InventoryVersion $version): void
    {
        try {
            RequestSignatures::dispatch($version);
        } catch (Throwable $exception) {
            Log::error('The job that sends the request for signatures of an official version could not be queued.', ['version' => $version->ulid, 'exception' => $exception]);
        }
    }

    /**
     * Records the PDF and counts the two files against the storage of the workspace, in one decision under the lock of the workspace.
     *
     * @param  array{pages: int, areas: list<array{page: int, x: float, y: float, width: float, height: float}>}  $written
     * @return bool Whether the version was completed: false when it is no longer running (deleted, or given up on).
     */
    private function complete(InventoryVersion $version, string $path, int $size, string $sha256, array $written): bool
    {
        $workspaceId = Inventory::query()->whereKey($version->inventory_id)->value('workspace_id');

        if ($workspaceId === null) {
            return false;
        }

        return DeadlockRetries::run(fn (): bool => $this->db->transaction(function () use ($version, $workspaceId, $path, $size, $sha256, $written): bool {
            $workspace = $this->locks->workspaces([$workspaceId])->first();

            if ($workspace === null) {
                return false;
            }

            $values = [
                'status' => VersionStatus::Completed->value,
                'error' => null,
                'pdf_path' => $path,
                'pdf_size' => $size,
                'pdf_sha256' => $sha256,
                'pdf_pages' => $written['pages'],
                'counted_bytes' => $version->snapshot_size + $size,
                'updated_at' => now(),
            ];

            if ($written['areas'] !== []) {
                $values['signers'] = json_encode($this->withAreas($version, $written['areas']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $completed = InventoryVersion::query()
                ->whereKey($version->getKey())
                ->where('status', VersionStatus::Running->value)
                ->update($values);

            if ($completed !== 1) {
                return false;
            }

            $this->usage->move($workspace, $version->snapshot_size + $size);

            return true;
        }));
    }

    /**
     * The signers of the version with the position of the signature area of each one.
     *
     * @param  list<array{page: int, x: float, y: float, width: float, height: float}>  $areas  In the order of the signers.
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException When there is not one area for each signer.
     */
    private function withAreas(InventoryVersion $version, array $areas): array
    {
        $signers = $version->signers ?? [];

        if (count($signers) !== count($areas)) {
            throw new RuntimeException('The signature areas of the PDF are not the signers of the version.');
        }

        foreach ($signers as $index => $signer) {
            $signer['field'] = $areas[$index];
            $signers[$index] = $signer;
        }

        return $signers;
    }

    /**
     * Has the folder of the version purged from the disk once the transaction in progress is committed (at once when there is none).
     */
    private function purge(InventoryVersion $version): void
    {
        $directory = $version->directory();

        if ($directory !== null) {
            $this->storage->purgeAfterCommit($version->disk, [], [$directory]);
        }
    }
}
