<?php

declare(strict_types=1);

use App\Inventories\Attachments\FileNames;

/*
 * The name a file keeps once it is attached: harmless wherever it is shown and in a download header. It is never part of a
 * path on the disk (that is in tests/Feature/Inventories/ItemAttachmentsTest.php).
 */

describe('cleaning up the name of a file', function (): void {
    it('keeps a plain name as it is', function (string $name): void {
        expect(FileNames::sanitize($name))->toBe($name);
    })->with(['invoice.pdf', 'Facture été 2026.pdf', 'photo (1).JPG', 'archive.tar.gz', 'README', '日本語のファイル.txt', "l'été.txt", 'a "quoted" name.txt']);

    it('keeps only the last component of a path, whatever the system that wrote it', function (string $sent, string $kept): void {
        expect(FileNames::sanitize($sent))->toBe($kept);
    })->with([
        'parent folders' => ['../../etc/passwd', 'passwd'],
        'absolute path' => ['/etc/passwd', 'passwd'],
        'windows path' => ['C:\\Users\\me\\photo.jpg', 'photo.jpg'],
        'mixed separators' => ['a/b\\c/d.txt', 'd.txt'],
        'trailing separator' => ['folder/', 'file'],
    ]);

    it('drops control characters, null bytes included', function (): void {
        expect(FileNames::sanitize("bad\0na\x07me\r\n.txt"))->toBe('badname.txt')
            ->and(FileNames::sanitize("tab\there.txt"))->toBe('tabhere.txt');
    });

    it('drops the characters that change the direction of the text and the invisible ones', function (): void {
        // "invoice" + RIGHT-TO-LEFT OVERRIDE + "fdp.exe" displays as "invoiceexe.pdf".
        expect(FileNames::sanitize("invoice\u{202E}fdp.exe"))->toBe('invoicefdp.exe')
            ->and(FileNames::sanitize("zero\u{200B}width\u{FEFF}.txt"))->toBe('zerowidth.txt')
            ->and(FileNames::sanitize("line\u{2028}sep.txt"))->toBe('linesep.txt');
    });

    it('turns the other kinds of space into one plain space', function (): void {
        expect(FileNames::sanitize("a\u{00A0}\u{00A0}b\u{2003}c.txt"))->toBe('a b c.txt');
    });

    it('removes the spaces and dots at both ends: no hidden file, no dot-only name', function (string $sent, string $kept): void {
        expect(FileNames::sanitize($sent))->toBe($kept);
    })->with([
        'hidden' => ['.htaccess', 'htaccess'],
        'dots' => ['...', 'file'],
        'parent' => ['..', 'file'],
        'trailing dot' => ['name.', 'name'],
        'spaces' => ['  name.txt  ', 'name.txt'],
    ]);

    it('is called "file" when nothing is left', function (string $sent): void {
        expect(FileNames::sanitize($sent))->toBe('file');
    })->with(['', ' ', "\0\0", '/', "\u{202E}"]);

    it('puts accents in one form: a letter and a combining accent become the one character', function (): void {
        expect(FileNames::sanitize("e\u{0301}te\u{0301}.txt"))->toBe("\u{00E9}t\u{00E9}.txt");
    });

    it('replaces bytes that are not UTF-8', function (): void {
        $name = FileNames::sanitize("caf\xE9.txt");

        expect(mb_check_encoding($name, 'UTF-8'))->toBeTrue()
            ->and($name)->toEndWith('.txt');
    });

    it('is at most 255 characters, and keeps the extension of a long name', function (): void {
        $long = FileNames::sanitize(str_repeat('é', 400).'.pdf');
        $noExtension = FileNames::sanitize(str_repeat('a', 400));
        $longExtension = FileNames::sanitize('a.'.str_repeat('x', 300));

        expect(mb_strlen($long))->toBe(255)
            ->and($long)->toEndWith('.pdf')
            ->and(mb_strlen($noExtension))->toBe(255)
            ->and(mb_strlen($longExtension))->toBeLessThanOrEqual(255);
    });

    it('does not shorten a name of exactly 255 characters', function (): void {
        $name = str_repeat('a', 251).'.txt';

        expect(FileNames::sanitize($name))->toBe($name);
    });
});

describe('the ASCII fallback of a name for a download header', function (): void {
    it('keeps plain names and replaces the rest', function (string $name, string $fallback): void {
        expect(FileNames::asciiFallback($name))->toBe($fallback);
    })->with([
        'plain' => ['invoice.pdf', 'invoice.pdf'],
        'accents' => ['Facture été.pdf', 'Facture ete.pdf'],
        'quotes and percent' => ['a "b" 100%.txt', 'a _b_ 100_.txt'],
        'slash' => ['a/b.txt', 'a_b.txt'],
        'other scripts' => ['日本語.txt', 'download.txt'],
        'nothing left' => ['日本語', 'download'],
    ]);

    it('is always printable ASCII without the characters a header parameter cannot hold', function (): void {
        $fallback = FileNames::asciiFallback("na\"me;\\ %/ \u{00E9}.txt");

        expect(preg_match('/^[\x20-\x7e]+$/', $fallback))->toBe(1)
            ->and($fallback)->not->toContain('"')->not->toContain('%')->not->toContain('/')->not->toContain('\\');
    });
});
