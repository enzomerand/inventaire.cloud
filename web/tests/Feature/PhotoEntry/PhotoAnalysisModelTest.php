<?php

declare(strict_types=1);

use App\Auth\Exceptions\AccountDeletionRefused;
use App\Installer\ApplicationTables;
use App\Inventories\Attachments\AttachmentStorage;
use App\Jobs\PurgeStoredFiles;
use App\Models\Inventory;
use App\Models\PhotoAnalysis;
use App\PhotoEntry\PhotoAnalysisError;
use App\PhotoEntry\PhotoAnalysisStatus;
use App\View\Components\ClientMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/photo-entry.php';

/*
 * The photo analyses as rows: what the model casts, who they belong to, and how their photos are purged when an analysis, its
 * inventory, its workspace or the account that sent it is deleted (they are purged after the commit, by a queued job).
 */

uses(RefreshDatabase::class);

describe('the model', function (): void {
    it('is found by its ULID, in any case, and casts its columns', function (): void {
        $case = photoCase();
        $analysis = PhotoAnalysis::factory()->create([
            'inventory_id' => $case['inventory']->id,
            'user_id' => $case['team']['editor']->id,
            'status' => PhotoAnalysisStatus::Completed,
            'proposals' => [['field' => '01abc', 'value' => '12.50', 'confidence' => 'high', 'evidence' => 'Label']],
            'input_tokens' => 1200,
        ]);

        $found = PhotoAnalysis::query()->where('ulid', $analysis->ulid)->sole();

        expect($analysis->getRouteKeyName())->toBe('ulid')
            ->and(strlen($analysis->ulid))->toBe(26)
            ->and($found->status)->toBe(PhotoAnalysisStatus::Completed)
            ->and($found->proposals)->toEqual([['field' => '01abc', 'value' => '12.50', 'confidence' => 'high', 'evidence' => 'Label']])
            ->and($found->input_tokens)->toBe(1200)
            ->and($found->inventory->is($case['inventory']))->toBeTrue()
            ->and($case['inventory']->photoAnalyses()->count())->toBe(1);
    });

    it('knows whether its time is up', function (): void {
        $case = photoCase();
        $fresh = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'expires_at' => now()->addHour()]);
        $open = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'expires_at' => null]);
        $old = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'expires_at' => now()->subSecond()]);

        expect($old->isExpired())->toBeTrue()
            ->and($fresh->isExpired())->toBeFalse()
            ->and($open->isExpired())->toBeFalse();
    });

    it('knows the failure codes it wrote, and none it does not', function (): void {
        $case = photoCase();
        $analysis = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'error' => 'unavailable']);

        expect($analysis->failure())->toBe(PhotoAnalysisError::Unavailable);

        $analysis->error = 'from-a-later-version';
        expect($analysis->failure())->toBeNull();

        $analysis->error = null;
        expect($analysis->failure())->toBeNull();
    });

    it('has a message and a retry rule in the front for every failure code, and only for those', function (): void {
        $script = (string) file_get_contents(resource_path('js/photo-entry/proposals.js'));
        preg_match('/const ANALYSIS_FAILURES = \{(.*?)\n\};/s', $script, $table);
        preg_match_all("/^\s+(\w+): \['(photoEntry\.error\.\w+)', (?:true|false)\],$/m", $table[1] ?? '', $rows, PREG_SET_ORDER);
        $keys = array_column($rows, 2, 1);
        $messages = (new ClientMessages)->messages();

        expect(array_keys($keys))->toEqualCanonicalizing(array_map(fn (PhotoAnalysisError $error): string => $error->value, PhotoAnalysisError::cases()));

        foreach ($keys as $key) {
            expect($messages)->toHaveKey($key);
        }
    });

    it('goes with its inventory and with the account that sent it', function (): void {
        $case = photoCase();
        $team = $case['team'];
        $other = inventoryIn($team['workspace'], 'Other');
        $byInventory = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $team['owner']->id]);
        $byUser = PhotoAnalysis::factory()->create(['inventory_id' => $other->id, 'user_id' => $team['editor']->id]);
        $kept = PhotoAnalysis::factory()->create(['inventory_id' => $other->id, 'user_id' => $team['owner']->id]);

        $case['inventory']->delete();
        expect(PhotoAnalysis::query()->whereKey($byInventory->id)->exists())->toBeFalse();

        $team['editor']->delete();
        expect(PhotoAnalysis::query()->whereKey($byUser->id)->exists())->toBeFalse()
            ->and(PhotoAnalysis::query()->whereKey($kept->id)->exists())->toBeTrue();
    });

    it('is listed among the tables of the application', function (): void {
        expect(ApplicationTables::owns('photo_analyses'))->toBeTrue();
    });
});

describe('the photos', function (): void {
    it('are purged when the analysis is deleted, and only its own folder', function (): void {
        $case = photoCase();
        $first = photoAnalysisWithFiles($case['inventory'], $case['team']['owner']);
        $second = photoAnalysisWithFiles($case['inventory'], $case['team']['owner']);

        $first->delete();

        expect(storedFiles())->toBe([photoFolder($second).'/1.jpg', photoFolder($second).'/2.jpg'])->and(PhotoAnalysis::query()->count())->toBe(1);
    });

    it('are purged after the commit, and not at all when the deletion is rolled back', function (): void {
        $case = photoCase();
        $analysis = photoAnalysisWithFiles($case['inventory'], $case['team']['owner'], count: 1);

        try {
            DB::transaction(function () use ($analysis): void {
                $analysis->delete();

                throw new RuntimeException('Rolled back.');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        expect(storedFiles())->toBe([photoFolder($analysis).'/1.jpg'])->and(PhotoAnalysis::query()->count())->toBe(1);

        Queue::fake();
        DB::transaction(fn () => PhotoAnalysis::query()->findOrFail($analysis->id)->delete());

        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [photoFolder($analysis)]);
    });

    it('are purged with the inventory, the workspace and the account', function (): void {
        $case = photoCase();
        $team = $case['team'];
        $two = inventoryIn($team['workspace'], 'Two');
        $viewers = photoAnalysisWithFiles($case['inventory'], $team['viewer'], count: 1);
        $editors = photoAnalysisWithFiles($two, $team['editor'], count: 1);
        $owners = photoAnalysisWithFiles($two, $team['owner'], count: 1);

        $stranger = $team['stranger'];
        $theirs = photoAnalysisWithFiles(inventoryIn($stranger->workspaces()->sole()), $stranger, count: 1);

        // The inventory takes its analyses with it, even when they are all it holds on the disk.
        $case['inventory']->delete();
        expect(storedFiles())->toBe(collect([photoFolder($editors).'/1.jpg', photoFolder($owners).'/1.jpg', photoFolder($theirs).'/1.jpg'])->sort()->values()->all());

        // The account takes the analyses it started in a workspace that goes on.
        $team['editor']->delete();
        expect(storedFiles())->toBe(collect([photoFolder($owners).'/1.jpg', photoFolder($theirs).'/1.jpg'])->sort()->values()->all());

        // The workspace takes the rest of its own.
        $team['workspace']->delete();
        expect(storedFiles())->toBe([photoFolder($theirs).'/1.jpg']);

        // An account that owned its workspace alone takes everything with it.
        $stranger->delete();
        expect(storedFiles())->toBe([])->and(PhotoAnalysis::query()->count())->toBe(0);
        unset($viewers);
    });

    it('are not purged for an account whose deletion is refused', function (): void {
        $case = photoCase();
        $analysis = photoAnalysisWithFiles($case['inventory'], $case['team']['owner'], count: 1);

        // The owner of a workspace shared with other people cannot be deleted while it is the only owner.
        expect(fn () => $case['team']['owner']->delete())->toThrow(AccountDeletionRefused::class);

        expect(storedFiles())->toBe([photoFolder($analysis).'/1.jpg'])->and(PhotoAnalysis::query()->whereKey($analysis->id)->exists())->toBeTrue();
    });

    it('are not purged for an analysis whose inventory is already gone', function (): void {
        $case = photoCase();
        Queue::fake();
        $analysis = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id]);
        $inventoryId = $case['inventory']->id;

        DB::table('inventories')->where('id', $inventoryId)->delete();
        $analysis->delete();

        Queue::assertNotPushed(PurgeStoredFiles::class);
        expect(Inventory::query()->whereKey($inventoryId)->exists())->toBeFalse();
    });
});

describe('the purge job', function (): void {
    it('accepts the folder of an analysis and nothing in between', function (): void {
        $ok = new PurgeStoredFiles('local', [], [AttachmentStorage::analysisDirectory('01abc', '01def', '01ghi')]);

        expect($ok->directories)->toBe(['workspaces/01abc/inventories/01def/analyses/01ghi']);

        foreach (['workspaces/01abc/analyses/01ghi', 'workspaces/01abc/inventories/01def/analyses', 'workspaces/01abc/inventories/01def/analyses/01ghi/1.jpg', 'analyses/01ghi'] as $folder) {
            expect(fn () => new PurgeStoredFiles('local', [], [$folder]))->toThrow(InvalidArgumentException::class);
        }
    });
});
