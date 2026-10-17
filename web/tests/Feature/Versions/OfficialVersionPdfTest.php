<?php

declare(strict_types=1);

use App\Inventories\Versions\OfficialPdfWriter;
use App\Inventories\Versions\SignatureStatus;
use App\Inventories\Versions\SnapshotReader;
use App\Inventories\Versions\VersionError;
use App\Inventories\Versions\VersionGenerator;
use App\Inventories\Versions\VersionStatus;
use App\Jobs\GenerateOfficialVersion;
use App\Jobs\PurgeStoredFiles;
use App\Models\InventoryVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/versions.php';

/*
 * The PDF of an official version: written by a job from the snapshot, never from the live data, with a header that says what it proves,
 * a footer, the items laid out as an export lays them out, and a last page of signatures whose areas are measured on the real layout.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    app()->setLocale('en');
});

/**
 * The HTML the PDF library lays out for a version, written from its stored snapshot as the job does.
 */
function versionHtml(InventoryVersion $version): string
{
    $content = app(SnapshotReader::class)->read(versionFile($version, $version->snapshot_path), $version->snapshot_size, $version->snapshot_sha256);
    $previous = app()->getLocale();
    app()->setLocale($version->locale);

    try {
        // The spaces ICU puts in numbers and dates are not breaking: one kind is enough to compare.
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', app(OfficialPdfWriter::class)->html($version, $content));
    } finally {
        app()->setLocale($previous);
    }
}

describe('the job', function (): void {
    it('is the one the freezing queues: on the queue of the exports, tried once, a missing version is not an error', function (): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        $job = new GenerateOfficialVersion($version);

        expect($job->queue)->toBe('exports')
            ->and($job->tries)->toBe(1)
            ->and($job->timeout)->toBe(900)
            ->and($job->deleteWhenMissingModels)->toBeTrue();
    });

    it('writes the PDF, and records its size, hash and pages', function (): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner'], 'Year end');
        (new GenerateOfficialVersion($version))->handle(app(VersionGenerator::class));
        $version->refresh();
        $pdf = versionFile($version, (string) $version->pdf_path);

        expect($version->status)->toBe(VersionStatus::Completed)
            ->and($version->error)->toBeNull()
            ->and($version->pdf_path)->toBe($version->directory().'/official.pdf')
            ->and(substr($pdf, 0, 5))->toBe('%PDF-')
            ->and(rtrim($pdf))->toEndWith('%%EOF')
            // 842 x 595 points: A4 turned on its side.
            ->and($pdf)->toContain('/MediaBox [0.000 0.000 841.890 595.280]')
            ->and($pdf)->toContain('DejaVuSans')
            ->and($version->pdf_size)->toBe(strlen($pdf))
            ->and($version->pdf_sha256)->toBe(hash('sha256', $pdf))
            ->and($version->pdf_pages)->toBe(1)
            ->and(versionPdfPages($pdf))->toBe(1)
            ->and($version->signature_status)->toBeNull();
    });

    it('counts the snapshot and the PDF against the storage of the workspace, and records what it counted', function (): void {
        $case = versionTableCase();
        $before = (int) DB::table('workspaces')->where('id', $case['team']['workspace']->id)->value('storage_used');
        $version = versionFreeze($case['inventory'], $case['team']['owner']);

        expect((int) DB::table('workspaces')->where('id', $case['team']['workspace']->id)->value('storage_used'))->toBe($before);

        $version = versionRun($version);

        expect($version->counted_bytes)->toBe($version->snapshot_size + $version->pdf_size)
            ->and((int) DB::table('workspaces')->where('id', $case['team']['workspace']->id)->value('storage_used'))->toBe($before + $version->counted_bytes);
    });

    it('has more pages with more items', function (): void {
        $case = versionTableCase(90);
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));

        expect($version->status)->toBe(VersionStatus::Completed, (string) $version->error?->value)
            ->and($version->pdf_pages)->toBeGreaterThan(2)
            ->and(versionPdfPages(versionFile($version, (string) $version->pdf_path)))->toBe($version->pdf_pages);
    });

    it('lays an inventory of many fields out as cards', function (): void {
        $case = exportFixture();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));

        expect($version->status)->toBe(VersionStatus::Completed, (string) $version->error?->value)
            ->and($version->pdf_pages)->toBeGreaterThanOrEqual(1)
            ->and(versionHtml($version))->toContain('class="card"')->toContain('Drill 18V');
    });

    it('is written for an inventory with no item and no field', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $version = versionRun(versionFreeze(inventoryIn($team['workspace'], 'Empty'), $team['owner']));

        expect($version->status)->toBe(VersionStatus::Completed, (string) $version->error?->value)
            ->and($version->pdf_pages)->toBe(1)
            ->and(versionHtml($version))->toContain('No item.');
    });

    it('is written once: a worker that finds it taken, or finished, does nothing', function (VersionStatus $status): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        $version->forceFill(['status' => $status])->save();

        app(VersionGenerator::class)->run($version);

        expect($version->fresh()->status)->toBe($status)
            ->and($version->fresh()->pdf_path)->toBeNull()
            ->and(array_filter(storedFiles(), fn (string $file): bool => str_ends_with($file, 'official.pdf')))->toBe([]);
    })->with([VersionStatus::Running, VersionStatus::Completed, VersionStatus::Failed]);

    it('is not written a second time by a second run', function (): void {
        $case = versionTableCase();
        $done = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        $workspace = (int) DB::table('workspaces')->where('id', $case['team']['workspace']->id)->value('storage_used');

        app(VersionGenerator::class)->run($done);

        expect((int) DB::table('workspaces')->where('id', $case['team']['workspace']->id)->value('storage_used'))->toBe($workspace)
            ->and($done->fresh()->counted_bytes)->toBe($done->counted_bytes);
    });

    it('writes in the language of the version, and leaves the language of the worker as it was', function (): void {
        $case = versionTableCase();
        app()->setLocale('fr');
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        app()->setLocale('en');

        $version = versionRun($version);

        expect($version->status)->toBe(VersionStatus::Completed)
            ->and(app()->getLocale())->toBe('en')
            ->and(versionHtml($version))->toContain('Version officielle n° 1')->toContain('Auteur : Owner')->toContain('1 234,50 €');
    });
});

describe('the document', function (): void {
    it('says what it is: the number and the label, when it was frozen (in both zones), by whom, how many items, and the whole SHA-256', function (): void {
        $case = versionTableCase();
        $this->travelTo(now()->setDate(2026, 7, 14)->setTime(21, 30));
        $version = versionFreeze($case['inventory'], $case['team']['owner'], 'Year end');
        $html = versionHtml($version);

        expect($html)->toContain('Official version no. 1 · Year end')
            ->and($html)->toContain('Frozen on Jul 14, 2026, 11:30 PM (Europe/Paris) · Jul 14, 2026, 9:30 PM UTC · Tools')
            ->and($html)->toContain('Author: Owner · 3 items')
            ->and($html)->toContain('SHA-256 of the data: '.$version->snapshot_sha256)
            ->and(strlen($version->snapshot_sha256))->toBe(64);
    });

    it('is written from the snapshot, not from what the inventory holds now', function (): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        $item = $case['inventory']->items()->orderBy('id')->firstOrFail();

        // The live data moves on: the inventory is renamed, an item is deleted, the others change.
        $case['inventory']->forceFill(['name' => 'Renamed'])->save();
        DB::table('items')->where('inventory_id', $case['inventory']->id)->delete();
        DB::table('inventory_fields')->where('inventory_id', $case['inventory']->id)->delete();
        $version = versionRun($version);
        $html = versionHtml($version);

        expect($version->status)->toBe(VersionStatus::Completed, (string) $version->error?->value)
            ->and($html)->toContain('Tool 1')->toContain('Tool 3')->toContain('1,234.50')->toContain('· Tools')
            ->and($html)->not->toContain('Renamed')
            ->and($version->items_count)->toBe(3);
    });

    it('lists the items in the order they were frozen, oldest first', function (): void {
        $case = versionTableCase();
        $html = versionHtml(versionFreeze($case['inventory'], $case['team']['owner']));

        expect(strpos($html, 'Tool 1'))->toBeLessThan(strpos($html, 'Tool 2'))
            ->and(strpos($html, 'Tool 2'))->toBeLessThan(strpos($html, 'Tool 3'));
    });

    it('has no page of signatures when nobody is asked to sign', function (): void {
        $case = versionTableCase();
        $html = versionHtml(versionFreeze($case['inventory'], $case['team']['owner']));

        expect($html)->not->toContain('class="signatures"')->not->toContain('signer-0');
    });

    it('has the number of pages of its items alone when nobody is asked to sign', function (): void {
        $case = versionTableCase(90);
        $alone = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        versionDocumenso();
        $signed = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(2)));

        expect($signed->pdf_pages)->toBe($alone->pdf_pages + 1);
    });
});

describe('the page of signatures', function (): void {
    it('is the last page, with a box for each signer: name, address and an empty area', function (): void {
        versionDocumenso();
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner'], null, [['name' => 'Ada Lovelace', 'email' => 'ada@example.test'], ['name' => 'Grace Hopper', 'email' => 'grace@example.test']]);
        $html = versionHtml($version);

        expect($html)->toContain('class="signatures"')
            ->and($html)->toContain('Ada Lovelace')->toContain('ada@example.test')->toContain('Grace Hopper')->toContain('grace@example.test')
            ->and(substr_count($html, 'class="area"'))->toBe(2)
            ->and($html)->toContain('id="signer-0"')->toContain('id="signer-1"')
            ->and($html)->toContain('page-break-before: always');
    });

    it('is a page of its own after the items: two pages for a few items', function (): void {
        versionDocumenso();
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(2)));

        expect($version->status)->toBe(VersionStatus::Completed, (string) $version->error?->value)
            ->and($version->pdf_pages)->toBe(2)
            ->and($version->signature_status)->toBe(SignatureStatus::Requested);
    });

    it('records where each signature area is: the last page, and four percentages of the page, y from the top', function (): void {
        versionDocumenso();
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(3)));
        $fields = array_column($version->signers ?? [], 'field');

        expect($fields)->toHaveCount(3);

        foreach ($fields as $field) {
            expect(array_keys($field))->toEqualCanonicalizing(['page', 'x', 'y', 'width', 'height'])
                ->and($field['page'])->toBe($version->pdf_pages)
                ->and($field['x'])->toBeFloat()->toBeGreaterThan(0)->toBeLessThan(100)
                ->and($field['y'])->toBeGreaterThan(0)->toBeLessThan(100)
                ->and($field['x'] + $field['width'])->toBeLessThanOrEqual(100)
                ->and($field['y'] + $field['height'])->toBeLessThanOrEqual(100)
                // 330 x 48 CSS pixels on a 841.89 x 595.28 points page.
                ->and($field['width'])->toEqualWithDelta(330 * 0.75 / 841.89 * 100, 0.01)
                ->and($field['height'])->toEqualWithDelta(48 * 0.75 / 595.28 * 100, 0.01);
        }

        // Two to a row, left to right, then the next row down; nothing overlaps.
        expect($fields[1]['y'])->toBe($fields[0]['y'])
            ->and($fields[1]['x'])->toBeGreaterThan($fields[0]['x'] + $fields[0]['width'])
            ->and($fields[2]['x'])->toBe($fields[0]['x'])
            ->and($fields[2]['y'])->toBeGreaterThan($fields[0]['y'] + $fields[0]['height']);
    });

    it('records the areas the page really draws, to the hundredth of a point', function (int $count): void {
        versionDocumenso();
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners($count)));
        $drawn = versionDrawnAreas(versionFile($version, (string) $version->pdf_path));
        $fields = array_column($version->signers ?? [], 'field');

        expect($drawn)->toHaveCount($count)
            ->and($fields)->toHaveCount($count);

        foreach ($fields as $index => $field) {
            expect($field['x'])->toEqualWithDelta($drawn[$index]['x'] / 841.89 * 100, 0.01)
                ->and($field['y'])->toEqualWithDelta($drawn[$index]['y'] / 595.28 * 100, 0.01)
                ->and($field['width'])->toEqualWithDelta($drawn[$index]['width'] / 841.89 * 100, 0.01)
                ->and($field['height'])->toEqualWithDelta($drawn[$index]['height'] / 595.28 * 100, 0.01);
        }
    })->with([1, 2, 3, 7, 10]);

    it('holds ten signers on the one last page, whatever the layout of the items', function (string $layout): void {
        versionDocumenso();
        $case = $layout === 'table' ? versionTableCase(60) : exportFixture();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(10)));
        $fields = array_column($version->signers ?? [], 'field');
        $rows = array_values(array_unique(array_column($fields, 'y')));

        expect($version->status)->toBe(VersionStatus::Completed, (string) $version->error?->value)
            ->and($fields)->toHaveCount(10)
            ->and(array_unique(array_column($fields, 'page')))->toBe([$version->pdf_pages])
            ->and($rows)->toHaveCount(5)
            ->and(max(array_map(fn (array $field): float => $field['y'] + $field['height'], $fields)))->toBeLessThan(100 - 48 * 0.75 / 595.28 * 100);
    })->with(['table', 'cards']);

    it('keeps every other key of a signer, and the order of the signers', function (): void {
        versionDocumenso();
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(2)));

        expect(array_column($version->signers ?? [], 'email'))->toBe(['signer1@example.test', 'signer2@example.test'])
            ->and(array_keys($version->signers[0]))->toEqualCanonicalizing(['name', 'email', 'status', 'signed_at', 'rejection_reason', 'recipient_id', 'field']);
    });

    it('cuts a long name and a long address to fit its box, and keeps the real ones in the row', function (): void {
        versionDocumenso();
        $case = versionTableCase();
        $long = str_repeat('Wolfeschlegelsteinhausenbergerdorff ', 6);
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, [['name' => trim($long), 'email' => 'a@example.test']]));

        expect($version->status)->toBe(VersionStatus::Completed, (string) $version->error?->value)
            ->and($version->signers[0]['name'])->toBe(trim($long))
            ->and(versionHtml($version))->not->toContain(trim($long))
            ->and(versionHtml($version))->toContain('…');
    });
});

describe('a version that cannot be written', function (): void {
    it('fails when the snapshot on the disk is not the one that was frozen, and leaves nothing behind', function (): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        Storage::disk(ATTACHMENT_TEST_DISK)->put($version->snapshot_path, str_replace('Tool 1', 'Tool X', versionFile($version, $version->snapshot_path)));
        Log::spy();

        $version = versionRun($version);

        expect($version->status)->toBe(VersionStatus::Failed)
            ->and($version->error)->toBe(VersionError::GenerationFailed)
            ->and($version->pdf_path)->toBeNull()
            ->and($version->counted_bytes)->toBe(0);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [$version->directory()]);
    });

    it('fails when the snapshot is gone from the disk', function (): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        Storage::disk(ATTACHMENT_TEST_DISK)->delete($version->snapshot_path);
        Log::spy();

        expect(versionRun($version)->error)->toBe(VersionError::GenerationFailed);
    });

    it('fails with pdf_too_large for an inventory the limit of a PDF has outgrown, without counting anything', function (): void {
        $case = versionTableCase(4);
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        config(['inventories.pdf_max_items' => 3]);
        $before = (int) DB::table('workspaces')->where('id', $case['team']['workspace']->id)->value('storage_used');
        Log::spy();

        $version = versionRun($version);

        expect($version->status)->toBe(VersionStatus::Failed)
            ->and($version->error)->toBe(VersionError::PdfTooLarge)
            ->and($version->counted_bytes)->toBe(0)
            ->and((int) DB::table('workspaces')->where('id', $case['team']['workspace']->id)->value('storage_used'))->toBe($before);
        Queue::assertPushed(PurgeStoredFiles::class);
    });

    it('fails with pdf_too_large when the HTML is more than the library can lay out in the memory of a worker', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Texts');
        $notes = fieldIn($inventory, 'text', 'Notes');
        // The count of items is allowed (the limit is raised); the HTML of 5000 rows of a long text is over 1 MB.
        config(['inventories.pdf_max_items' => 100000]);
        $rows = [];

        for ($i = 1; $i <= 5000; $i++) {
            $rows[] = ['ulid' => strtolower((string) Str::ulid()), 'inventory_id' => $inventory->id, 'values' => json_encode([$notes->ulid => str_repeat('x', 110).$i]), 'search_text' => '', 'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('items')->insert($chunk);
        }

        $version = versionFreeze($inventory, $team['owner']);
        Log::spy();
        $version = versionRun($version);

        expect($version->items_count)->toBe(5000)
            ->and($version->status)->toBe(VersionStatus::Failed)
            ->and($version->error)->toBe(VersionError::PdfTooLarge)
            ->and($version->counted_bytes)->toBe(0);
    });

    it('fails when the PDF cannot be stored, and counts nothing', function (): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        $disk = Storage::disk(ATTACHMENT_TEST_DISK);
        $proxy = attachmentDiskProxy();
        $proxy->shouldReceive('put')->andReturn(false);
        Log::spy();

        $version = versionRun($version);

        expect($version->status)->toBe(VersionStatus::Failed)
            ->and($version->error)->toBe(VersionError::GenerationFailed)
            ->and($version->counted_bytes)->toBe(0)
            ->and($disk->exists($version->directory().'/official.pdf'))->toBeFalse();
    });

    it('is purged, not counted, when the version is deleted while its PDF is being written', function (): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        $real = Storage::disk(ATTACHMENT_TEST_DISK);
        $proxy = attachmentDiskProxy();
        $proxy->shouldReceive('put')->andReturnUsing(function (string $path, mixed $contents) use ($real, $version): bool {
            // The person deletes the version (and its row goes) just before the PDF is stored.
            DB::table('inventory_versions')->where('id', $version->id)->delete();

            return (bool) $real->put($path, $contents);
        });
        $before = (int) DB::table('workspaces')->where('id', $case['team']['workspace']->id)->value('storage_used');

        app(VersionGenerator::class)->run($version);

        expect(InventoryVersion::query()->count())->toBe(0)
            ->and((int) DB::table('workspaces')->where('id', $case['team']['workspace']->id)->value('storage_used'))->toBe($before);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [$version->directory()]);
    });

    it('leaves a completed version alone when a late failure is reported for it', function (): void {
        $case = versionTableCase();
        $done = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        Queue::fake();

        app(VersionGenerator::class)->fail($done, VersionError::GenerationFailed);

        expect($done->fresh()->status)->toBe(VersionStatus::Completed)
            ->and($done->fresh()->counted_bytes)->toBe($done->counted_bytes)
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->exists((string) $done->pdf_path))->toBeTrue();
        Queue::assertNothingPushed();
    });

    it('is failed, and its folder purged, by a job the queue gave up on', function (): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);

        (new GenerateOfficialVersion($version))->failed(new RuntimeException('timeout'));

        expect($version->fresh()->status)->toBe(VersionStatus::Failed)
            ->and($version->fresh()->error)->toBe(VersionError::GenerationFailed);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [$version->directory()]);
    });
});
