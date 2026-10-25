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
 * Every change of state is one conditional update that wants the analysis to be where it was left, so that a late worker, a pruner and a
 * person who deleted the analysis never undo each other's work.
 *
 * One line is logged for each analysis: its ULID, the model, the tokens and how it ended. Never the photos, the proposals or the key.
 */
final class PhotoAnalysisRunner
{
    /**
     * Gets the analyzer, the code that judges what it read, the one that tells which fields to fill, the cleanup that deletes the photos,
     * the storage they are read from and the code that tells whether the photo entry is still on.
     */
    public function __construct(
        private readonly PhotoAnalyzer $analyzer,
        private readonly Proposals $proposals,
        private readonly PhotoFields $fields,
        private readonly PhotoAnalysisCleanup $cleanup,
        private readonly AttachmentStorage $storage,
        private readonly PhotoEntry $photoEntry,
    ) {}

    /**
     * Does the analysis. One that is not waiting any more (it was given up on, deleted, or another worker took it) is left alone. One
     * whose workspace may not use the photo entry any more (the owner withdrew consent, the administrator turned it off or removed the key
     * while it waited in the queue) is failed `disabled` before anything is read or sent: consent is judged when the photos leave, not only
     * when they came.
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

        try {
            [$reading, $proposals] = $this->read($analysis);
            $count = count($proposals);

            $this->complete($analysis, $reading, $proposals);
        } catch (PhotoAnalysisException $failure) {
            $error = $failure->error;
        } catch (Throwable $exception) {
            // Not a failure of the analysis but of the application (the database, the disk): the administrator needs to know.
            report($exception);
            $error = PhotoAnalysisError::Failed;
        } finally {
            $this->cleanup->deleteNow($analysis);
        }

        if ($error !== null) {
            $this->markFailed($analysis, $error);
        }

        Log::info('Photo analysis finished.', [
            'analysis' => $analysis->ulid,
            'status' => $error === null ? PhotoAnalysisStatus::Completed->value : PhotoAnalysisStatus::Failed->value,
            'error' => $error?->value,
            'model' => $reading?->model,
            'input_tokens' => $reading?->inputTokens,
            'output_tokens' => $reading?->outputTokens,
            'photos' => $analysis->photo_count,
            'proposals' => $count,
        ]);
    }

    /**
     * Marks an analysis that is not over `failed` with the reason, and deletes its photos. One that is over already (completed, or
     * failed before) is left as it is: the update wants the analysis to be waiting or running, so a failure reported late never undoes
     * the proposals of an analysis that did end.
     */
    public function fail(PhotoAnalysis $analysis, PhotoAnalysisError $error): void
    {
        $this->markFailed($analysis, $error);

        $this->cleanup->deleteNow($analysis);
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
     */
    private function complete(PhotoAnalysis $analysis, PhotoReading $reading, array $proposals): void
    {
        PhotoAnalysis::query()
            ->whereKey($analysis->getKey())
            ->where('status', PhotoAnalysisStatus::Running->value)
            ->update([
                'status' => PhotoAnalysisStatus::Completed->value,
                'error' => null,
                'model' => $reading->model === null ? null : mb_substr($reading->model, 0, 64),
                'input_tokens' => $reading->inputTokens,
                'output_tokens' => $reading->outputTokens,
                'proposals' => json_encode($proposals, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'completed_at' => now(),
            ]);
    }

    /**
     * Ends the analysis `failed`, if it is still waiting or running.
     */
    private function markFailed(PhotoAnalysis $analysis, PhotoAnalysisError $error): void
    {
        PhotoAnalysis::query()
            ->whereKey($analysis->getKey())
            ->whereIn('status', [PhotoAnalysisStatus::Pending->value, PhotoAnalysisStatus::Running->value])
            ->update([
                'status' => PhotoAnalysisStatus::Failed->value,
                'error' => $error->value,
                'completed_at' => now(),
            ]);
    }
}
