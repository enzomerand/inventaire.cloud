<?php

declare(strict_types=1);

use App\Admin\UserAdministration;
use App\Database\DeadlockRetries;
use App\Inventories\Attachments\Attachments;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\DerivedData;
use App\Inventories\Inventories;
use App\Inventories\InventoryFields;
use App\Inventories\Items;
use App\Jobs\PurgeStoredFiles;
use App\Models\Attachment;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\ItemRevision;
use App\Models\Workspace;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/attachments.php';

/*
 * What becomes of the files on the disk when what holds them goes: an attachment, an item, a file or photo field, an inventory,
 * a workspace, an account that owned one alone. The files are deleted once the deletion is committed, by a queued job (the
 * suite runs the queue synchronously), counted out of the storage of the workspace in the same transaction, and never
 * for a change that was rolled back. And the counters `inventaire:upgrade` adds up again.
 */

uses(RefreshDatabase::class);

/**
 * A team with two inventories that hold items with files of every kind: a plain PDF, a photo (with its thumbnail) and a
 * document in fields, and a second workspace of a stranger whose files must never be touched.
 *
 * @return array<string, mixed>
 */
function purgeCase(): array
{
    attachmentDisk();
    $team = inventoryTeam();
    $tools = inventoryIn($team['workspace'], 'Tools');
    $books = inventoryIn($team['workspace'], 'Books');
    $photos = fieldIn($tools, 'photo', 'Photos', ['max_files' => 3]);
    $documents = fieldIn($tools, 'file', 'Documents');
    $hammer = itemIn($tools);
    $saw = itemIn($tools);
    $novel = itemIn($books);
    $files = [
        'plain' => attachmentTo($tools, $hammer, attachmentPdf('plain.pdf'), $team['owner']),
        'photo' => attachmentTo($tools, $hammer, attachmentImage('photo.png', 'png', 600, 300), $team['owner'], $photos),
        'document' => attachmentTo($tools, $hammer, attachmentPdf('doc.pdf'), $team['owner'], $documents),
        'saw' => attachmentTo($tools, $saw, attachmentPdf('saw.pdf'), $team['owner']),
        'novel' => attachmentTo($books, $novel, attachmentPdf('novel.pdf'), $team['owner']),
    ];

    $stranger = inventoryPerson('Stranger');
    $elsewhere = inventoryIn($stranger->workspaces()->sole());
    $foreign = attachmentTo($elsewhere, itemIn($elsewhere), attachmentImage('theirs.png'), $stranger);

    return $team + compact('tools', 'books', 'photos', 'documents', 'hammer', 'saw', 'novel', 'files', 'foreign') + ['theirs' => $stranger];
}

/**
 * The paths a file and its thumbnail occupy.
 *
 * @return list<string>
 */
function purgePaths(Attachment ...$attachments): array
{
    $paths = [];

    foreach ($attachments as $attachment) {
        $paths[] = $attachment->path;

        if ($attachment->thumbnail_path !== null) {
            $paths[] = $attachment->thumbnail_path;
        }
    }

    sort($paths);

    return $paths;
}

/**
 * The bytes the workspace says its files use, and the bytes its attachment rows add up to.
 *
 * @return array{stored: int, counted: int}
 */
function purgeStorage(Workspace $workspace): array
{
    return [
        'stored' => (int) DB::table('workspaces')->where('id', $workspace->id)->value('storage_used'),
        'counted' => (int) DB::table('attachments')->join('inventories', 'inventories.id', '=', 'attachments.inventory_id')->where('inventories.workspace_id', $workspace->id)->sum('attachments.size'),
    ];
}

/**
 * Makes the next deletion from a table fail in the database, once it is asked for (the statement never reaches the engine).
 */
function purgeRefuseDeletionFrom(string $table): void
{
    DB::beforeExecuting(function (string $query) use ($table): void {
        if (preg_match('/^delete from ["`]?'.$table.'["`]?(\s|$)/i', $query) === 1) {
            throw new RuntimeException("The database refuses to delete from {$table}.");
        }
    });
}

describe('the job', function (): void {
    it('deletes the paths and the folders it is given, and what is already gone is no failure', function (): void {
        attachmentDisk();
        $disk = Storage::disk(ATTACHMENT_TEST_DISK);
        $disk->put('workspaces/w1/inventories/i1/items/a/file', 'x');
        $disk->put('workspaces/w1/inventories/i1/items/a/file.thumb.jpg', 'x');
        $disk->put('workspaces/w1/inventories/i1/items/b/file', 'x');
        $disk->put('workspaces/w1/inventories/i1/items/ab/file', 'x');
        $disk->put('workspaces/w2/inventories/i2/items/c/file', 'x');

        (new PurgeStoredFiles(ATTACHMENT_TEST_DISK, ['workspaces/w1/inventories/i1/items/a/file', 'workspaces/w1/missing'], ['workspaces/w1/inventories/i1/items/b', 'workspaces/w9']))->handle(app('filesystem'));

        // A folder is deleted whole, and only that one: "ab" is not "a".
        expect(storedFiles())->toBe([
            'workspaces/w1/inventories/i1/items/a/file.thumb.jpg',
            'workspaces/w1/inventories/i1/items/ab/file',
            'workspaces/w2/inventories/i2/items/c/file',
        ]);
    });

    it('writes a warning in the log for a path or a folder that the disk says it did not delete, and fails so that it is tried again', function (): void {
        attachmentDisk();
        $disk = attachmentDiskProxy();
        $disk->shouldReceive('delete')->once()->with('workspaces/w1/file')->andReturn(false);
        $disk->shouldReceive('deleteDirectory')->once()->with('workspaces/w1')->andReturn(false);
        Log::spy();

        expect(fn () => (new PurgeStoredFiles(ATTACHMENT_TEST_DISK, ['workspaces/w1/file'], ['workspaces/w1']))->handle(app('filesystem')))->toThrow(RuntimeException::class);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'could not be deleted') && $context['path'] === 'workspaces/w1/file')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'could not be deleted') && $context['path'] === 'workspaces/w1')->once();
    });

    it('deletes a whole workspace, or a whole inventory, by its folder', function (): void {
        attachmentDisk();
        $disk = Storage::disk(ATTACHMENT_TEST_DISK);
        $disk->put('workspaces/w1/inventories/i1/items/a/file', 'x');
        $disk->put('workspaces/w1/inventories/i2/items/b/file', 'x');
        $disk->put('workspaces/w2/inventories/i3/items/c/file', 'x');

        (new PurgeStoredFiles(ATTACHMENT_TEST_DISK, [], ['workspaces/w1/inventories/i1']))->handle(app('filesystem'));
        expect(storedFiles())->toBe(['workspaces/w1/inventories/i2/items/b/file', 'workspaces/w2/inventories/i3/items/c/file']);

        (new PurgeStoredFiles(ATTACHMENT_TEST_DISK, [], ['workspaces/w1']))->handle(app('filesystem'));
        expect(storedFiles())->toBe(['workspaces/w2/inventories/i3/items/c/file']);
    });

    it('refuses a folder that is not one of a workspace, an inventory or an item: a short path would empty a disk', function (string $directory): void {
        expect(fn () => new PurgeStoredFiles(ATTACHMENT_TEST_DISK, [], [$directory]))->toThrow(InvalidArgumentException::class);
    })->with(['', '/', '.', 'workspaces', 'workspaces/', 'other/w1', 'workspaces/w1/', 'workspaces/../x', 'workspaces/w1/inventories', 'workspaces/w1/inventories/i1/items', 'workspaces/w1/inventories/i1/items/a/file', 'workspaces/W1']);

    it('tries three times with a pause that grows, and is queued', function (): void {
        $job = new PurgeStoredFiles(ATTACHMENT_TEST_DISK, ['x']);

        expect($job->tries)->toBe(3)
            ->and($job->backoff())->toBe([30, 300])
            ->and($job)->toBeInstanceOf(ShouldQueue::class);
    });

    it('goes on after a path it cannot delete, then fails so that the queue tries again', function (): void {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->with('first')->once()->andReturn(false);
        $disk->shouldReceive('delete')->with('second')->once()->andReturn(true);
        $disk->shouldReceive('delete')->with('third')->once()->andThrow(new RuntimeException('The disk went away.'));
        $disk->shouldReceive('deleteDirectory')->with('workspaces/w1')->once()->andReturn(false);
        Storage::set('flaky', $disk);
        Log::spy();

        expect(fn () => (new PurgeStoredFiles('flaky', ['first', 'second', 'third'], ['workspaces/w1']))->handle(app('filesystem')))
            ->toThrow(RuntimeException::class, 'Could not delete 3 stored file(s) or folder(s) on the disk [flaky].');
    });

    it('writes in the log what could not be deleted once the last try failed', function (): void {
        Log::spy();
        $job = new PurgeStoredFiles('flaky', ['a/b'], ['workspaces/w1']);

        $job->failed(new RuntimeException('The disk went away.'));

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'could not be deleted')
            && $context['disk'] === 'flaky' && $context['paths'] === ['a/b'] && $context['directories'] === ['workspaces/w1']);
    });

    it('fails for a disk that does not exist rather than succeed doing nothing', function (): void {
        expect(fn () => (new PurgeStoredFiles('no-such-disk', ['x']))->handle(app('filesystem')))->toThrow(InvalidArgumentException::class);
    });
});

describe('queueing a purge', function (): void {
    it('waits for the commit of the transaction in progress', function (): void {
        Queue::fake();

        DB::transaction(function (): void {
            app(AttachmentStorage::class)->purgeAfterCommit(ATTACHMENT_TEST_DISK, ['a/b']);

            Queue::assertNothingPushed();
        });

        Queue::assertPushed(PurgeStoredFiles::class, 1);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->disk === ATTACHMENT_TEST_DISK && $job->paths === ['a/b'] && $job->directories === []);
    });

    it('purges nothing for a transaction that is rolled back', function (): void {
        Queue::fake();

        expect(fn () => DB::transaction(function (): void {
            app(AttachmentStorage::class)->purgeAfterCommit(ATTACHMENT_TEST_DISK, ['a/b'], ['workspaces/w1']);

            throw new RuntimeException('The request failed.');
        }))->toThrow(RuntimeException::class);

        Queue::assertNothingPushed();
    });

    it('purges once for a transaction that lost a deadlock and was run again', function (): void {
        Queue::fake();
        $attempts = 0;

        // Not an arrow function: it would hand the work its own copy of the counter on every try.
        DeadlockRetries::run(function () use (&$attempts): void {
            DB::transaction(function () use (&$attempts): void {
                app(AttachmentStorage::class)->purgeAfterCommit(ATTACHMENT_TEST_DISK, ['a/b']);

                if (++$attempts === 1) {
                    throw lostDeadlock();
                }
            });
        });

        expect($attempts)->toBe(2);
        Queue::assertPushed(PurgeStoredFiles::class, 1);
    });

    it('queues at once when there is no transaction, and nothing when there is nothing to delete', function (): void {
        Queue::fake();
        $storage = app(AttachmentStorage::class);

        $storage->purgeAfterCommit(ATTACHMENT_TEST_DISK);
        Queue::assertNothingPushed();

        $storage->purgeAfterCommit(ATTACHMENT_TEST_DISK, ['a'], ['workspaces/w1']);
        Queue::assertPushed(PurgeStoredFiles::class, 2);
    });

    it('splits a long list of paths into jobs of five hundred', function (): void {
        Queue::fake();

        app(AttachmentStorage::class)->purgeAfterCommit(ATTACHMENT_TEST_DISK, array_map(fn (int $number): string => "workspaces/w/file-{$number}", range(1, 1201)));

        Queue::assertPushed(PurgeStoredFiles::class, 3);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => count($job->paths) === 201);
    });

    it('does not turn a queue that is down into a failure of a change that happened, and logs the paths', function (): void {
        Log::spy();
        Queue::shouldReceive('push')->andThrow(new RuntimeException('The queue is down.'));
        Queue::shouldReceive('connection')->andReturnSelf();

        app(AttachmentStorage::class)->purgeAfterCommit(ATTACHMENT_TEST_DISK, ['a/b']);

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'could not be queued') && $context['paths'] === ['a/b']);
    });
});

describe('removing an attachment', function (): void {
    it('deletes its file and its thumbnail, and nothing else', function (): void {
        $case = purgeCase();
        $photo = $case['files']['photo'];

        expect($photo->thumbnail_path)->not->toBeNull();

        app(Attachments::class)->delete($case['tools'], $case['hammer'], $photo, $case['owner']);

        expect(storedFiles())->toBe(purgePaths($case['files']['plain'], $case['files']['document'], $case['files']['saw'], $case['files']['novel'], $case['foreign']))
            ->and(purgeStorage($case['workspace']))->toBe(['stored' => $case['files']['plain']->size + $case['files']['document']->size + $case['files']['saw']->size + $case['files']['novel']->size, 'counted' => $case['files']['plain']->size + $case['files']['document']->size + $case['files']['saw']->size + $case['files']['novel']->size]);
    });

    it('keeps the files when the removal is rolled back', function (): void {
        $case = purgeCase();
        $before = purgeStorage($case['workspace']);

        expect(fn () => DB::transaction(function () use ($case): void {
            app(Attachments::class)->delete($case['tools'], $case['hammer'], $case['files']['plain'], $case['owner']);

            throw new RuntimeException('The request failed afterwards.');
        }))->toThrow(RuntimeException::class);

        expect(storedFiles())->toHaveCount(count(purgePaths(...array_values($case['files']), ...[$case['foreign']])))
            ->and(Attachment::query()->count())->toBe(6)
            ->and(purgeStorage($case['workspace']))->toBe($before);
    });
});

describe('deleting an item', function (): void {
    it('deletes the folder of the item: its files and thumbnails, the files of its fields included', function (): void {
        $case = purgeCase();

        inventoryCall($this, $case['owner'], 'DELETE', "/api/v1/inventories/{$case['tools']->ulid}/items/{$case['hammer']->ulid}")->assertNoContent();

        $left = purgePaths($case['files']['saw'], $case['files']['novel'], $case['foreign']);

        expect(storedFiles())->toBe($left)
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->directories("workspaces/{$case['workspace']->ulid}/inventories/{$case['tools']->ulid}/items"))->toBe(["workspaces/{$case['workspace']->ulid}/inventories/{$case['tools']->ulid}/items/{$case['saw']->ulid}"])
            ->and(Attachment::query()->pluck('id')->sort()->values()->all())->toBe(collect([$case['files']['saw'], $case['files']['novel'], $case['foreign']])->pluck('id')->sort()->values()->all())
            ->and(purgeStorage($case['workspace']))->toBe(['stored' => $case['files']['saw']->size + $case['files']['novel']->size, 'counted' => $case['files']['saw']->size + $case['files']['novel']->size]);
    });

    it('deletes its history with it, and leaves the history of the other items', function (): void {
        $case = purgeCase();

        app(Items::class)->delete($case['tools'], $case['hammer']);

        expect(ItemRevision::query()->where('item_id', $case['hammer']->id)->count())->toBe(0)
            ->and(ItemRevision::query()->where('item_id', $case['saw']->id)->count())->toBeGreaterThan(0);
    });

    it('keeps everything when the deletion is rolled back, and deletes nothing from the disk', function (): void {
        $case = purgeCase();
        $before = [Attachment::query()->count(), count(storedFiles()), purgeStorage($case['workspace'])];
        Item::deleting(fn () => throw new RuntimeException('The database failed.'));

        expect(fn () => app(Items::class)->delete($case['tools'], $case['hammer']))->toThrow(RuntimeException::class);

        expect([Attachment::query()->count(), count(storedFiles()), purgeStorage($case['workspace'])])->toBe($before)
            ->and(ItemRevision::query()->where('item_id', $case['hammer']->id)->count())->toBeGreaterThan(0)
            ->and(Item::query()->whereKey($case['hammer']->id)->exists())->toBeTrue();
    });

    it('purges each disk the files of the item were written to, even one that is not the current one', function (): void {
        $case = purgeCase();
        Storage::fake('second-disk');
        config(['inventories.attachments_disk' => 'second-disk']);
        $other = attachmentTo($case['tools'], $case['hammer'], attachmentPdf('other.pdf'), $case['owner']);

        expect($other->disk)->toBe('second-disk')
            ->and(Storage::disk('second-disk')->allFiles())->toHaveCount(1);

        app(Items::class)->delete($case['tools'], $case['hammer']);

        expect(Storage::disk('second-disk')->allFiles())->toBe([])
            ->and(storedFiles())->toBe(purgePaths($case['files']['saw'], $case['files']['novel'], $case['foreign']));
    });

    it('does nothing about files for an item that has none', function (): void {
        $case = purgeCase();
        $bare = itemIn($case['books']);
        Queue::fake();

        app(Items::class)->delete($case['books'], $bare);

        Queue::assertNothingPushed();
    });
});

describe('deleting a file or photo field', function (): void {
    it('deletes the files of the field, with their thumbnails, their rows and their bytes, and no other', function (): void {
        $case = purgeCase();

        inventoryCall($this, $case['owner'], 'DELETE', "/api/v1/inventories/{$case['tools']->ulid}/fields/{$case['photos']->ulid}")->assertNoContent();

        $left = ['plain', 'document', 'saw', 'novel'];
        $kept = array_map(fn (string $key): Attachment => $case['files'][$key], $left);

        expect(storedFiles())->toBe(purgePaths($case['foreign'], ...$kept))
            ->and(Attachment::query()->whereKey($case['files']['photo']->id)->exists())->toBeFalse()
            ->and(Attachment::query()->count())->toBe(5)
            ->and(Item::query()->findOrFail($case['hammer']->id)->values)->toEqual([$case['documents']->ulid => [$case['files']['document']->ulid]])
            ->and(purgeStorage($case['workspace']))->toBe(['stored' => array_sum(array_map(fn (Attachment $attachment): int => $attachment->size, $kept)), 'counted' => array_sum(array_map(fn (Attachment $attachment): int => $attachment->size, $kept))]);
    });

    it('deletes the files of a file field too, and leaves the plain attachments of the same item', function (): void {
        $case = purgeCase();

        app(InventoryFields::class)->delete($case['tools'], $case['documents']);

        expect(Attachment::query()->whereKey($case['files']['document']->id)->exists())->toBeFalse()
            ->and(Attachment::query()->whereKey($case['files']['plain']->id)->exists())->toBeTrue()
            ->and(storedFiles())->not->toContain($case['files']['document']->path)
            ->and(storedFiles())->toContain($case['files']['plain']->path);
    });

    it('takes the workspace lock first for such a field, and not for any other', function (): void {
        $case = purgeCase();
        $text = fieldIn($case['tools'], 'text', 'Name');
        $locked = fn (Closure $write): array => array_map(
            fn (string $statement): string => preg_match('/from\s+["`]?(\w+)["`]?/i', $statement, $found) === 1 ? $found[1] : '?',
            array_values(array_filter(statementsOf($write), fn (string $statement): bool => str_starts_with(strtolower($statement), 'select') && preg_match('/for update|for no key update/i', $statement) === 1)),
        );

        expect($locked(fn () => app(InventoryFields::class)->delete($case['tools'], $text)))->toBe(['inventories'])
            ->and($locked(fn () => app(InventoryFields::class)->delete($case['tools'], $case['documents'])))->toBe(['workspaces', 'inventories']);
    });

    it('keeps everything when the deletion is rolled back', function (): void {
        $case = purgeCase();
        $before = [Attachment::query()->count(), count(storedFiles()), purgeStorage($case['workspace'])];

        expect(fn () => DB::transaction(function () use ($case): void {
            app(InventoryFields::class)->delete($case['tools'], $case['photos']);

            throw new RuntimeException('The request failed afterwards.');
        }))->toThrow(RuntimeException::class);

        expect([Attachment::query()->count(), count(storedFiles()), purgeStorage($case['workspace'])])->toBe($before);
    });

    it('does nothing about files for a field that holds none', function (): void {
        $case = purgeCase();
        $empty = fieldIn($case['books'], 'photo', 'Covers');
        Queue::fake();

        app(InventoryFields::class)->delete($case['books'], $empty);

        Queue::assertNothingPushed();
    });
});

describe('deleting an inventory', function (): void {
    it('deletes its folder, its rows and its history, counts its bytes out of the workspace, and touches no other inventory', function (): void {
        $case = purgeCase();

        inventoryCall($this, $case['owner'], 'DELETE', "/api/v1/inventories/{$case['tools']->ulid}")->assertNoContent();

        expect(storedFiles())->toBe(purgePaths($case['files']['novel'], $case['foreign']))
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->directories("workspaces/{$case['workspace']->ulid}/inventories"))->toBe(["workspaces/{$case['workspace']->ulid}/inventories/{$case['books']->ulid}"])
            ->and(Attachment::query()->pluck('inventory_id')->unique()->sort()->values()->all())->toBe(collect([$case['books']->id, $case['foreign']->inventory_id])->sort()->values()->all())
            ->and(ItemRevision::query()->where('inventory_id', $case['tools']->id)->count())->toBe(0)
            ->and(purgeStorage($case['workspace']))->toBe(['stored' => $case['files']['novel']->size, 'counted' => $case['files']['novel']->size]);
    });

    it('does it through the model too, whichever way the inventory is deleted', function (): void {
        $case = purgeCase();

        $case['tools']->delete();

        expect(storedFiles())->toBe(purgePaths($case['files']['novel'], $case['foreign']));
    });

    it('deletes nothing from the disk when the deletion itself fails, outside any transaction too', function (): void {
        $case = purgeCase();
        $before = storedFiles();
        purgeRefuseDeletionFrom('inventories');

        expect(fn () => $case['tools']->delete())->toThrow(RuntimeException::class);

        expect(storedFiles())->toBe($before)
            ->and(Inventory::query()->whereKey($case['tools']->id)->exists())->toBeTrue();
    });

    it('deletes the folder only once the row is gone, and keeps it when a transaction around a bare deletion is rolled back', function (): void {
        $case = purgeCase();
        $before = storedFiles();
        $gone = [];
        DB::listen(function ($query) use (&$gone, $case): void {
            if (str_starts_with(strtolower($query->sql), 'delete from') && str_contains($query->sql, 'inventories')) {
                $gone[] = count(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles("workspaces/{$case['workspace']->ulid}/inventories/{$case['tools']->ulid}"));
            }
        });

        expect(fn () => DB::transaction(function () use ($case): void {
            $case['tools']->delete();

            throw new RuntimeException('The request failed afterwards.');
        }))->toThrow(RuntimeException::class);

        // The files were still there when the DELETE ran, and are still there after the rollback.
        expect($gone)->not->toBe([])
            ->and($gone[0])->toBeGreaterThan(0)
            ->and(storedFiles())->toBe($before);

        Inventory::query()->findOrFail($case['tools']->id)->delete();

        expect(storedFiles())->toBe(purgePaths($case['files']['novel'], $case['foreign']));
    });

    it('keeps everything when the deletion is rolled back', function (): void {
        $case = purgeCase();
        $before = [Attachment::query()->count(), count(storedFiles()), purgeStorage($case['workspace'])];

        expect(fn () => DB::transaction(function () use ($case): void {
            app(Inventories::class)->delete($case['tools']);

            throw new RuntimeException('The request failed afterwards.');
        }))->toThrow(RuntimeException::class);

        expect([Attachment::query()->count(), count(storedFiles()), purgeStorage($case['workspace'])])->toBe($before)
            ->and(Inventory::query()->whereKey($case['tools']->id)->exists())->toBeTrue();
    });

    it('takes the workspace lock before the inventory', function (): void {
        $case = purgeCase();

        $tables = array_map(
            fn (string $statement): string => preg_match('/from\s+["`]?(\w+)["`]?/i', $statement, $found) === 1 ? $found[1] : '?',
            array_values(array_filter(statementsOf(fn () => app(Inventories::class)->delete($case['books'])), fn (string $statement): bool => str_starts_with(strtolower($statement), 'select') && preg_match('/for update|for no key update/i', $statement) === 1)),
        );

        expect($tables)->toBe(['workspaces', 'inventories']);
    });
});

describe('deleting a workspace', function (): void {
    it('deletes the folder of the workspace, with every inventory in it, and no other workspace', function (): void {
        $case = purgeCase();
        $other = app(Workspaces::class)->create($case['owner'], 'Spare');

        inventoryCall($this, $case['owner'], 'DELETE', "/api/v1/workspaces/{$case['workspace']->ulid}")->assertNoContent();

        expect(storedFiles())->toBe(purgePaths($case['foreign']))
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->directories('workspaces'))->toBe(["workspaces/{$case['theirs']->workspaces()->sole()->ulid}"])
            ->and(Attachment::query()->count())->toBe(1)
            // The item of the stranger: its creation, and the file added to it.
            ->and(ItemRevision::query()->count())->toBe(2)
            ->and($other->fresh())->not->toBeNull();
    });

    it('does it through the service and the model too', function (): void {
        $case = purgeCase();

        app(Workspaces::class)->delete($case['owner'], $case['workspace']);

        expect(storedFiles())->toBe(purgePaths($case['foreign']));
    });

    it('deletes nothing from the disk when the deletion itself fails, outside any transaction too', function (): void {
        $case = purgeCase();
        $before = storedFiles();
        purgeRefuseDeletionFrom('workspaces');

        expect(fn () => $case['workspace']->delete())->toThrow(RuntimeException::class);

        expect(storedFiles())->toBe($before)
            ->and(Workspace::query()->whereKey($case['workspace']->id)->exists())->toBeTrue();
    });

    it('keeps the files when a transaction around a bare deletion of the model is rolled back', function (): void {
        $case = purgeCase();
        $before = storedFiles();

        expect(fn () => DB::transaction(function () use ($case): void {
            $case['workspace']->delete();

            throw new RuntimeException('The request failed afterwards.');
        }))->toThrow(RuntimeException::class);

        expect(storedFiles())->toBe($before);
    });

    it('empties the folder on the disk of today even when no file of the workspace is recorded: an upload whose row is not committed yet is not orphaned', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $item = itemIn($inventory);
        $unrecorded = AttachmentStorage::itemDirectory($team['workspace']->ulid, $inventory->ulid, $item->ulid).'/'.strtolower((string) Str::ulid());
        Storage::disk(ATTACHMENT_TEST_DISK)->put($unrecorded, 'written for an upload that is still in flight');
        Storage::disk(ATTACHMENT_TEST_DISK)->put('workspaces/another-one/inventories/i/items/a/file', 'x');

        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/workspaces/{$team['workspace']->ulid}")->assertNoContent();

        expect(storedFiles())->toBe(['workspaces/another-one/inventories/i/items/a/file']);
    });

    it('keeps the files when the deletion is rolled back', function (): void {
        $case = purgeCase();
        $before = count(storedFiles());

        expect(fn () => DB::transaction(function () use ($case): void {
            app(Workspaces::class)->delete($case['owner'], $case['workspace']);

            throw new RuntimeException('The request failed afterwards.');
        }))->toThrow(RuntimeException::class);

        expect(count(storedFiles()))->toBe($before)
            ->and(Attachment::query()->count())->toBe(6);
    });
});

describe('deleting an account', function (): void {
    it('deletes the files of the workspaces nobody else belongs to, and keeps those of a shared workspace', function (): void {
        $case = purgeCase();
        $alone = $case['theirs'];

        $alone->delete();

        // The stranger's own workspace went; the team's workspace, shared, is untouched.
        expect(storedFiles())->toBe(purgePaths(...array_values($case['files'])))
            ->and(Attachment::query()->count())->toBe(5);
    });

    it('leaves the files of a shared workspace the account only belonged to, and forgets who uploaded them', function (): void {
        $case = purgeCase();
        $theirs = attachmentTo($case['books'], $case['novel'], attachmentPdf('theirs.pdf'), $case['editor']);

        $case['editor']->delete();

        expect(storedFiles())->toBe(purgePaths(...array_values($case['files']), ...[$case['foreign'], $theirs]))
            ->and($theirs->fresh()?->uploaded_by)->toBeNull()
            ->and(Attachment::query()->whereNull('uploaded_by')->count())->toBe(1)
            ->and(Workspace::query()->whereKey($case['workspace']->id)->exists())->toBeTrue();
    });

    it('is the same when an administrator deletes the account', function (): void {
        $case = purgeCase();
        $admin = inventoryPerson('Admin');
        $admin->forceFill(['is_admin' => true])->save();

        app(UserAdministration::class)->delete($admin, $case['theirs']);

        expect(storedFiles())->toBe(purgePaths(...array_values($case['files'])));
    });

    it('deletes the files of every workspace the account owned alone', function (): void {
        $case = purgeCase();
        $solo = inventoryPerson('Solo');
        $second = app(Workspaces::class)->create($solo, 'Second');
        $inventory = inventoryIn($second, 'Mine');
        $mine = attachmentTo($inventory, itemIn($inventory), attachmentPdf(), $solo);

        expect(storedFiles())->toContain($mine->path);

        $solo->delete();

        expect(storedFiles())->not->toContain($mine->path)
            ->and(storedFiles())->toHaveCount(count(purgePaths(...array_values($case['files']), ...[$case['foreign']])));
    });
});

describe('the database', function (): void {
    it('deletes everything that hangs from a workspace when it is deleted behind the application\'s back, whatever the number of inventories', function (): void {
        $case = purgeCase();

        DB::table('workspaces')->where('id', $case['workspace']->id)->delete();

        expect(Inventory::query()->where('workspace_id', $case['workspace']->id)->count())->toBe(0)
            ->and(Item::query()->whereIn('inventory_id', [$case['tools']->id, $case['books']->id])->count())->toBe(0)
            ->and(Attachment::query()->whereIn('inventory_id', [$case['tools']->id, $case['books']->id])->count())->toBe(0)
            ->and(ItemRevision::query()->whereIn('inventory_id', [$case['tools']->id, $case['books']->id])->count())->toBe(0)
            ->and(DB::table('inventory_fields')->whereIn('inventory_id', [$case['tools']->id, $case['books']->id])->count())->toBe(0)
            ->and(Item::query()->count())->toBe(1)
            ->and(Attachment::query()->count())->toBe(1);
    });

    it('deletes the inventories of a workspace one after the other, with nothing left behind', function (): void {
        $case = purgeCase();
        $third = inventoryIn($case['workspace'], 'Third');
        attachmentTo($third, itemIn($third), attachmentPdf(), $case['owner']);

        DB::table('inventories')->where('workspace_id', $case['workspace']->id)->delete();

        expect(Item::query()->count())->toBe(1)
            ->and(Attachment::query()->count())->toBe(1)
            ->and(ItemRevision::query()->count())->toBe(2);
    });
});

describe('the storage counter of every workspace', function (): void {
    it('is added up again by the upgrade, and the ones that were wrong are corrected and logged', function (): void {
        $case = purgeCase();
        $empty = inventoryTeam()['workspace'];
        $right = $case['files']['plain']->size + $case['files']['photo']->size + $case['files']['document']->size + $case['files']['saw']->size + $case['files']['novel']->size;
        DB::table('workspaces')->where('id', $case['workspace']->id)->update(['storage_used' => 5]);
        DB::table('workspaces')->where('id', $empty->id)->update(['storage_used' => 123]);
        Log::spy();

        $corrected = app(DerivedData::class)->recountStorage();

        expect($corrected)->toBe(2)
            ->and(purgeStorage($case['workspace']))->toBe(['stored' => $right, 'counted' => $right])
            ->and(purgeStorage($empty))->toBe(['stored' => 0, 'counted' => 0]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'storage counter') && isset($context['stored'], $context['counted']))->twice();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ($context['workspace'] ?? null) === $case['workspace']->ulid && $context['stored'] === 5 && $context['counted'] === $right)->once();
    });

    it('says nothing and writes nothing when every counter is right', function (): void {
        $case = purgeCase();
        $updated = DB::table('workspaces')->where('id', $case['workspace']->id)->value('updated_at');
        Log::spy();

        expect(app(DerivedData::class)->recountStorage())->toBe(0)
            ->and(DB::table('workspaces')->where('id', $case['workspace']->id)->value('updated_at'))->toBe($updated);
        Log::shouldNotHaveReceived('warning');
    });

    it('locks the workspace, which every upload and removal of a file locks before anything else', function (): void {
        $case = purgeCase();

        $locks = array_values(array_filter(statementsOf(fn () => app(DerivedData::class)->recountStorage()), fn (string $statement): bool => preg_match('/for update|for no key update/i', $statement) === 1));

        expect($locks)->not->toBe([])
            ->and($locks[0])->toContain('workspaces');
    });
});
