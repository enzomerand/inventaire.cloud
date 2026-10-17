<?php

declare(strict_types=1);

use App\Inventories\Exceptions\ImportFileException;
use App\Inventories\Imports\ImportCsv;
use App\Inventories\Imports\ImportFiles;
use App\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FailingReadStream;
use Tests\Support\FailingWriteStream;

require_once __DIR__.'/../../Support/imports.php';

/*
 * Copying the files of an import: a read or a write that fails is an error, never a copy that stops short and is read as a file that
 * ends there, and a stream that was opened is closed whatever happens next.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
    stream_wrapper_register('importwrite', FailingWriteStream::class);
    stream_wrapper_register('importread', FailingReadStream::class);
    FailingWriteStream::$files = ['importwrite://source' => str_repeat('x', 30000)];
    FailingWriteStream::$failAllWrites = false;
    FailingWriteStream::$failNextWrite = false;
    FailingReadStream::$bytes = 5000;
    FailingReadStream::$stall = false;
});

afterEach(function (): void {
    stream_wrapper_unregister('importwrite');
    stream_wrapper_unregister('importread');
    FailingWriteStream::$failAllWrites = false;
    FailingWriteStream::$failNextWrite = false;
    FailingReadStream::$stall = false;
});

/**
 * Whether doing something fails the way a file that cannot be copied does.
 */
function importUnreadable(Closure $copy): void
{
    try {
        $copy();
    } catch (ImportFileException $failure) {
        expect($failure->reason)->toBe(ImportFileException::UNREADABLE);

        return;
    }

    throw new RuntimeException('The copy did not fail.');
}

describe('converting a text to UTF-8', function (): void {
    it('writes all of it', function (string $encoding, string $input, string $expected): void {
        $source = fopen('php://memory', 'w+b');
        $target = fopen('php://memory', 'w+b');
        fwrite($source, $input);
        rewind($source);

        ImportCsv::transcode($source, $target, $encoding);
        rewind($target);

        expect(stream_get_contents($target))->toBe($expected);
    })->with([
        'Windows-1252' => ['windows-1252', "Pr\xE9nom;Ville\r\n", "Prénom;Ville\r\n"],
        'UTF-16 with its byte order mark' => ['utf-16', "\xFF\xFE".mb_convert_encoding("Né\r\n", 'UTF-16LE', 'UTF-8'), "Né\r\n"],
    ]);

    it('converts a file that ends exactly where a block of its reading does', function (): void {
        // 16 bytes a line, and 2 MiB: two blocks of 1 MiB, the second one the last, and nothing after it.
        $path = importFile(str_repeat("Pr\xE9nom;Ville-a\r\n", 131072), 'csv');
        expect(filesize($path))->toBe(2097152);
        $source = fopen($path, 'rb');
        $target = fopen('php://memory', 'w+b');

        ImportCsv::transcode($source, $target, 'windows-1252');
        rewind($target);

        expect(stream_get_contents($target))->toBe(str_repeat("Prénom;Ville-a\r\n", 131072));
    });

    it('fails when the copy cannot be written whole (a disk that is full)', function (string $encoding): void {
        $source = fopen('importwrite://source', 'rb');
        $target = fopen('importwrite://target', 'wb');
        FailingWriteStream::$failAllWrites = true;

        importUnreadable(fn () => ImportCsv::transcode($source, $target, $encoding));
    })->with(['windows-1252', 'utf-16']);

    it('fails, and does not loop for ever, when the text cannot be read to its end', function (string $encoding): void {
        $source = fopen('importread://source', 'rb');
        $target = fopen('php://memory', 'w+b');

        importUnreadable(fn () => ImportCsv::transcode($source, $target, $encoding));
    })->with(['windows-1252', 'utf-16']);

    it('fails, and does not loop for ever, when a read gives nothing and the end of the text is not reached', function (string $encoding): void {
        FailingReadStream::$stall = true;
        $source = fopen('importread://source', 'rb');
        $target = fopen('php://memory', 'w+b');

        importUnreadable(fn () => ImportCsv::transcode($source, $target, $encoding));
    })->with(['windows-1252', 'utf-16']);

    it('is a failure of the file when the text of a CSV in another encoding cannot be read', function (): void {
        // The file is found to be Windows-1252 as it is told so; its reads then fail after a few bytes.
        importUnreadable(fn () => ImportCsv::prepare('importread://source', 'windows-1252', 'comma'));
    });
});

describe('copying the source of an import', function (): void {
    it('copies a stream whole', function (): void {
        $source = fopen('php://memory', 'w+b');
        $target = fopen('php://memory', 'w+b');
        fwrite($source, 'a,b');
        rewind($source);

        ImportFiles::copy($source, $target);
        rewind($target);

        expect(stream_get_contents($target))->toBe('a,b');
    });

    it('fails when the copy cannot be written, or the source cannot be read', function (): void {
        $from = fopen('importwrite://source', 'rb');
        $to = fopen('importwrite://target', 'wb');
        FailingWriteStream::$failAllWrites = true;

        importUnreadable(fn () => ImportFiles::copy($from, $to));

        importUnreadable(fn () => ImportFiles::copy(fopen('importread://source', 'rb'), fopen('php://memory', 'w+b')));
    });

    it('closes what it opened and leaves no scratch file when the source is gone', function (): void {
        $case = importCase();
        $import = Import::factory()->create([
            'inventory_id' => $case['inventory']->id,
            'user_id' => $case['team']['owner']->id,
            'disk' => ATTACHMENT_TEST_DISK,
            'path' => 'workspaces/none/inventories/none/imports/none/source.csv',
        ]);
        $files = app(ImportFiles::class);
        $folder = storage_path('framework/imports');
        $before = [count(get_resources('stream')), count(glob($folder.'/import-*') ?: [])];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            importUnreadable(fn () => $files->localCopy($import));
        }

        expect([count(get_resources('stream')), count(glob($folder.'/import-*') ?: [])])->toBe($before);
    });
});
