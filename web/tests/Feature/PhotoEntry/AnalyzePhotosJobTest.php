<?php

declare(strict_types=1);

use App\Jobs\AnalyzePhotos;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\PhotoAnalysis;
use App\Models\Workspace;
use App\PhotoEntry\PhotoAnalysisError;
use App\PhotoEntry\PhotoAnalysisException;
use App\PhotoEntry\PhotoAnalysisRunner;
use App\PhotoEntry\PhotoAnalysisStatus;
use App\PhotoEntry\PhotoReading;
use App\Settings\InstanceSettings;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/photo-entry.php';

/*
 * The job that does a photo analysis: the photos are read back and given to the analyzer, what it read is converted and checked by the
 * fields, and the analysis ends `completed` or `failed` with the code of the reason. The photos are deleted in every case, and one line is
 * logged for each analysis (never the photos, never the proposals).
 */

uses(RefreshDatabase::class);

/**
 * An inventory with fields, and an analysis of it waiting for the job, with two photos on the disk.
 *
 * @return array{team: array<string, mixed>, inventory: Inventory, name: InventoryField, weight: InventoryField, condition: InventoryField, analysis: PhotoAnalysis}
 */
function analysisCase(array $attributes = []): array
{
    $case = photoCase();
    photoTurnedOn($case['team']['workspace']);
    $name = fieldIn($case['inventory'], 'text', 'Name');
    $weight = fieldIn($case['inventory'], 'number', 'Weight', ['decimals' => 1]);
    $condition = fieldIn($case['inventory'], 'select', 'Condition', ['choices' => [['label' => 'New'], ['label' => 'Used']]]);
    fieldIn($case['inventory'], 'photo', 'Pictures');

    return $case + [
        'name' => $name,
        'weight' => $weight,
        'condition' => $condition,
        'analysis' => photoAnalysisWithFiles($case['inventory'], $case['team']['editor'], ['locale' => 'fr'] + $attributes),
    ];
}

/**
 * What an analyzer read: these raw proposals.
 *
 * @param  list<array<string, mixed>>  $proposals
 */
function analysisReading(array $proposals, ?string $model = 'claude-sonnet-5-5'): PhotoReading
{
    return new PhotoReading($proposals, $model, 1200, 85);
}

/**
 * Does the analysis the way the queue does.
 */
function analysisRun(PhotoAnalysis $analysis): PhotoAnalysis
{
    (new AnalyzePhotos($analysis))->handle(app(PhotoAnalysisRunner::class));

    return $analysis->fresh();
}

describe('an analysis that works', function (): void {
    it('is completed with the proposals converted to what the fields store, the model and the tokens', function (): void {
        $case = analysisCase();
        $calls = [];
        $ids = collect($case['condition']->options['choices'])->pluck('id', 'label');
        photoAnalyzerSaying(analysisReading([
            ['field' => $case['condition']->ulid, 'values' => ['used'], 'confidence' => 'medium', 'evidence' => 'Sticker'],
            ['field' => $case['name']->ulid, 'values' => ['  Drill  '], 'confidence' => 'high', 'evidence' => "On the\ncase"],
            ['field' => $case['weight']->ulid, 'values' => ['2,5 kg'], 'confidence' => 'low', 'evidence' => ''],
        ], 'claude-opus-5-5'), $calls);

        $analysis = analysisRun($case['analysis']);

        expect($analysis->status)->toBe(PhotoAnalysisStatus::Completed)
            ->and($analysis->error)->toBeNull()
            // MySQL reorders the keys of a JSON object: compared as equal, not identical.
            ->and($analysis->proposals)->toEqual([
                ['field' => $case['name']->ulid, 'value' => 'Drill', 'confidence' => 'high', 'evidence' => 'On the case'],
                ['field' => $case['weight']->ulid, 'value' => '2.5', 'confidence' => 'low', 'evidence' => ''],
                ['field' => $case['condition']->ulid, 'value' => $ids['Used'], 'confidence' => 'medium', 'evidence' => 'Sticker'],
            ])
            ->and($analysis->model)->toBe('claude-opus-5-5')
            ->and($analysis->input_tokens)->toBe(1200)
            ->and($analysis->output_tokens)->toBe(85)
            ->and($analysis->started_at)->not->toBeNull()
            ->and($analysis->completed_at)->not->toBeNull();
    });

    it('gives the analyzer the photos in order, the fields a photo can fill, and the language of the person', function (): void {
        $case = analysisCase();
        $calls = [];
        photoAnalyzerSaying(analysisReading([]), $calls);

        analysisRun($case['analysis']);

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['photos'])->toBe(['jpeg-1', 'jpeg-2'])
            ->and($calls[0]['fields'])->toBe([$case['name']->ulid, $case['weight']->ulid, $case['condition']->ulid])
            ->and($calls[0]['locale'])->toBe('fr');
    });

    it('drops what the fields refuse, and is completed with none when nothing holds', function (): void {
        $case = analysisCase();
        photoAnalyzerSaying(analysisReading([
            ['field' => $case['weight']->ulid, 'values' => ['heavy'], 'confidence' => 'high', 'evidence' => 'Plate'],
            ['field' => $case['condition']->ulid, 'values' => ['Broken'], 'confidence' => 'high', 'evidence' => 'Look'],
            ['field' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'values' => ['ghost'], 'confidence' => 'high', 'evidence' => ''],
        ]));

        $analysis = analysisRun($case['analysis']);

        expect($analysis->status)->toBe(PhotoAnalysisStatus::Completed)->and($analysis->proposals)->toBe([]);
    });

    it('stores proposals that survive every engine: a boolean false, a list, a number as a text, accents', function (): void {
        $case = analysisCase();
        $working = fieldIn($case['inventory'], 'boolean', 'Working');
        $tags = fieldIn($case['inventory'], 'tags', 'Tags');
        photoAnalyzerSaying(analysisReading([
            ['field' => $working->ulid, 'values' => ['non'], 'confidence' => 'high', 'evidence' => 'Étiquette « HS »'],
            ['field' => $tags->ulid, 'values' => ['rouge', 'métal'], 'confidence' => 'medium', 'evidence' => ''],
        ]));

        $analysis = analysisRun($case['analysis']);

        // MySQL reorders the keys of a JSON object: compared as equal, not identical.
        expect($analysis->proposals)->toEqual([
            ['field' => $working->ulid, 'value' => false, 'confidence' => 'high', 'evidence' => 'Étiquette « HS »'],
            ['field' => $tags->ulid, 'value' => ['rouge', 'métal'], 'confidence' => 'medium', 'evidence' => ''],
        ]);
    });

    it('deletes the photos', function (): void {
        $case = analysisCase();
        photoAnalyzerSaying(analysisReading([]));

        analysisRun($case['analysis']);

        expect(storedFiles())->toBe([])
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->exists(photoFolder($case['analysis'])))->toBeFalse();
    });

    it('leaves the photos of other analyses alone', function (): void {
        $case = analysisCase();
        $other = photoAnalysisWithFiles($case['inventory'], $case['team']['editor']);
        photoAnalyzerSaying(analysisReading([]));

        analysisRun($case['analysis']);

        expect(storedFiles())->toBe([photoFolder($other).'/1.jpg', photoFolder($other).'/2.jpg']);
    });
});

describe('an analysis that fails', function (): void {
    it('is failed with the code the analyzer gave, and the photos are deleted', function (PhotoAnalysisError $error): void {
        $case = analysisCase();
        photoAnalyzerSaying(new PhotoAnalysisException($error));

        $analysis = analysisRun($case['analysis']);

        expect($analysis->status)->toBe(PhotoAnalysisStatus::Failed)
            ->and($analysis->error)->toBe($error->value)
            ->and($analysis->failure())->toBe($error)
            ->and($analysis->proposals)->toBeNull()
            ->and($analysis->model)->toBeNull()
            ->and($analysis->completed_at)->not->toBeNull()
            ->and(storedFiles())->toBe([]);
    })->with(array_map(fn (PhotoAnalysisError $error): array => [$error], PhotoAnalysisError::cases()));

    it('is failed with "failed" for anything the analyzer did not foresee, and the exception is reported', function (): void {
        $case = analysisCase();
        photoAnalyzerSaying(new RuntimeException('Something broke'));
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $exception) use (&$reported): bool {
            $reported[] = $exception->getMessage();

            return false;
        });

        $analysis = analysisRun($case['analysis']);

        expect($analysis->status)->toBe(PhotoAnalysisStatus::Failed)
            ->and($analysis->error)->toBe('failed')
            ->and($reported)->toBe(['Something broke'])
            ->and(storedFiles())->toBe([]);
    });

    it('is failed when a photo is not on the disk any more', function (): void {
        $case = analysisCase();
        $calls = [];
        photoAnalyzerSaying(analysisReading([]), $calls);
        Storage::disk(ATTACHMENT_TEST_DISK)->delete(photoFolder($case['analysis']).'/2.jpg');

        $analysis = analysisRun($case['analysis']);

        expect($analysis->status)->toBe(PhotoAnalysisStatus::Failed)->and($analysis->error)->toBe('failed')->and($calls)->toBe([])->and(storedFiles())->toBe([]);
    });

    it('is failed when the inventory has no field left to fill, without asking the analyzer', function (): void {
        $case = analysisCase();
        $calls = [];
        photoAnalyzerSaying(analysisReading([]), $calls);
        foreach ([$case['name'], $case['weight'], $case['condition']] as $field) {
            $field->delete();
        }

        $analysis = analysisRun($case['analysis']);

        expect($analysis->status)->toBe(PhotoAnalysisStatus::Failed)->and($analysis->error)->toBe('failed')->and($calls)->toBe([])->and(storedFiles())->toBe([]);
    });

    it('is failed, and its photos deleted, when the queue gives up on the job', function (): void {
        $case = analysisCase();

        (new AnalyzePhotos($case['analysis']))->failed(new RuntimeException('Timed out'));
        $analysis = $case['analysis']->fresh();

        expect($analysis->status)->toBe(PhotoAnalysisStatus::Failed)->and($analysis->error)->toBe('failed')->and(storedFiles())->toBe([]);
    });

    it('does not undo an analysis that completed when the queue gives up on its job', function (): void {
        $case = analysisCase(['status' => PhotoAnalysisStatus::Completed, 'proposals' => []]);

        (new AnalyzePhotos($case['analysis']))->failed(new RuntimeException('Timed out'));

        expect($case['analysis']->fresh()->status)->toBe(PhotoAnalysisStatus::Completed)->and($case['analysis']->fresh()->error)->toBeNull();
    });
});

describe('an analysis whose workspace may not use the photo entry any more', function (): void {
    it('is failed "disabled" without any call, and the photos are deleted, whoever switched it off', function (Closure $switchOff): void {
        $case = analysisCase();
        $calls = [];
        photoAnalyzerSaying(analysisReading([]), $calls);
        $switchOff($case['team']['workspace']);

        $analysis = analysisRun($case['analysis']);

        expect($analysis->status)->toBe(PhotoAnalysisStatus::Failed)
            ->and($analysis->error)->toBe('disabled')
            ->and($analysis->failure())->toBe(PhotoAnalysisError::Disabled)
            ->and($analysis->proposals)->toBeNull()
            ->and($analysis->model)->toBeNull()
            ->and($calls)->toBe([])
            ->and(storedFiles())->toBe([]);
    })->with([
        'the owner withdrew consent' => [fn (Workspace $workspace) => $workspace->forceFill(['photo_entry_enabled' => false])->save()],
        'the administrator switched it off' => [fn () => app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => false])],
        'the key was removed' => [fn () => config(['services.anthropic.key' => null])],
    ]);

    it('tells the person in their language', function (): void {
        app()->setLocale('fr');

        expect(PhotoAnalysisError::Disabled->message())->toContain('désactivée');
    });
});

describe('an analysis that is not waiting', function (): void {
    it('is left alone: the analyzer is not asked, nothing changes', function (PhotoAnalysisStatus $status): void {
        $case = analysisCase(['status' => $status, 'error' => $status === PhotoAnalysisStatus::Failed ? 'stale' : null]);
        $calls = [];
        photoAnalyzerSaying(analysisReading([]), $calls);

        $analysis = analysisRun($case['analysis']);

        expect($calls)->toBe([])
            ->and($analysis->status)->toBe($status)
            ->and($analysis->started_at)->toBeNull()
            ->and($analysis->proposals)->toBeNull();
    })->with([[PhotoAnalysisStatus::Running], [PhotoAnalysisStatus::Completed], [PhotoAnalysisStatus::Failed]]);

    it('is not taken by two workers: the second finds it running', function (): void {
        $case = analysisCase();
        $calls = [];
        photoAnalyzerSaying(analysisReading([]), $calls);
        $stale = PhotoAnalysis::query()->findOrFail($case['analysis']->id);

        analysisRun($case['analysis']);
        (new AnalyzePhotos($stale))->handle(app(PhotoAnalysisRunner::class));

        expect($calls)->toHaveCount(1);
    });
});

describe('the log', function (): void {
    it('has one line for each analysis: its ULID, how it ended, the model and the tokens, and nothing of the photos or the proposals', function (): void {
        Log::spy();
        $case = analysisCase();
        photoAnalyzerSaying(analysisReading([['field' => $case['name']->ulid, 'values' => ['Secret drill'], 'confidence' => 'high', 'evidence' => 'Secret evidence']]));

        analysisRun($case['analysis']);

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context) use ($case): bool {
            $text = json_encode($context);

            return $message === 'Photo analysis finished.'
                && $context === [
                    'analysis' => $case['analysis']->ulid,
                    'status' => 'completed',
                    'error' => null,
                    'model' => 'claude-sonnet-5-5',
                    'input_tokens' => 1200,
                    'output_tokens' => 85,
                    'photos' => 2,
                    'proposals' => 1,
                ]
                && ! str_contains($text, 'Secret')
                && ! str_contains($text, 'jpeg-');
        });
    });

    it('tells the reason of a failure', function (): void {
        Log::spy();
        $case = analysisCase();
        photoAnalyzerSaying(new PhotoAnalysisException(PhotoAnalysisError::Refused));

        analysisRun($case['analysis']);

        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $context['status'] === 'failed'
            && $context['error'] === 'refused'
            && $context['model'] === null
            && $context['input_tokens'] === null);
    });
});

describe('the job', function (): void {
    it('is tried once, within five minutes, on the default queue, and dropped when its analysis is gone', function (): void {
        $case = analysisCase();
        $job = new AnalyzePhotos($case['analysis']);

        expect($job->tries)->toBe(1)
            ->and($job->timeout)->toBe(300)
            ->and($job->queue)->toBeNull()
            ->and($job->deleteWhenMissingModels)->toBeTrue()
            ->and($job)->toBeInstanceOf(ShouldQueue::class);
    });

    it('can be serialized and read again from the queue', function (): void {
        $case = analysisCase();
        $calls = [];
        photoAnalyzerSaying(analysisReading([]), $calls);

        $job = unserialize(serialize(new AnalyzePhotos($case['analysis'])));
        $job->handle(app(PhotoAnalysisRunner::class));

        expect($case['analysis']->fresh()->status)->toBe(PhotoAnalysisStatus::Completed)->and($calls)->toHaveCount(1);
    });
});
