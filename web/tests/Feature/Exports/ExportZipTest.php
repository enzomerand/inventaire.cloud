<?php

declare(strict_types=1);

use App\Inventories\Exports\ExportError;
use App\Inventories\Exports\ExportFormat;
use App\Inventories\Exports\ExportNames;
use App\Inventories\Exports\ExportStatus;
use App\Models\Attachment;
use App\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/exports.php';

/*
 * The zip of the files of an export: a folder for each item, named after its first text and its ULID, the files as they were
 * uploaded, every name cleaned up so that nothing can be unzipped outside the folder, and the option that says which files.
 */

uses(RefreshDatabase::class);

/**
 * The entries of a zip, with their contents and how they are stored.
 *
 * @return array<string, array{contents: string, method: int}>
 */
function zipEntries(Export $export): array
{
    $zip = new ZipArchive;
    expect($zip->open(exportLocalPath($export)))->toBeTrue();
    $entries = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        $entries[(string) $stat['name']] = ['contents' => (string) $zip->getFromIndex($index), 'method' => (int) $stat['comp_method']];
    }

    $zip->close();
    ksort($entries);

    return $entries;
}

/**
 * The fixture inventory with files: two photos and a document on the drill, a plain attachment on the formulas item.
 *
 * @return array<string, mixed>
 */
function zipCase(): array
{
    $case = exportFixture();
    $owner = $case['team']['owner'];
    $inventory = $case['inventory'];
    $case['files'] = [
        'front' => attachmentTo($inventory, $case['items']['drill'], attachmentImage('front.png'), $owner, $case['fields']['photos']),
        'back' => attachmentTo($inventory, $case['items']['drill'], attachmentImage('back.png', 'png', 60, 30), $owner, $case['fields']['photos']),
        'manual' => attachmentTo($inventory, $case['items']['drill'], attachmentPdf('manual.pdf'), $owner, $case['fields']['docs']),
        'plain' => attachmentTo($inventory, $case['items']['formulas'], attachmentPdf('receipt.pdf'), $owner),
    ];

    return $case;
}

describe('the entries', function (): void {
    it('are the photos in a folder for each item, named after its text and its ULID', function (): void {
        $case = zipCase();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Zip));
        $folder = 'Drill 18V - '.$case['items']['drill']->ulid;

        expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error)
            ->and(array_keys(zipEntries($export)))->toBe(["{$folder}/back.png", "{$folder}/front.png"])
            ->and($export->items_count)->toBe(1)
            ->and($export->mime)->toBe('application/zip')
            ->and($export->file_name)->toEndWith('.zip');
    });

    it('hold the files as they were uploaded, stored and not compressed', function (): void {
        $case = zipCase();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Zip));
        $entries = zipEntries($export);
        $folder = 'Drill 18V - '.$case['items']['drill']->ulid;

        expect($entries["{$folder}/front.png"]['contents'])->toBe(attachmentImageBytes('png', 40, 20))
            ->and($entries["{$folder}/back.png"]['contents'])->toBe(attachmentImageBytes('png', 60, 30))
            ->and($entries["{$folder}/front.png"]['method'])->toBe(ZipArchive::CM_STORE)
            ->and($export->size)->toBe(filesize(exportLocalPath($export)));
    });

    it('are every file of the items with the option all, the plain attachments and the documents too', function (): void {
        $case = zipCase();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Zip, ['options' => ['files' => 'all']]));
        $drill = 'Drill 18V - '.$case['items']['drill']->ulid;
        $formulas = '=1+1 - '.$case['items']['formulas']->ulid;

        expect(array_keys(zipEntries($export)))->toBe(["{$formulas}/receipt.pdf", "{$drill}/back.png", "{$drill}/front.png", "{$drill}/manual.pdf"])
            ->and($export->items_count)->toBe(2);
    });

    it('are the photos when the option says anything else', function (): void {
        $case = zipCase();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Zip, ['options' => ['files' => 'everything']]));

        expect(count(zipEntries($export)))->toBe(2);
    });

    it('name an item with no text "Item"', function (): void {
        $case = zipCase();
        $bare = inventoryIn($case['team']['workspace'], 'Bare');
        $photos = fieldIn($bare, 'photo', 'Photos');
        $item = itemIn($bare);
        attachmentTo($bare, $item, attachmentImage('only.png'), $case['team']['owner'], $photos);
        $export = exportRun(exportAsked($bare, $case['team']['owner'], ExportFormat::Zip));

        expect(array_keys(zipEntries($export)))->toBe(["Item - {$item->ulid}/only.png"]);
    });

    it('take the first text that has a value, in the order of the fields, as the label', function (): void {
        $case = exportFixture();
        $inventory = inventoryIn($case['team']['workspace'], 'Labels');
        $empty = fieldIn($inventory, 'text', 'Reference');
        $name = fieldIn($inventory, 'text', 'Name');
        $other = fieldIn($inventory, 'text', 'Other');
        $photos = fieldIn($inventory, 'photo', 'Photos');
        $item = itemIn($inventory, [$name->ulid => 'Hammer', $other->ulid => 'Second']);
        attachmentTo($inventory, $item, attachmentImage('h.png'), $case['team']['owner'], $photos);
        $export = exportRun(exportAsked($inventory, $case['team']['owner'], ExportFormat::Zip));

        expect(array_keys(zipEntries($export)))->toBe(["Hammer - {$item->ulid}/h.png"]);
        unset($empty);
    });

    it('keep two items with the same label apart by their ULID', function (): void {
        $case = exportFixture();
        $inventory = inventoryIn($case['team']['workspace'], 'Twins');
        $name = fieldIn($inventory, 'text', 'Name');
        $photos = fieldIn($inventory, 'photo', 'Photos');
        $one = itemIn($inventory, [$name->ulid => 'Same']);
        $two = itemIn($inventory, [$name->ulid => 'Same']);
        attachmentTo($inventory, $one, attachmentImage('a.png'), $case['team']['owner'], $photos);
        attachmentTo($inventory, $two, attachmentImage('a.png'), $case['team']['owner'], $photos);
        $export = exportRun(exportAsked($inventory, $case['team']['owner'], ExportFormat::Zip));

        expect(array_keys(zipEntries($export)))->toBe(collect(["Same - {$one->ulid}/a.png", "Same - {$two->ulid}/a.png"])->sort()->values()->all());
    });
});

describe('the names', function (): void {
    it('are cleaned up so that nothing can be unzipped outside the folder', function (): void {
        $case = exportFixture();
        $inventory = inventoryIn($case['team']['workspace'], 'Hostile');
        $name = fieldIn($inventory, 'text', 'Name');
        $photos = fieldIn($inventory, 'photo', 'Photos', ['max_files' => 10]);
        $item = itemIn($inventory, [$name->ulid => '../../etc/passwd: "evil"|<tag>?*']);

        foreach (['../../../escape.png', 'a:b*c?d.png', '..\\..\\win.png', '/abs/olute.png', '.hidden.png'] as $upload) {
            attachmentTo($inventory, $item, attachmentImage($upload), $case['team']['owner'], $photos);
        }

        $export = exportRun(exportAsked($inventory, $case['team']['owner'], ExportFormat::Zip));
        $names = array_keys(zipEntries($export));

        expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error)
            ->and($names)->toHaveCount(5);

        foreach ($names as $entry) {
            expect($entry)->not->toContain('..')
                ->not->toStartWith('/')
                ->not->toContain('\\')
                ->not->toMatch('/[:*?"<>|\x00-\x1f]/')
                // One folder and one file: the label and the name never add a level.
                ->and(substr_count($entry, '/'))->toBe(1);
        }
    });

    it('are at most 200 bytes for a file and 150 for the label of a folder, the extension of a file kept', function (): void {
        $case = exportFixture();
        $inventory = inventoryIn($case['team']['workspace'], 'Long');
        $name = fieldIn($inventory, 'text', 'Name', ['max_length' => 300]);
        $photos = fieldIn($inventory, 'photo', 'Photos');
        $item = itemIn($inventory, [$name->ulid => str_repeat('label ', 40)]);
        attachmentTo($inventory, $item, attachmentImage(str_repeat('file', 100).'.png'), $case['team']['owner'], $photos);
        $export = exportRun(exportAsked($inventory, $case['team']['owner'], ExportFormat::Zip));
        [$folder, $file] = explode('/', array_keys(zipEntries($export))[0]);

        expect(strlen($file))->toBe(200)
            ->and($file)->toEndWith('.png')
            ->and(strlen($folder))->toBeLessThanOrEqual(150 + 3 + 26);
    });

    it('are counted in bytes, so that a name in Chinese or Japanese is cut on a character and never in the middle of one', function (): void {
        $case = exportFixture();
        $inventory = inventoryIn($case['team']['workspace'], 'Long');
        $name = fieldIn($inventory, 'text', 'Name', ['max_length' => 300]);
        $photos = fieldIn($inventory, 'photo', 'Photos', ['max_files' => 3]);
        $item = itemIn($inventory, [$name->ulid => str_repeat('日本語の部品', 30)]);
        attachmentTo($inventory, $item, attachmentImage(str_repeat('写真', 100).'.png'), $case['team']['owner'], $photos);
        attachmentTo($inventory, $item, attachmentImage(str_repeat('写真', 100).'.png', 'png', 60, 30), $case['team']['owner'], $photos);
        $export = exportRun(exportAsked($inventory, $case['team']['owner'], ExportFormat::Zip));
        $entries = array_keys(zipEntries($export));

        expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error)
            ->and($entries)->toHaveCount(2);

        foreach ($entries as $entry) {
            [$folder, $file] = explode('/', $entry);

            // 3 bytes a character: 150 bytes is 50 characters, 200 bytes 66 characters and the extension.
            expect(mb_check_encoding($entry, 'UTF-8'))->toBeTrue()
                ->and(strlen($folder))->toBeLessThanOrEqual(150 + 3 + 26)
                ->and(strlen($file))->toBeLessThanOrEqual(200)
                ->and($file)->toEndWith('.png')
                ->and($folder)->toStartWith(str_repeat('日本語の部品', 8).'日本')
                ->and(mb_strlen($folder))->toBeLessThan(80);
        }

        // The second file has the number of its duplicate in the name, which is still inside the 200 bytes.
        expect(collect($entries)->map(fn (string $entry): string => explode('/', $entry)[1])->contains(fn (string $file): bool => str_ends_with($file, ' (2).png')))->toBeTrue();
    });

    it('cut a name in bytes whatever its characters are: the name stays valid, and the duplicate number counts in the length', function (): void {
        $long = str_repeat('日', 100).'.jpg';
        $cut = ExportNames::entry($long, 'Fallback');
        $taken = [];
        $first = ExportNames::unique($cut, $taken);
        $second = ExportNames::unique($cut, $taken);
        $tenth = $second;

        for ($number = 3; $number <= 10; $number++) {
            $tenth = ExportNames::unique($cut, $taken);
        }

        expect(strlen($cut))->toBeLessThanOrEqual(200)
            ->and(mb_check_encoding($cut, 'UTF-8'))->toBeTrue()
            ->and($cut)->toEndWith('日.jpg')
            // 196 bytes of 日 and the extension: the 65th character is cut off, not split.
            ->and(mb_strlen($cut))->toBe(65 + 4)
            ->and($first)->toBe($cut)
            ->and(strlen($second))->toBeLessThanOrEqual(200)
            ->and($second)->toEndWith(' (2).jpg')
            ->and(mb_check_encoding($second, 'UTF-8'))->toBeTrue()
            ->and($tenth)->toEndWith(' (10).jpg')
            ->and(strlen($tenth))->toBeLessThanOrEqual(200)
            ->and(mb_check_encoding($tenth, 'UTF-8'))->toBeTrue()
            ->and(strlen(ExportNames::entry(str_repeat('日', 100), 'Item', ExportNames::FOLDER_LABEL_MAX_BYTES)))->toBe(150)
            // Emoji take four bytes: no split sequence either.
            ->and(mb_check_encoding(ExportNames::entry(str_repeat('🔧', 100), 'Item', 150), 'UTF-8'))->toBeTrue()
            ->and(strlen(ExportNames::entry(str_repeat('🔧', 100), 'Item', 150)))->toBe(148);
    });

    it('get (2), (3) before the extension when a name is already used in the folder', function (): void {
        $taken = [];

        expect(ExportNames::unique('photo.jpg', $taken))->toBe('photo.jpg')
            ->and(ExportNames::unique('photo.jpg', $taken))->toBe('photo (2).jpg')
            ->and(ExportNames::unique('PHOTO.JPG', $taken))->toBe('PHOTO (3).JPG')
            ->and(ExportNames::unique('notes', $taken))->toBe('notes')
            ->and(ExportNames::unique('notes', $taken))->toBe('notes (2)')
            ->and(ExportNames::unique('.env', $taken))->toBe('.env')
            ->and(ExportNames::unique('.env', $taken))->toBe('.env (2)');
    });

    it('are the same name twice in a zip as two files', function (): void {
        $case = exportFixture();
        $inventory = inventoryIn($case['team']['workspace'], 'Dupes');
        $photos = fieldIn($inventory, 'photo', 'Photos', ['max_files' => 3]);
        $item = itemIn($inventory);

        foreach ([1, 2, 3] as $number) {
            attachmentTo($inventory, $item, attachmentImage('photo.png', 'png', 20 + $number, 10), $case['team']['owner'], $photos);
        }

        $export = exportRun(exportAsked($inventory, $case['team']['owner'], ExportFormat::Zip));
        $entries = zipEntries($export);

        expect(array_map(fn (string $entry): string => explode('/', $entry)[1], array_keys($entries)))->toBe(['photo (2).png', 'photo (3).png', 'photo.png'])
            // Nothing was overwritten: three different images.
            ->and(count(array_unique(array_column($entries, 'contents'))))->toBe(3);
    });

    it('cleans a name the way each part of the application needs', function (string $name, string $expected): void {
        expect(ExportNames::entry($name, 'Fallback'))->toBe($expected);
    })->with([
        'plain' => ['Drill 18V', 'Drill 18V'],
        'separators' => ['a/b\\c', 'a_b_c'],
        'forbidden characters' => ['a:b*c?d"e<f>g|h', 'a_b_c_d_e_f_g_h'],
        'dots' => ['..', 'Fallback'],
        'dotted' => ['a..b', 'a.b'],
        'only a separator' => ['/', '_'],
        'controls' => ["a\x00b\x1Fc\u{200B}d", 'abcd'],
        'spaces around' => ['  name.  ', 'name'],
        'a name that is just file' => ['file', 'file'],
    ]);
});

describe('what cannot be added', function (): void {
    it('is an empty zip a reader accepts, when no item has a file', function (): void {
        $case = exportFixture();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Zip));
        $zip = new ZipArchive;

        expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error)
            ->and($zip->open(exportLocalPath($export)))->toBeTrue()
            ->and($zip->numFiles)->toBe(0)
            ->and($export->items_count)->toBe(0)
            ->and($export->size)->toBe(22);
    });

    it('leaves out a file that is gone from its disk, logs it, and goes on with the others', function (): void {
        $case = zipCase();
        Storage::disk(ATTACHMENT_TEST_DISK)->delete($case['files']['front']->path);
        Log::spy();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Zip));

        expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error)
            ->and(array_keys(zipEntries($export)))->toBe(['Drill 18V - '.$case['items']['drill']->ulid.'/back.png']);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'could not be read for a zip export') && $context['attachment'] === $case['files']['front']->ulid)->once();
    });

    it('copies the file to a scratch file first when its disk is not a local one, and deletes the copy', function (): void {
        $case = zipCase();
        config(['filesystems.disks.'.ATTACHMENT_TEST_DISK.'.driver' => 's3']);
        $scratch = fn (): array => glob(storage_path('framework/exports/*')) ?: [];
        $before = $scratch();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Zip));

        expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error)
            ->and(array_keys(zipEntries($export)))->toHaveCount(2)
            ->and(zipEntries($export)['Drill 18V - '.$case['items']['drill']->ulid.'/front.png']['contents'])->toBe(attachmentImageBytes('png', 40, 20))
            ->and($scratch())->toBe($before);
    });
});

describe('what goes wrong with a file', function (): void {
    /**
     * The scratch files in the folder of the exports.
     *
     * @return list<string>
     */
    function zipScratch(): array
    {
        return glob(storage_path('framework/exports/*')) ?: [];
    }

    it('fails the export, and leaves nothing, when a file is there but cannot be read: a zip that silently lacks files would pass for a whole one', function (): void {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('File permissions do not restrict root.');
        }

        $case = zipCase();
        // The fake disk is a folder of this machine: the files are added from where they are.
        config(['filesystems.disks.'.ATTACHMENT_TEST_DISK.'.driver' => 'local']);
        $local = Storage::disk(ATTACHMENT_TEST_DISK)->path($case['files']['front']->path);
        chmod($local, 0000);
        Log::spy();
        $before = zipScratch();

        try {
            $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Zip));
        } finally {
            chmod($local, 0644);
        }

        expect($export->status)->toBe(ExportStatus::Failed)
            ->and($export->error)->toBe(ExportError::GenerationFailed->value)
            ->and($export->path)->toBeNull()
            ->and(array_filter(storedFiles(), fn (string $file): bool => str_contains($file, '/exports/')))->toBe([])
            ->and(zipScratch())->toBe($before);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($context['exception']->getMessage(), 'cannot be read'))->once();
        Log::shouldNotHaveReceived('warning');
    });

    it('fails the export when its disk does not answer, instead of leaving every file out', function (): void {
        $case = zipCase();
        attachmentDiskProxy()->shouldReceive('exists')->andThrow(new RuntimeException('The disk cannot be reached.'));

        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Zip));

        expect($export->status)->toBe(ExportStatus::Failed)
            ->and($export->error)->toBe(ExportError::GenerationFailed->value);
    });

    it('fails the export when a file of another disk cannot be opened although it is there', function (): void {
        $case = zipCase();
        config(['filesystems.disks.'.ATTACHMENT_TEST_DISK.'.driver' => 's3']);
        attachmentDiskProxy()->shouldReceive('readStream')->andReturn(null);
        $before = zipScratch();

        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Zip));

        expect($export->status)->toBe(ExportStatus::Failed)
            ->and(zipScratch())->toBe($before);
    });

    it('fails the export when the copy of a file of another disk comes out short, and deletes the copies', function (): void {
        $case = zipCase();
        config(['filesystems.disks.'.ATTACHMENT_TEST_DISK.'.driver' => 's3']);
        // The disk gave less than what was recorded when the file was uploaded: a connection that dropped.
        Attachment::query()->whereKey($case['files']['back']->id)->update(['size' => $case['files']['back']->size + 10]);
        Log::spy();
        $before = zipScratch();

        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Zip));

        expect($export->status)->toBe(ExportStatus::Failed)
            ->and($export->path)->toBeNull()
            ->and(array_filter(storedFiles(), fn (string $file): bool => str_contains($file, '/exports/')))->toBe([])
            ->and(zipScratch())->toBe($before);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($context['exception']->getMessage(), 'was not copied whole'))->once();
    });

    it('fails the export with a clear error when a file of the local disk is deleted after it was added and before the zip is written', function (): void {
        $case = zipCase();
        config(['filesystems.disks.'.ATTACHMENT_TEST_DISK.'.driver' => 'local']);
        $real = Storage::disk(ATTACHMENT_TEST_DISK);
        $looked = [];
        // The first file is added when the second one is looked at, and it is gone when the zip is written.
        attachmentDiskProxy()->shouldReceive('exists')->andReturnUsing(function (string $path) use ($real, &$looked): bool {
            if (count($looked) === 1) {
                $real->delete($looked[0]);
            }

            $looked[] = $path;

            return $real->exists($path);
        });
        Log::spy();
        $before = zipScratch();

        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Zip));

        expect($export->status)->toBe(ExportStatus::Failed)
            ->and($export->error)->toBe(ExportError::GenerationFailed->value)
            ->and($export->path)->toBeNull()
            ->and(array_filter(storedFiles(), fn (string $file): bool => str_contains($file, '/exports/')))->toBe([])
            ->and(zipScratch())->toBe($before);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($context['exception']->getMessage(), 'The zip of the export could not be written'))->once();
    });
});
