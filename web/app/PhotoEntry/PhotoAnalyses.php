<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Inventories\Attachments\AttachmentStorage;
use App\Jobs\AnalyzePhotos;
use App\Models\Inventory;
use App\Models\PhotoAnalysis;
use App\Models\User;
use App\PhotoEntry\Exceptions\AnalysisNotStartedException;
use App\PhotoEntry\Exceptions\InstanceQuotaReachedException;
use App\PhotoEntry\Exceptions\NoFieldsToFillException;
use App\PhotoEntry\Exceptions\PhotoEntryDisabledException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

/**
 * Receives the photos of an object and starts their analysis.
 *
 * In this order, so that nothing is spent on a request that cannot be served: the workspace must have the photo entry on and the
 * inventory a field to fill (`409`), the person and the instance must have analyses left today (`429`), every photo is decoded and drawn again without any
 * metadata ({@see PhotoImages}, `422`), the analysis is counted ({@see PhotoAnalysisQuota}), the row is created `pending`, the photos are
 * written to the disk, under the folder of the inventory, and the job {@see AnalyzePhotos} queued. The row comes first so that no folder
 * is ever left that nothing refers to; if the photos cannot be written or the job queued, the row ends `failed`, the photos are deleted,
 * the cause is reported and the API answers `503` ({@see AnalysisNotStartedException}). The job reads the photos back, asks Claude,
 * stores the proposals and deletes the photos; the row is kept `inventories.photo_analysis_ttl_hours`.
 *
 * The photos are not counted in the storage of the workspace: they are gone minutes after they came.
 */
final class PhotoAnalyses
{
    /**
     * Gets what tells whether the feature is on and which fields a photo fills, the preparation of the photos, the daily count, the
     * storage the photos are written to and the runner that ends an analysis that cannot start.
     */
    public function __construct(
        private readonly PhotoEntry $photoEntry,
        private readonly PhotoFields $fields,
        private readonly PhotoImages $images,
        private readonly PhotoAnalysisQuota $quota,
        private readonly AttachmentStorage $storage,
        private readonly PhotoAnalysisRunner $runner,
    ) {}

    /**
     * Starts the analysis of some photos of one object for an inventory.
     *
     * @param  list<UploadedFile>  $photos  The photos, one to the most the configuration allows (the request checked their number and size).
     * @param  string  $locale  The language of the person, the one the evidence is written in.
     *
     * @throws PhotoEntryDisabledException When the workspace of the inventory may not use the photo entry.
     * @throws NoFieldsToFillException When no field of the inventory can be filled from a photo.
     * @throws ThrottleRequestsException When the person has asked for all the analyses they may today.
     * @throws InstanceQuotaReachedException When the instance has run all the analyses it may today.
     * @throws UnreadablePhotoException When a photo is not an image that can be read; it says which one.
     * @throws AnalysisNotStartedException When the photos could not be written or the job queued; nothing is left behind.
     */
    public function request(Inventory $inventory, User $user, array $photos, string $locale): PhotoAnalysis
    {
        $workspace = $inventory->workspace()->firstOrFail();

        if (! $this->photoEntry->enabledFor($workspace)) {
            throw new PhotoEntryDisabledException;
        }

        if ($this->fields->eligible($inventory->fields()->get()) === []) {
            throw new NoFieldsToFillException;
        }

        $this->quota->ensureAvailable($user);

        $prepared = [];

        foreach ($photos as $index => $photo) {
            try {
                $prepared[] = $this->images->prepare($photo->getRealPath() ?: $photo->getPathname());
            } catch (UnreadablePhotoException $refused) {
                throw $refused->at($index);
            }
        }

        $this->quota->reserve($user);

        $disk = $this->storage->diskName();
        $ulid = strtolower((string) Str::ulid());
        $directory = AttachmentStorage::analysisDirectory($workspace->ulid, $inventory->ulid, $ulid);

        // The row comes first, so that no folder is ever written that no row refers to: whatever happens to the photos from here on, the
        // row knows where they are, and the person who deletes the inventory, the account, or the pruner finds them.
        $analysis = new PhotoAnalysis;
        $analysis->forceFill([
            'ulid' => $ulid,
            'inventory_id' => $inventory->getKey(),
            'user_id' => $user->getKey(),
            'status' => PhotoAnalysisStatus::Pending,
            'disk' => $disk,
            'locale' => $locale,
            'photo_count' => count($prepared),
            'expires_at' => now()->addHours(config()->integer('inventories.photo_analysis_ttl_hours')),
        ])->save();

        try {
            foreach ($prepared as $index => $bytes) {
                $this->storage->write($disk, $directory.'/'.($index + 1).'.jpg', $bytes);
            }

            AnalyzePhotos::dispatch($analysis)->afterCommit();
        } catch (Throwable $failure) {
            // Nothing will ever run this analysis: it ends failed, the photos that were written go now, and the administrator is told why.
            report($failure);
            $this->runner->fail($analysis, PhotoAnalysisError::Failed);

            throw new AnalysisNotStartedException;
        }

        return $analysis;
    }
}
