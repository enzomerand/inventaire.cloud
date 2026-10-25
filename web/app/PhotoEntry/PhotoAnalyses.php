<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Inventories\Attachments\AttachmentStorage;
use App\Jobs\AnalyzePhotos;
use App\Models\Inventory;
use App\Models\PhotoAnalysis;
use App\Models\User;
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
 * metadata ({@see PhotoImages}, `422`), the analysis is counted ({@see PhotoAnalysisQuota}), the photos are written to the disk, under the
 * folder of the inventory, then the row is created `pending` and the job {@see AnalyzePhotos} queued once that is committed. The job reads
 * the photos back, asks Claude, stores the proposals and deletes the photos; the row is kept `inventories.photo_analysis_ttl_hours`.
 *
 * The photos are not counted in the storage of the workspace: they are gone minutes after they came.
 */
final class PhotoAnalyses
{
    /**
     * Gets what tells whether the feature is on and which fields a photo fills, the preparation of the photos, the daily count and the
     * storage the photos are written to.
     */
    public function __construct(
        private readonly PhotoEntry $photoEntry,
        private readonly PhotoFields $fields,
        private readonly PhotoImages $images,
        private readonly PhotoAnalysisQuota $quota,
        private readonly AttachmentStorage $storage,
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
        $paths = [];

        try {
            foreach ($prepared as $index => $bytes) {
                $path = $directory.'/'.($index + 1).'.jpg';
                $paths[] = $path;
                $this->storage->write($disk, $path, $bytes);
            }

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
        } catch (Throwable $failure) {
            // Nothing refers to the photos that were written: they go now.
            $this->storage->forgetNow($disk, $paths);

            throw $failure;
        }

        AnalyzePhotos::dispatch($analysis)->afterCommit();

        return $analysis;
    }
}
