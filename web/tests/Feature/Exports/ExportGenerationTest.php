<?php

declare(strict_types=1);

use App\Inventories\Exports\ExportError;
use App\Inventories\Exports\ExportFormat;
use App\Inventories\Exports\ExportGenerator;
use App\Inventories\Exports\ExportItems;
use App\Inventories\Exports\ExportScope;
use App\Inventories\Exports\ExportStatus;
use App\Inventories\InventoryFields;
use App\Inventories\Items;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/exports.php';

/*
 * The writing of an export from the request to the end: claimed once, written in its language, with the items of its scope, stored
 * under its folder, and recorded as completed, or as failed with a code and nothing left on the disk.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * The files of the exports on the fake disk.
 *
 * @return list<string>
 */
function exportFiles(): array
{
    return array_values(array_filter(storedFiles(), fn (string $file): bool => str_contains($file, '/exports/')));
}

describe('a completed export', function (): void {
    it('has its file, its size, the number of items and the end of its life', function (): void {
        $case = exportFixture();
        config(['inventories.export_ttl_hours' => 24]);
        $this->travelTo(now()->startOfSecond());
        $export = exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv);

        $done = exportRun($export);

        expect($done->status)->toBe(ExportStatus::Completed)
            ->and($done->error)->toBeNull()
            ->and($done->items_count)->toBe(3)
            ->and($done->started_at?->equalTo(now()))->toBeTrue()
            ->and($done->completed_at?->equalTo(now()))->toBeTrue()
            ->and($done->expires_at?->equalTo(now()->addHours(24)))->toBeTrue()
            ->and($done->file_name)->toBe('Workshop - '.now()->setTimezone('Europe/Paris')->format('Y-m-d').'.csv')
            ->and($done->path)->toBe(exportFolder($done).'/'.$done->file_name)
            ->and($done->size)->toBe(strlen((string) file_get_contents(exportLocalPath($done))))
            ->and($done->isDownloadable())->toBeTrue()
            ->and(exportFiles())->toBe([$done->path]);
    });

    it('is downloadable for the hours the configuration says', function (): void {
        $case = exportFixture();
        config(['inventories.export_ttl_hours' => 3]);
        $this->travelTo(now()->startOfSecond());
        $done = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Json));

        expect($done->expires_at?->equalTo(now()->addHours(3)))->toBeTrue();
    });

    it('names the file after the inventory, safely, with the day in the timezone of the instance', function (): void {
        $case = exportFixture('Q1: Shop/Tools*');
        $this->travelTo(Carbon::parse('2026-12-31 23:30:00', 'UTC'));
        $done = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Json));

        // 23:30 UTC on the 31st is the 1st of January in Paris.
        expect($done->file_name)->toBe('Q1_ Shop_Tools_ - 2027-01-01.json');
    });

    it('is written in the language it was asked in, whatever the language of the worker', function (): void {
        $case = exportFixture();
        app()->setLocale('en');
        $french = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Xlsx, ['locale' => 'fr']));

        expect(exportSheet($french)['rows'][0])->toContain('Identifiant', 'Créé le', 'Modifié le')
            // The worker is where it was.
            ->and(app()->getLocale())->toBe('en');

        $english = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Xlsx, ['locale' => 'en']));

        expect(exportSheet($english)['rows'][0])->toContain('Identifier', 'Created on', 'Modified on');
    });

    it('is written once: a worker that finds it taken, or finished, does nothing', function (ExportStatus $status): void {
        $case = exportFixture();
        $export = exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv, ['status' => $status]);

        app(ExportGenerator::class)->run($export);

        expect($export->fresh()->status)->toBe($status)
            ->and($export->fresh()->path)->toBeNull()
            ->and(exportFiles())->toBe([]);
    })->with([ExportStatus::Running, ExportStatus::Completed, ExportStatus::Failed]);

    it('is not written a second time by a second run', function (): void {
        $case = exportFixture();
        $done = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv));
        $completed = $done->completed_at;
        $this->travel(5)->minutes();

        app(ExportGenerator::class)->run($done);

        expect($done->fresh()->completed_at?->equalTo($completed))->toBeTrue()
            ->and(exportFiles())->toBe([$done->path]);
    });

    it('takes every item of an inventory larger than a chunk, once, in order', function (): void {
        attachmentDisk();
        config(['app.display_timezone' => 'UTC']);
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Large');
        $field = fieldIn($inventory, 'number', 'Number');
        $rows = [];

        for ($i = 1; $i <= ExportItems::CHUNK * 2 + 37; $i++) {
            $rows[] = ['ulid' => strtolower((string) Str::ulid()), 'inventory_id' => $inventory->id, 'values' => json_encode([$field->ulid => (string) $i]), 'search_text' => '', 'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('items')->insert($chunk);
        }

        $done = exportRun(exportAsked($inventory, $team['owner'], ExportFormat::Csv));
        $numbers = array_map(intval(...), array_column(exportRecords($done), 'Number'));

        expect($done->items_count)->toBe(1037)
            // The newest first: the last inserted item is the first of the file.
            ->and($numbers)->toBe(range(1037, 1));
    });
});

describe('the items of a view', function (): void {
    /**
     * An inventory of a name, a quantity and a condition, with five items.
     *
     * @return array<string, mixed>
     */
    function viewCase(): array
    {
        attachmentDisk();
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Stock');
        $name = fieldIn($inventory, 'text', 'Name');
        $quantity = fieldIn($inventory, 'number', 'Quantity');
        $flag = fieldIn($inventory, 'boolean', 'Flag');

        foreach ([['Hammer', '5', true], ['Saw', '12', false], ['Drill', '7', true], ['Plane', '30', false], ['Chisel', '2', true]] as [$label, $count, $on]) {
            itemIn($inventory, [$name->ulid => $label, $quantity->ulid => $count, $flag->ulid => $on]);
        }

        return compact('team', 'inventory', 'name', 'quantity', 'flag');
    }

    /**
     * The names in the file of a view export.
     *
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    function viewNames(array $case, array $query, ?string $scope = null): array
    {
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv, [
            'scope' => $scope ?? ExportScope::View,
            'options' => ['query' => $query],
        ]));

        expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error);

        return array_column(exportRecords($export), 'Name');
    }

    it('holds the whole inventory for the scope all, whatever the view stored', function (): void {
        $case = viewCase();

        expect(viewNames($case, ['q' => 'saw'], ExportScope::All->value))->toBe(['Chisel', 'Plane', 'Drill', 'Saw', 'Hammer']);
    });

    it('holds the items that match the search', function (): void {
        $case = viewCase();

        expect(viewNames($case, ['q' => 'e']))->toBe(['Chisel', 'Plane', 'Hammer'])
            ->and(viewNames($case, ['q' => 'HAMMER']))->toBe(['Hammer'])
            ->and(viewNames($case, ['q' => 'nothing like it']))->toBe([]);
    });

    it('holds the items that match the filters, in the order of the sort', function (): void {
        $case = viewCase();
        $quantity = $case['quantity']->ulid;

        expect(viewNames($case, ['filter' => [$quantity => ['min' => '5', 'max' => '12']], 'sort' => $quantity, 'direction' => 'asc']))->toBe(['Hammer', 'Drill', 'Saw'])
            ->and(viewNames($case, ['sort' => $quantity, 'direction' => 'desc']))->toBe(['Plane', 'Saw', 'Drill', 'Hammer', 'Chisel'])
            // Filters arrive as the list gets them: the strings "true" and "false".
            ->and(viewNames($case, ['filter' => [$case['flag']->ulid => ['is' => 'true']], 'sort' => $case['name']->ulid]))->toBe(['Chisel', 'Drill', 'Hammer']);
    });

    it('holds the items of the search and the filters together', function (): void {
        $case = viewCase();

        expect(viewNames($case, ['q' => 'e', 'filter' => [$case['quantity']->ulid => ['min' => '3']]]))->toBe(['Plane', 'Hammer']);
    });

    it('is the newest first when the view does not sort', function (): void {
        $case = viewCase();

        expect(viewNames($case, []))->toBe(['Chisel', 'Plane', 'Drill', 'Saw', 'Hammer']);
    });

    it('fails with invalid_query when the view uses a field that was deleted since, and leaves nothing on the disk', function (): void {
        $case = viewCase();
        $query = ['filter' => [$case['quantity']->ulid => ['min' => '3']], 'sort' => $case['quantity']->ulid];
        $export = exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv, ['scope' => ExportScope::View, 'options' => ['query' => $query]]);
        app(InventoryFields::class)->delete($case['inventory'], $case['quantity']);

        $failed = exportRun($export);

        expect($failed->status)->toBe(ExportStatus::Failed)
            ->and($failed->error)->toBe(ExportError::InvalidQuery->value)
            ->and($failed->failure()?->message())->toContain('no longer valid')
            ->and($failed->path)->toBeNull()
            ->and($failed->expires_at)->not->toBeNull()
            ->and($failed->completed_at)->not->toBeNull()
            ->and(exportFiles())->toBe([]);
    });
});

describe('the list of items', function (): void {
    it('is made first: an item added or deleted while the file is written changes nothing', function (): void {
        $case = viewCase();
        $export = exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv);
        $context = app(ExportGenerator::class)->context($export);
        $before = $context->itemIds;

        $added = itemIn($case['inventory'], [$case['name']->ulid => 'Added late']);
        app(Items::class)->delete($case['inventory'], Item::query()->findOrFail($before[1]));

        $names = [];

        foreach ($context->items() as $item) {
            $names[] = $context->table->presented($item)[$case['name']->ulid];
        }

        expect($before)->toHaveCount(5)
            // Chisel, [Plane deleted], Drill, Saw, Hammer; the late item is not in it.
            ->and($names)->toBe(['Chisel', 'Drill', 'Saw', 'Hammer'])
            ->and($names)->not->toContain('Added late');
        unset($added);
    });
});

describe('a failed export', function (): void {
    it('is recorded as generation_failed, with the cause in the log and nothing left on the disk', function (): void {
        $case = exportFixture();
        $disk = Storage::disk(ATTACHMENT_TEST_DISK);
        attachmentDiskProxy()->shouldReceive('put')->once()->andReturnUsing(function (string $path) use ($disk): bool {
            $disk->put($path, 'the first bytes of it');

            return false;
        });
        Log::spy();
        $export = exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv);

        $failed = exportRun($export);

        expect($failed->status)->toBe(ExportStatus::Failed)
            ->and($failed->error)->toBe(ExportError::GenerationFailed->value)
            ->and($failed->failure()?->message())->toBe('The export could not be generated. Try again.')
            ->and($failed->path)->toBeNull()
            ->and($failed->size)->toBeNull()
            ->and($failed->expires_at)->not->toBeNull()
            ->and(exportFiles())->toBe([]);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'An export could not be generated.' && $context['export'] === $export->ulid)->once();
    });

    it('is kept like a finished one, so that it is seen, and deleted when it expires', function (): void {
        $case = exportFixture();
        config(['inventories.export_ttl_hours' => 2, 'inventories.pdf_max_items' => 1]);
        $this->travelTo(now()->startOfSecond());
        $failed = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Pdf));

        expect($failed->status)->toBe(ExportStatus::Failed)
            ->and($failed->expires_at?->equalTo(now()->addHours(2)))->toBeTrue()
            ->and($failed->isDownloadable())->toBeFalse();
    });

    it('is not resurrected by a worker that finishes after the person deleted it, and leaves no file', function (): void {
        $case = exportFixture();
        $disk = Storage::disk(ATTACHMENT_TEST_DISK);
        $export = exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv);
        attachmentDiskProxy()->shouldReceive('put')->once()->andReturnUsing(function (string $path, mixed $contents) use ($disk, $export): bool {
            $stored = $disk->put($path, $contents);
            // The person deletes the export while the file is being stored.
            Export::query()->whereKey($export->id)->delete();

            return $stored;
        });

        app(ExportGenerator::class)->run($export);

        expect(Export::query()->count())->toBe(0)
            ->and(exportFiles())->toBe([]);
    });

    it('is not completed by a worker that was too slow, once the export was given up on', function (): void {
        $case = exportFixture();
        $disk = Storage::disk(ATTACHMENT_TEST_DISK);
        $export = exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv);
        attachmentDiskProxy()->shouldReceive('put')->once()->andReturnUsing(function (string $path, mixed $contents) use ($disk, $export): bool {
            $stored = $disk->put($path, $contents);
            // `inventaire:prune-exports` gave up on it meanwhile.
            app(ExportGenerator::class)->fail($export->fresh(), ExportError::Stale);

            return $stored;
        });

        app(ExportGenerator::class)->run($export);

        expect($export->fresh()->status)->toBe(ExportStatus::Failed)
            ->and($export->fresh()->error)->toBe(ExportError::Stale->value)
            ->and($export->fresh()->path)->toBeNull()
            ->and(exportFiles())->toBe([]);
    });
});

describe('the job', function (): void {
    it('writes the export when it runs', function (): void {
        $case = exportFixture();
        $export = exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Json);

        GenerateExport::dispatch($export);

        expect($export->fresh()->status)->toBe(ExportStatus::Completed);
    });

    it('is tried once, may run for 15 minutes, and is dropped when its export is gone', function (): void {
        $job = new GenerateExport(Export::factory()->create(['disk' => 'local']));

        expect($job->tries)->toBe(1)
            ->and($job->timeout)->toBe(900)
            ->and($job->deleteWhenMissingModels)->toBeTrue()
            // The queue may hand a running job over after that: more than its timeout.
            ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout);
    });

    it('goes through the queue, carrying the export and nothing else', function (): void {
        $case = exportFixture();
        $export = exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Json);
        $restored = unserialize(serialize(new GenerateExport($export)));

        expect($restored->export->is($export))->toBeTrue();
    });

    it('records a failure the queue reports (a timeout) on a running export, and leaves a finished one alone', function (): void {
        $case = exportFixture();
        $running = exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Json, ['status' => ExportStatus::Running]);
        $finished = exportStored(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Json));

        (new GenerateExport($running))->failed(new RuntimeException('Timed out.'));
        (new GenerateExport($finished))->failed(new RuntimeException('Timed out.'));

        expect($running->fresh()->status)->toBe(ExportStatus::Failed)
            ->and($running->fresh()->error)->toBe(ExportError::GenerationFailed->value)
            ->and($finished->fresh()->status)->toBe(ExportStatus::Completed)
            ->and($finished->fresh()->error)->toBeNull();
    });
});
