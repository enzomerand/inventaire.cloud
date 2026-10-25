<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Inventories\Attachments\AttachmentStorage;
use App\Jobs\AnalyzePhotos;
use App\Models\PhotoAnalysis;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Does the photo analyses, and ends the ones that cannot be done.
 *
 * {@see self::run()} is the work of the job ({@see AnalyzePhotos}): the analysis goes from `pending` to `running`, the photos
 * are read back from the disk and given to the {@see PhotoAnalyzer}, what it read goes through {@see Proposals} (converted and checked
 * by the rules of the fields), and the analysis is `completed` with the proposals and what the request cost, or `failed` with the code of
 * the reason ({@see PhotoAnalysisError}). **The photos are deleted whatever happens**: the person's photos stay on the disk no longer than
 * the analysis needs them. {@see self::fail()} is where a failure is recorded when something else gives up on the analysis (the queue
 * killed the job, the pruner found its worker lost).
 *
 * Every change of state is one conditional update that wants the analysis to be where it was left (the worker's: `pending` to `running`,
 * then `running` to `completed` or `failed`; the queue's: `pending` or `running` to `failed`), so that a late worker, a pruner and a
 * person who deleted the analysis never undo each other's work. When the worker finds that the analysis is not where it left it when it
 * has to end it, it logs what became of the analysis, not what it would have made of it.
 *
 * One line is logged for each analysis: its ULID, the model, the tokens and how it ended. Never the photos, the proposals or the key.
 */
final class PhotoAnalysisRunner
{
    /**
     * Gets the analyzer, the code that judges what it read, the one that tells which fields to fill, the cleanup that deletes the photos,
     * the storage they are read from, the code that tells whether the photo entry is still on and the daily count the analyses are
     * counted in.
     */
    public function __construct(
        private readonly PhotoAnalyzer $analyzer,
        private readonly Proposals $proposals,
        private readonly PhotoFields $fields,
        private readonly PhotoAnalysisCleanup $cleanup,
        private readonly AttachmentStorage $storage,
        private readonly PhotoEntry $photoEntry,
        private readonly PhotoAnalysisQuota $quota,
    ) {}

    /**
     * Does the analysis. One that is not waiting any more (it was given up on, deleted, or another worker took it) is left alone. One
     * whose workspace may not use the photo entry any more (the owner withdrew consent, the administrator turned it off or removed the key
     * while it waited in the queue) is failed `disabled` before anything is read or sent: consent is judged when the photos leave, not only
     * when they came, and since nothing was sent to Anthropic the places counted for it go back to the person and the instance.
     */
    public function run(PhotoAnalysis $analysis): void
    {
        $claimed = PhotoAnalysis::query()
            ->whereKey($analysis->getKey())
            ->where('status', PhotoAnalysisStatus::Pending->value)
            ->update(['status' => PhotoAnalysisStatus::Running->value, 'started_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $reading = null;
        $count = 0;
        $error = null;
        $completed = false;

        try {
            [$reading, $proposals] = $this->read($analysis);
            $count = count($proposals);

            $completed = $this->complete($analysis, $reading, $proposals);
        } catch (PhotoAnalysisException $failure) {
            $error = $failure->error;
        } catch (Throwable $exception) {
            // Not a failure of the analysis but of the application (the database, the disk): the administrator needs to know.
            report($exception);
            $error = PhotoAnalysisError::Failed;
        } finally {
            $this->deletePhotos($analysis);
        }

        // The analysis is `running` since this worker took it: it is ended only if it still is. When it is not, somebody else ended it while
        // Claude was being asked (the pruner gave up on it, or the person deleted it), and that is what the log says became of it.
        $ended = $error === null ? $completed : $this->markFailed($analysis, $error, [PhotoAnalysisStatus::Running]);
        $status = $error === null ? PhotoAnalysisStatus::Completed->value : PhotoAnalysisStatus::Failed->value;
        $code = $error?->value;

        if (! $ended) {
            $current = PhotoAnalysis::query()->whereKey($analysis->getKey())->first();
            $status = $current === null ? 'deleted' : $current->status->value;
            $code = $current?->error;
            $count = 0;
        }

        Log::info('Photo analysis finished.', [
            'analysis' => $analysis->ulid,
            'status' => $status,
            'error' => $code,
            'model' => $reading?->model,
            'input_tokens' => $reading?->inputTokens,
            'output_tokens' => $reading?->outputTokens,
            'photos' => $analysis->photo_count,
            'proposals' => $count,
        ]);

        // Consent withdrawn while the photos waited: nothing left the instance, so the analysis costs nothing. Only the worker that ended the
        // analysis gives the places back, once.
        if ($ended && $error === PhotoAnalysisError::Disabled) {
            $this->quota->release($analysis->user_id);
        }
    }

    /**
     * Marks an analysis that is not over `failed` with the reason, and deletes its photos. One that is over already (completed, or
     * failed before) is left as it is: the update wants the analysis to be waiting or running, so a failure reported late never undoes
     * the proposals of an analysis that did end.
     *
     * @return bool Whether it was ended here: false when it was over already.
     */
    public function fail(PhotoAnalysis $analysis, PhotoAnalysisError $error): bool
    {
        try {
            return $this->markFailed($analysis, $error, [PhotoAnalysisStatus::Pending, PhotoAnalysisStatus::Running]);
        } finally {
            $this->deletePhotos($analysis);
        }
    }

    /**
     * Reads the photos back and has them analysed.
     *
     * @return array{0: PhotoReading, 1: list<array{field: string, value: mixed, confidence: string, evidence: string}>}
     *
     * @throws PhotoAnalysisException When the photo entry was turned off, the photos are not there any more, no field is left to fill, or the analyzer failed.
     */
    private function read(PhotoAnalysis $analysis): array
    {
        $inventory = $analysis->inventory()->firstOrFail();

        if (! $this->photoEntry->enabledFor($inventory->workspace()->firstOrFail())) {
            throw new PhotoAnalysisException(PhotoAnalysisError::Disabled, 'The photo entry was turned off while the analysis waited.');
        }

        $fields = $inventory->fields()->get();
        $eligible = $this->fields->eligible($fields);

        if ($eligible === []) {
            throw new PhotoAnalysisException(PhotoAnalysisError::Failed, 'The inventory has no field left to fill.');
        }

        $directory = $this->cleanup->directory($analysis);
        $disk = $this->storage->disk($analysis->disk);
        $photos = [];

        for ($number = 1; $number <= $analysis->photo_count; $number++) {
            $bytes = $directory === null ? null : $disk->get("{$directory}/{$number}.jpg");

            if (! is_string($bytes) || $bytes === '') {
                throw new PhotoAnalysisException(PhotoAnalysisError::Failed, 'A photo is missing.');
            }

            $photos[] = $bytes;
        }

        $reading = $this->analyzer->analyze($photos, $eligible, $analysis->locale);

        return [$reading, $this->proposals->normalize($fields, $reading->proposals)];
    }

    /**
     * Ends the analysis `completed`, if it is still running.
     *
     * @param  list<array{field: string, value: mixed, confidence: string, evidence: string}>  $proposals
     * @return bool Whether it was: false when somebody else ended or deleted the analysis meanwhile.
     */
    private function complete(PhotoAnalysis $analysis, PhotoReading $reading, array $proposals): bool
    {
        return PhotoAnalysis::query()
            ->whereKey($analysis->getKey())
            ->where('status', PhotoAnalysisStatus::Running->value)
            ->update([
                'status' => PhotoAnalysisStatus::Completed->value,
                'model' => $reading->model === null ? null : mb_substr($reading->model, 0, 64),
                'input_tokens' => $reading->inputTokens,
                'output_tokens' => $reading->outputTokens,
                'proposals' => json_encode($proposals, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'completed_at' => now(),
            ]) === 1;
    }

    /**
     * Ends the analysis `failed`, if it is in one of the states it is expected to be in.
     *
     * @param  list<PhotoAnalysisStatus>  $from  The states the analysis may be in for this failure to apply.
     * @return bool Whether it was.
     */
    private function markFailed(PhotoAnalysis $analysis, PhotoAnalysisError $error, array $from): bool
    {
        return PhotoAnalysis::query()
            ->whereKey($analysis->getKey())
            ->whereIn('status', array_map(fn (PhotoAnalysisStatus $status): string => $status->value, $from))
            ->update([
                'status' => PhotoAnalysisStatus::Failed->value,
                'error' => $error->value,
                'completed_at' => now(),
            ]) === 1;
    }

    /**
     * Deletes the photos of the analysis. What goes wrong here (the database that says where they are, the disk) is reported but never
     * thrown: it must not hide the failure of the analysis that led here, nor stop it from being recorded.
     */
    private function deletePhotos(PhotoAnalysis $analysis): void
    {
        try {
            $this->cleanup->deleteNow($analysis);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
