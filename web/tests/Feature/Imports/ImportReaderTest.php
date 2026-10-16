<?php

declare(strict_types=1);

use App\Inventories\Exceptions\ImportFileException;
use App\Inventories\Imports\ImportCsv;
use App\Inventories\Imports\ImportFormat;
use OpenSpout\Common\Entity\Cell\FormulaCell;

require_once __DIR__.'/../../Support/imports.php';

/*
 * Reading the file of an import: the formats, the encoding and the separator of a CSV, the typed cells of a spreadsheet, the numbers
 * of the rows, and the guards against files that are not what they say or are too large.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
});

describe('a CSV', function (): void {
    it('reads UTF-8, drops the byte order mark and keeps the accents', function (): void {
        $path = importFile("\xEF\xBB\xBF".importCsv([['Name', 'City'], ['Élodie', 'Besançon']]));

        $found = importRead($path, ImportFormat::Csv);

        expect($found['rows'])->toBe([1 => ['Name', 'City'], 2 => ['Élodie', 'Besançon']])
            ->and($found['encoding'])->toBe('utf-8')
            ->and($found['delimiter'])->toBe('comma');
    });

    it('reads a Windows-1252 file written by a French spreadsheet', function (): void {
        $csv = mb_convert_encoding(importCsv([['Prénom', 'Ville', 'Prix'], ['Élodie', 'Besançon', '1 234,56 €']], ';'), 'Windows-1252', 'UTF-8');

        $found = importRead(importFile($csv), ImportFormat::Csv);

        expect($found['encoding'])->toBe('windows-1252')
            ->and($found['delimiter'])->toBe('semicolon')
            ->and($found['rows'])->toBe([1 => ['Prénom', 'Ville', 'Prix'], 2 => ['Élodie', 'Besançon', '1 234,56 €']]);
    });

    it('reads a Unicode text export of a spreadsheet (UTF-16 with tabs)', function (): void {
        $text = importCsv([['Name', 'Qty'], ['Drill', '3']], "\t");
        $path = importFile("\xFF\xFE".mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'));

        $found = importRead($path, ImportFormat::Csv);

        expect($found['encoding'])->toBe('utf-16')
            ->and($found['delimiter'])->toBe('tab')
            ->and($found['rows'])->toBe([1 => ['Name', 'Qty'], 2 => ['Drill', '3']]);
    });

    it('uses the encoding and the separator it is told to, not the ones it would find', function (): void {
        $path = importFile(importCsv([['a;b', 'c'], ['d', 'e']], ','));

        $found = importRead($path, ImportFormat::Csv, 'semicolon', 'utf-8');

        expect($found['rows'][1])->toBe(['a', 'b,c']);
    });

    it('finds the separator of the first lines, not the one inside quotes or only in some lines', function (string $sample, string $expected): void {
        expect(ImportCsv::detectDelimiter($sample))->toBe($expected);
    })->with([
        'semicolons' => ["a;b;c\r\n1;2;3\r\n", 'semicolon'],
        'commas' => ["a,b,c\n1,2,3\n", 'comma'],
        'tabs' => ["a\tb\tc\n1\t2\t3\n", 'tab'],
        'decimal commas with semicolons' => ["Name;Price\nDrill;12,5\nSaw;9,75\n", 'semicolon'],
        'a comma inside quotes' => ["\"a,b\";c\n\"d,e\";f\n", 'semicolon'],
        'commas in free text, tabs for the columns' => ["Name\tNotes\nDrill\tred, heavy\nSaw\tsharp\n", 'tab'],
        'a single line' => ['Name;Price;Notes', 'semicolon'],
        'a single column' => ["Name\nDrill\nSaw\n", 'comma'],
        'a byte order mark' => ["\xEF\xBB\xBFName;Price\nDrill;12\n", 'semicolon'],
        'a quoted line break' => ["\"a\nb\";c\n\"d\";e\n", 'semicolon'],
    ]);

    it('takes the apostrophe off a text the export protected from a spreadsheet formula, and only that', function (): void {
        $path = importFile(importCsv([["'=1+1", "'+33 6 12", "'-3", "'@home", "'\t tab", "'plain", "it's", "'=", "'"]]));

        expect(importRead($path, ImportFormat::Csv)['rows'][1])->toBe(['=1+1', '+33 6 12', '-3', '@home', "\t tab", "'plain", "it's", '=', "'"]);
    });

    it('keeps the numbers of the rows with the empty ones counted, and cuts the empty cells at the end of a row', function (): void {
        $path = importFile("Name,Qty,,\r\nDrill,3,,\r\n\r\n\r\nSaw,,,\r\n,,,\r\nLast,9\r\n\r\n");

        expect(importRead($path, ImportFormat::Csv)['rows'])->toBe([1 => ['Name', 'Qty'], 2 => ['Drill', '3'], 5 => ['Saw'], 7 => ['Last', '9']]);
    });

    it('reads a cell with a line break and a quote inside quotes', function (): void {
        $path = importFile("Name,Notes\r\nDrill,\"two\r\nlines, \"\"quoted\"\"\"\r\n");

        expect(importRead($path, ImportFormat::Csv)['rows'][2])->toBe(['Drill', "two\r\nlines, \"quoted\""]);
    });

    it('drops the NUL characters a database refuses and replaces bytes that are not valid text', function (): void {
        // Forced as UTF-8: a Windows-1252 byte that is not valid UTF-8 is replaced, whatever else the file holds. The NUL is past the
        // first bytes, which are looked at to tell a text from a binary file.
        $path = importFile('Name'.str_repeat(' ', 9000)."\r\nab\x00c\xE9d\r\n");

        $cell = importRead($path, ImportFormat::Csv, 'comma', 'utf-8')['rows'][2][0];

        expect($cell)->toBeString()
            ->and(mb_check_encoding($cell, 'UTF-8'))->toBeTrue()
            ->and($cell)->not->toContain("\0");
    });

    it('is refused when it is empty, or is not text', function (string $bytes, string $reason): void {
        expect(fn () => importRead(importFile($bytes), ImportFormat::Csv))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe($reason));
    })->with([
        'empty' => ['', ImportFileException::EMPTY],
        'a PNG' => ["\x89PNG\r\n\x1A\n\x00\x00\x00\rIHDR", ImportFileException::UNRECOGNIZED],
    ]);

    it('is refused when a row uses too many columns', function (): void {
        config(['inventories.import_max_columns' => 3]);

        expect(fn () => importRead(importFile("a,b,c\r\n1,2,3,4\r\n"), ImportFormat::Csv))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_COLUMNS)->and($failure->getMessage())->toBe('This file uses more than 3 columns.'));
    });

    it('takes a file of empty lines for a file with no row', function (): void {
        expect(importRead(importFile("\r\n\r\n\r\n"), ImportFormat::Csv)['rows'])->toBe([]);
    });
});

describe('a spreadsheet', function (): void {
    it('gives each cell the value it has, typed', function (string $format): void {
        $day = new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC'));
        $moment = new DateTimeImmutable('2026-07-01 12:30:00', new DateTimeZone('UTC'));
        $path = importSpreadsheet($format, [
            ['Text', 'Int', 'Float', 'Yes', 'Day', 'Moment', 'Time'],
            ['Drill', 3, 12.5, true, $day, $moment, new DateInterval('PT7H5M')],
            ['text', 0, -0.25, false],
        ]);

        $rows = importRead($path, ImportFormat::from($format))['rows'];

        expect($rows[2][0])->toBe('Drill')
            ->and($rows[2][1])->toBe(3)
            ->and($rows[2][2])->toBe(12.5)
            ->and($rows[2][3])->toBeTrue()
            ->and($rows[2][4])->toBeInstanceOf(DateTimeImmutable::class)
            ->and($rows[2][4]->format('Y-m-d H:i'))->toBe('2026-03-01 00:00')
            ->and($rows[2][5]->format('Y-m-d H:i'))->toBe('2026-07-01 12:30')
            // A time is a duration in OpenDocument and a time of the day in the other.
            ->and($rows[2][6])->toBeInstanceOf($format === 'xlsx' ? DateTimeImmutable::class : DateInterval::class)
            ->and($format === 'xlsx' ? $rows[2][6]->format('H:i') : sprintf('%02d:%02d', $rows[2][6]->h, $rows[2][6]->i))->toBe('07:05')
            ->and($rows[3][2])->toBe(-0.25)
            ->and($rows[3][3])->toBeFalse();
    })->with(['xlsx', 'ods']);

    it('reads a formula as its calculated value', function (): void {
        $path = importSpreadsheet('xlsx', [['A'], [new FormulaCell('=1+1')]]);
        // The library writes the formula and not its value; a spreadsheet application writes both.
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/worksheets/sheet1.xml', (string) preg_replace('~(<f>[^<]*</f>)~', '$1<v>2</v>', (string) $zip->getFromName('xl/worksheets/sheet1.xml')));
        $zip->close();

        expect(importRead($path, ImportFormat::Xlsx)['rows'])->toBe([1 => ['A'], 2 => [2]]);
    });

    it('keeps the numbers of the rows, empty ones counted', function (string $format): void {
        $path = importSpreadsheet($format, [['Name', 'Qty'], [null], [null], ['Drill', 3], [null, null], ['Saw']]);

        expect(array_keys(importRead($path, ImportFormat::from($format))['rows']))->toBe([1, 4, 6]);
    })->with(['xlsx', 'ods']);

    it('reads the sheet that was active when it was saved', function (): void {
        $path = importSpreadsheet('xlsx', [['First']], [[['Second']], [['Third']]]);
        $zip = new ZipArchive;
        $zip->open($path);
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        // Excel records the tab that was open when the workbook was saved.
        $zip->addFromString('xl/workbook.xml', (string) preg_replace('/<sheets>/', '<bookViews><workbookView activeTab="2"/></bookViews><sheets>', $workbook, 1));
        $zip->close();

        expect(importRead($path, ImportFormat::Xlsx)['rows'][1][0])->toBe('Third');
    });

    it('skips a hidden sheet', function (): void {
        $path = importSpreadsheet('xlsx', [['Hidden']], [[['Shown']]]);
        $zip = new ZipArchive;
        $zip->open($path);
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->addFromString('xl/workbook.xml', (string) preg_replace('/state="visible"/', 'state="hidden"', $workbook, 1));
        $zip->close();

        expect(importRead($path, ImportFormat::Xlsx)['rows'][1][0])->toBe('Shown');
    });

    it('is refused when it is not a spreadsheet of its extension', function (): void {
        $notZip = importFile('Name;Qty', 'xlsx');
        $wrongZip = importFile((function (): string {
            $path = tempnam(sys_get_temp_dir(), 'zip');
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('hello.txt', 'hi');
            $zip->close();

            return (string) file_get_contents($path);
        })(), 'xlsx');

        foreach ([$notZip, $wrongZip] as $path) {
            expect(fn () => importRead($path, ImportFormat::Xlsx))
                ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::UNRECOGNIZED));
        }

        // An ODS read as an XLSX (and the other way round) is not the format asked for.
        expect(fn () => importRead(importSpreadsheet('ods', [['a']]), ImportFormat::Xlsx))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::UNRECOGNIZED));
    });

    it('is refused before anything is read when it unpacks to more than the limit (a zip bomb)', function (string $format): void {
        $path = importSpreadsheet($format, [['Name'], ['Drill']]);
        $size = (int) filesize($path);
        config(['inventories.import_max_unpacked_size' => 200]);

        expect($size)->toBeLessThan(20000)
            ->and(fn () => importRead($path, ImportFormat::from($format)))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::UNPACKED_TOO_LARGE));
    })->with(['xlsx', 'ods']);

    it('is refused when it holds more parts than a spreadsheet does', function (): void {
        $path = importFile('', 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook/>');

        for ($part = 0; $part <= 10000; $part++) {
            $zip->addFromString("junk/{$part}", '');
        }

        $zip->close();

        expect(fn () => importRead($path, ImportFormat::Xlsx))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::UNPACKED_TOO_LARGE));
    });

    it('is refused when a row uses too many columns', function (): void {
        config(['inventories.import_max_columns' => 3]);

        expect(fn () => importRead(importSpreadsheet('xlsx', [['a', 'b', 'c', 'd']]), ImportFormat::Xlsx))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_COLUMNS));
    });
});

describe('the formats', function (): void {
    it('are told by the extension of the name', function (string $name, ?ImportFormat $format): void {
        expect(ImportFormat::fromFileName($name))->toBe($format);
    })->with([
        ['items.csv', ImportFormat::Csv],
        ['ITEMS.TSV', ImportFormat::Csv],
        ['export.txt', ImportFormat::Csv],
        ['stock.XLSX', ImportFormat::Xlsx],
        ['stock.ods', ImportFormat::Ods],
        ['old.xls', null],
        ['stock.xlsm', null],
        ['noextension', null],
        ['archive.csv.zip', null],
    ]);
});
