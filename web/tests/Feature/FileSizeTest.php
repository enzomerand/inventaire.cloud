<?php

declare(strict_types=1);

use App\Support\FileSize;
use Illuminate\Support\Number;

/*
 * The size of a file as the server writes it for a person (the messages of the API, the public page of a share link): the
 * scale of Laravel's `Number::fileSize()`, the number and the unit of the language of the request.
 */

describe('in English', function (): void {
    beforeEach(function (): void {
        $this->useLanguage('en');
    });

    it('writes the units of Laravel, with one decimal at most', function (int $bytes, string $expected): void {
        expect(FileSize::format($bytes))->toBe($expected);
    })->with([
        'nothing' => [0, '0 B'],
        'a few bytes' => [193, '193 B'],
        'just under the next unit starts' => [921, '921 B'],
        'where a kilobyte starts to be written' => [922, '0.9 KB'],
        'a kilobyte' => [1024, '1 KB'],
        'a kilobyte and a bit' => [1741, '1.7 KB'],
        'a megabyte and a half' => [1536 * 1024, '1.5 MB'],
        'the limit of an attachment' => [20 * 1024 * 1024, '20 MB'],
        'a gigabyte' => [1024 ** 3, '1 GB'],
        'five terabytes' => [5 * 1024 ** 4, '5 TB'],
    ]);

    it('agrees with Number::fileSize() on the whole scale', function (): void {
        foreach ([0, 1, 512, 921, 922, 1023, 1024, 1025, 10_000, 1_048_575, 1_048_576, 7_340_032, 1024 ** 3 - 1, 1024 ** 3, 3 * 1024 ** 3 + 1, 1024 ** 4, 900 * 1024 ** 4] as $bytes) {
            expect(FileSize::format($bytes))->toBe(Number::fileSize($bytes, maxPrecision: 1), "for {$bytes} bytes");
        }
    });
});

describe('in French', function (): void {
    beforeEach(function (): void {
        $this->useLanguage('fr');
    });

    it('counts in octets, with a decimal comma', function (int $bytes, string $expected): void {
        expect(FileSize::format($bytes))->toBe($expected);
    })->with([
        'nothing' => [0, '0 o'],
        'a few bytes' => [193, '193 o'],
        'where a kilobyte starts to be written' => [922, '0,9 ko'],
        'a kilobyte' => [1024, '1 ko'],
        'a kilobyte and a bit' => [1741, '1,7 ko'],
        'a megabyte and a half' => [1536 * 1024, '1,5 Mo'],
        'the limit of an attachment' => [20 * 1024 * 1024, '20 Mo'],
        'a gigabyte' => [1024 ** 3, '1 Go'],
        'five terabytes' => [5 * 1024 ** 4, '5 To'],
    ]);
});

it('follows the language of the moment, not the one it was first called in', function (): void {
    $this->useLanguage('fr');
    $french = FileSize::format(1741);

    $this->useLanguage('en');

    expect($french)->toBe('1,7 ko')
        ->and(FileSize::format(1741))->toBe('1.7 KB');
});
