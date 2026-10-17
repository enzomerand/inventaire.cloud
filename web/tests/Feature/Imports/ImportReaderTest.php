<?php

declare(strict_types=1);

use App\Inventories\Exceptions\ImportFileException;
use App\Inventories\Imports\ImportCsv;
use App\Inventories\Imports\ImportFormat;
use App\Inventories\Imports\ImportRows;
use App\Inventories\Imports\ImportWidthGuard;
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

    it('reads on after a very long run of empty lines, and drops no row', function (): void {
        $long = importFile("Name\r\nDrill\r\n".str_repeat("\r\n", 150000)."Saw\r\n");

        expect(importRead($long, ImportFormat::Csv)['rows'])->toBe([1 => ['Name'], 2 => ['Drill'], 150003 => ['Saw']]);
    });

    it('is refused when it has more lines than a spreadsheet holds, the empty ones counted', function (): void {
        $path = importFile(str_repeat("\n", ImportRows::MAX_LINES)."Late\n");

        expect(fn () => importRead($path, ImportFormat::Csv))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_LINES)
                ->and($failure->getMessage())->toBe('This file has more than 1,100,000 lines, the empty ones included. Delete the empty lines at the end of the file.'));
    });

    it('is refused when a record has more fields than a row may be built with, before the record is built', function (string $text): void {
        expect(fn () => importRead(importFile($text), ImportFormat::Csv))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_COLUMNS));
    })->with([
        'separators' => ['a'.str_repeat(',', ImportWidthGuard::MATERIALISED_COLUMNS)."\r\n"],
        'a quote that does not open a field does not hide the separators' => ['a"b'.str_repeat(',', ImportWidthGuard::MATERIALISED_COLUMNS)."\r\n"],
        'a field closed by its quote, and the separators after it' => ['"a"'.str_repeat(',', ImportWidthGuard::MATERIALISED_COLUMNS)."\r\n"],
        'a doubled quote inside quotes does not close the field' => ['"a""b",'.str_repeat(',', ImportWidthGuard::MATERIALISED_COLUMNS)."\r\n"],
        'after lines that are not wide' => ["a,b\nc,d\n".str_repeat(',', ImportWidthGuard::MATERIALISED_COLUMNS)],
        'spaces before the quote' => ['  "a"'.str_repeat(',', ImportWidthGuard::MATERIALISED_COLUMNS)],
    ]);

    it('lets through a record that holds its separators in quotes, and rows that end with a few empty fields', function (): void {
        $commas = str_repeat(',', ImportWidthGuard::MATERIALISED_COLUMNS + 10);
        $path = importFile("Name,Notes\r\n\"{$commas}\",x\r\n\"a\"\"{$commas}\",y\r\nz,w".str_repeat(',', 300)."\r\n");

        $rows = importRead($path, ImportFormat::Csv)['rows'];

        expect($rows[2])->toBe([$commas, 'x'])
            ->and($rows[3])->toBe(['a"'.$commas, 'y'])
            ->and($rows[4])->toBe(['z', 'w']);
    });

    it('reads a quote just after the byte order mark as the library does, which skips the mark', function (): void {
        // A quote opens a field that holds the line feed; each line after it closes the quote at its first character, makes 500 empty fields
        // of its commas, and opens a quote again with its last: all of them are fields of one record.
        $lines = implode('', array_map(fn (): string => '"'.str_repeat(',', 500)."\"\n", range(1, 10)));

        foreach (["\xEF\xBB\xBF", ''] as $mark) {
            expect(fn () => importRead(importFile($mark."\"\n".$lines), ImportFormat::Csv))
                ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_COLUMNS));
        }
    });

    it('counts the separators the same way wherever the megabyte that is read at a time ends', function (): void {
        $wide = str_repeat(',', 20000);

        // A doubled quote is a quote in the text and the field goes on; a lone one closes it and the separators after it separate.
        foreach ([1048573, 1048574, 1048575] as $padding) {
            $inside = importFile('"'.str_repeat('x', $padding).'""'.$wide."\"\n");
            $outside = importFile('"'.str_repeat('x', $padding).'"'.$wide."\n");

            expect(fn () => ImportWidthGuard::csv($inside, ','))->not->toThrow(ImportFileException::class)
                ->and(fn () => ImportWidthGuard::csv($outside, ','))
                ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_COLUMNS));
        }
    });

    it('counts the fields of a record with the separator it is given, and judges the width against the limit of the guard', function (): void {
        $line = fn (int $fields, string $separator): string => implode($separator, array_fill(0, $fields, 'x'))."\n";

        foreach ([',', ';', "\t"] as $separator) {
            expect(fn () => ImportWidthGuard::csv(importFile($line(ImportWidthGuard::MATERIALISED_COLUMNS, $separator)), $separator))->not->toThrow(ImportFileException::class)
                ->and(fn () => ImportWidthGuard::csv(importFile($line(ImportWidthGuard::MATERIALISED_COLUMNS + 1, $separator)), $separator))->toThrow(ImportFileException::class)
                // The other characters are text.
                ->and(fn () => ImportWidthGuard::csv(importFile($line(ImportWidthGuard::MATERIALISED_COLUMNS + 1, $separator === ',' ? ';' : ',')), $separator))->not->toThrow(ImportFileException::class);
        }

        // A larger limit of the import widens the one of the guard.
        config(['inventories.import_max_columns' => 20000]);

        expect(fn () => ImportWidthGuard::csv(importFile($line(20000, ',')), ','))->not->toThrow(ImportFileException::class)
            ->and(fn () => ImportWidthGuard::csv(importFile($line(20001, ',')), ','))->toThrow(ImportFileException::class);
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

    it('counts the bytes that really come out of the archive, not the sizes the archive announces (a forged zip bomb)', function (string $format): void {
        config(['inventories.import_max_unpacked_size' => 100000]);
        // The archive says the part weighs a hundred bytes; it weighs two hundred thousand.
        $path = importForgedArchive($format, 100, 200000);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::RDONLY);

        expect($zip->statIndex(0)['size'])->toBe(100)
            ->and(filesize($path))->toBeLessThan(2000)
            ->and(fn () => importRead($path, ImportFormat::from($format)))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::UNPACKED_TOO_LARGE));
    })->with(['xlsx', 'ods']);

    it('lets through an archive that unpacks to what it announces, up to the limit', function (): void {
        $path = importSpreadsheet('xlsx', [['Name'], ['Drill']]);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::RDONLY);
        $unpacked = array_sum(array_map(fn (int $index): int => $zip->statIndex($index)['size'], range(0, $zip->numFiles - 1)));
        config(['inventories.import_max_unpacked_size' => $unpacked]);

        expect(importRead($path, ImportFormat::Xlsx)['rows'])->toBe([1 => ['Name'], 2 => ['Drill']]);
    });

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

describe('a worksheet of a workbook that announces a width no sheet has', function (): void {
    it('is refused before the sheet is filled to that width', function (string|array $from, string|array $to): void {
        $path = importPatch(importSpreadsheet('xlsx', [['a', 'b']]), 'xl/worksheets/sheet1.xml', fn (string $xml): string => str_replace($from, $to, $xml));

        expect(fn () => importRead($path, ImportFormat::Xlsx))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_COLUMNS));
    })->with([
        'a dimension' => ['<dimension ref="A1:B1"/>', '<dimension ref="A1:ZZZZZZ1"/>'],
        'a dimension past the last column' => ['<dimension ref="A1:B1"/>', '<dimension ref="A1:XFE1"/>'],
        'the spans of a row' => ['spans="1:2"', 'spans="1:50000000"'],
        // The only one the library does not check itself: with no dimension it fills each row to its spans, fifty million cells.
        'the spans of a row, in a sheet with no dimension' => [['<dimension ref="A1:B1"/>', 'spans="1:2"'], ['', 'spans="1:50000000"']],
        'a cell past the last column' => ['<c r="B1"', '<c r="XFE1"'],
    ]);

    it('is read when it is as wide as a row may be built', function (): void {
        // The import takes as many columns as the guard does: 512, which is `SR`.
        config(['inventories.import_max_columns' => ImportWidthGuard::MATERIALISED_XLSX_COLUMNS]);
        $path = importPatch(importSpreadsheet('xlsx', [['a', 'b']]), 'xl/worksheets/sheet1.xml', fn (string $xml): string => str_replace(['<dimension ref="A1:B1"/>', 'spans="1:2"', '<c r="B1"'], ['<dimension ref="A1:SR1"/>', 'spans="1:512"', '<c r="SR1"'], $xml));

        $row = importRead($path, ImportFormat::Xlsx)['rows'][1];

        expect($row[0])->toBe('a')
            ->and($row[511])->toBe('b');
    });

    it('is refused when it is one column wider than that', function (string|array $from, string|array $to): void {
        config(['inventories.import_max_columns' => ImportWidthGuard::MATERIALISED_XLSX_COLUMNS]);
        $path = importPatch(importSpreadsheet('xlsx', [['a', 'b']]), 'xl/worksheets/sheet1.xml', fn (string $xml): string => str_replace($from, $to, $xml));

        expect(fn () => importRead($path, ImportFormat::Xlsx))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_COLUMNS));
    })->with([
        'by its dimension' => ['<dimension ref="A1:B1"/>', '<dimension ref="A1:SS1"/>'],
        'by the spans of its rows' => [['<dimension ref="A1:B1"/>', 'spans="1:2"'], ['', 'spans="1:513"']],
        'by a cell' => ['<c r="B1"', '<c r="SS1"'],
    ]);

    it('lets through a sheet written by Excel: styled empty cells, the width of its data, and 10 000 rows', function (): void {
        $cells = fn (int $row): string => implode('', array_map(fn (int $column): string => '<c r="'.chr(64 + $column).$row.'" s="1"><v>'.$row.'</v></c>', range(1, 13))).'<c r="N'.$row.'" s="2"/>';
        $rows = implode('', array_map(fn (int $row): string => '<row r="'.$row.'" spans="1:14">'.$cells($row).'</row>', range(1, 10000)));
        $path = importPatch(importXlsxRows($rows), 'xl/worksheets/sheet1.xml', fn (string $xml): string => str_replace('<sheetData>', '<dimension ref="A1:N10000"/><cols><col min="1" max="14" width="12" customWidth="1"/></cols><sheetData>', $xml));

        ImportWidthGuard::xlsx($path);

        expect(true)->toBeTrue();
    });
});

describe('a worksheet whose cells are placed one after the other, or by a reference', function (): void {
    it('is refused when a row holds more cells than a sheet has columns, however they say where they are', function (string $cells): void {
        expect(fn () => importRead(importXlsxRows('<row r="1">'.$cells.'</row>'), ImportFormat::Xlsx))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_COLUMNS));
    })->with([
        // The library puts a cell with no reference after the last one it placed, and builds all of them.
        'cells with no reference' => [str_repeat('<c/>', ImportWidthGuard::MATERIALISED_XLSX_COLUMNS + 1)],
        'the same cell again and again' => [str_repeat('<c r="A1"/>', ImportWidthGuard::MATERIALISED_XLSX_COLUMNS + 1)],
        // `SR` is the 512th column.
        'a cell with no reference after the last column' => ['<c r="SR1"/><c/>'],
        'cells with no reference after one far to the right' => ['<c r="SQ1"/><c/><c/>'],
        'one cell, then cells that go back' => ['<c r="SR1"/>'.str_repeat('<c r="A1"/>', ImportWidthGuard::MATERIALISED_XLSX_COLUMNS)],
    ]);

    it('counts the cells of each row, not of the sheet', function (): void {
        config(['inventories.import_max_columns' => ImportWidthGuard::MATERIALISED_XLSX_COLUMNS]);
        $columns = ImportWidthGuard::MATERIALISED_XLSX_COLUMNS;
        $row = fn (int $number): string => '<row r="'.$number.'">'.str_repeat('<c t="inlineStr"><is><t>x</t></is></c>', $columns / 2).'</row>';
        $rows = importRead(importXlsxRows($row(1).$row(2).$row(3)), ImportFormat::Xlsx)['rows'];

        expect($rows)->toHaveCount(3)->and(count($rows[3]))->toBe($columns / 2);
    });

    it('is read when its cells have no reference, up to the width a row may be built with', function (): void {
        $columns = ImportWidthGuard::MATERIALISED_XLSX_COLUMNS;
        // The empty cells at the end of the row are cut off, the others are read.
        $cells = '<c t="inlineStr"><is><t>x</t></is></c><c t="inlineStr"><is><t>y</t></is></c>'.str_repeat('<c/>', $columns - 2);

        expect(importRead(importXlsxRows('<row r="1">'.$cells.'</row>'), ImportFormat::Xlsx)['rows'])->toBe([1 => ['x', 'y']]);
    });
});

describe('a worksheet whose references and spans are not what the library reads', function (): void {
    it('is refused as unreadable when the reference of a cell is not a column of letters and a row', function (string $reference): void {
        // The library reads `SR1` followed by a line feed as a valid reference (`$` matches before it) and works out another column for it.
        $path = importXlsxRows('<row r="1"><c r="'.$reference.'"/></row>');

        expect(fn () => ImportWidthGuard::xlsx($path))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::UNREADABLE));
    })->with([
        'a line feed after it' => ['SR1&#10;'],
        'a line feed in it' => ['S&#10;R1'],
        'lowercase letters' => ['a1'],
        'no row' => ['A'],
        'no column' => ['1'],
        'four letters' => ['ABCD1'],
        'something that is none' => ['?'],
        'nothing' => [''],
        'a space after it' => ['A1 '],
    ]);

    it('is refused as unreadable when the spans of a row are not a range of columns', function (string $spans): void {
        $path = importXlsxRows('<row r="1" spans="'.$spans.'"><c r="A1"/></row>');

        expect(fn () => ImportWidthGuard::xlsx($path))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::UNREADABLE));
    })->with([
        'a negative end' => ['1:-5'],
        'a range that goes back' => ['5:1'],
        'no end' => ['1:'],
        'no start' => [':5'],
        'one number' => ['5'],
        'letters' => ['a:b'],
    ]);

    it('lets through the references and spans that a sheet has', function (string $spans): void {
        ImportWidthGuard::xlsx(importXlsxRows('<row r="1" spans="'.$spans.'"><c r="A1"/><c r="AB12"/><c r="SR3"/></row>'));

        expect(true)->toBeTrue();
    })->with([['1:14'], ['1:1'], ['2:10 12:14'], ['']]);
});

describe('a worksheet the workbook points to by a name that is not the usual one', function (): void {
    it('is read as any sheet is', function (string $to, ?string $target): void {
        $path = importRenamePart(importSpreadsheet('xlsx', [['a', 'b']]), 'xl/worksheets/sheet1.xml', $to, $target);

        expect(importRead($path, ImportFormat::Xlsx)['rows'])->toBe([1 => ['a', 'b']]);
    })->with([
        'with no extension' => ['xl/worksheets/data', null],
        'with another extension' => ['xl/worksheets/data.bin', null],
        'in another folder' => ['xl/other/sheet1.xml', null],
        'by an absolute name' => ['xl/worksheets/data.dat', '/xl/worksheets/data.dat'],
    ]);

    it('is checked as any sheet is', function (string $to, ?string $target, string|array $from, string|array $replacement): void {
        $path = importRenamePart(importSpreadsheet('xlsx', [['a', 'b']]), 'xl/worksheets/sheet1.xml', $to, $target);
        $path = importPatch($path, $to, fn (string $xml): string => str_replace($from, $replacement, $xml));

        expect(fn () => importRead($path, ImportFormat::Xlsx))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_COLUMNS));
    })->with([
        'a dimension, with no extension' => ['xl/worksheets/data', null, '<dimension ref="A1:B1"/>', '<dimension ref="A1:ZZZZZZ1"/>'],
        'cells with no reference, with another extension' => ['xl/worksheets/data.bin', null, '<c r="B1"', '<c r="B1"/>'.str_repeat('<c/>', ImportWidthGuard::MATERIALISED_XLSX_COLUMNS + 1).'<c r="C1"'],
        'a far cell, by an absolute name' => ['xl/worksheets/data.dat', '/xl/worksheets/data.dat', '<c r="B1"', '<c r="XFE1"'],
    ]);

    it('is the only kind of part whose cells are counted', function (): void {
        // The list of the formulas of a workbook holds one `<c>` for each of them, in no row: far more than a row may hold.
        $calculations = '<calcChain xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.str_repeat('<c r="A1" i="1"/>', ImportWidthGuard::MATERIALISED_XLSX_COLUMNS * 2).'</calcChain>';
        $path = importPatch(importSpreadsheet('xlsx', [['a', 'b']]), 'xl/calcChain.xml', fn (): string => $calculations);
        $path = importPatch($path, 'xl/_rels/workbook.xml.rels', fn (string $xml): string => str_replace('</Relationships>', '<Relationship Id="rIdCalc" Target="calcChain.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/calcChain"/></Relationships>', $xml));

        expect(importRead($path, ImportFormat::Xlsx)['rows'])->toBe([1 => ['a', 'b']]);
    });
});

describe('an OpenDocument sheet whose cells are repeated', function (): void {
    it('is refused when a row would be built wider than a sheet can be', function (string $cells): void {
        $path = importPatch(importSpreadsheet('ods', [['a'], ['b']]), 'content.xml', fn (string $xml): string => preg_replace('~</table:table-row>~', $cells.'</table:table-row>', $xml, 1) ?? $xml);

        expect(fn () => importRead($path, ImportFormat::Ods))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_COLUMNS));
    })->with([
        'a cell with a value, repeated fifty million times' => ['<table:table-cell office:value-type="string" table:number-columns-repeated="50000000"><text:p>x</text:p></table:table-cell>'],
        'an empty cell that is not the last one of the row' => ['<table:table-cell table:number-columns-repeated="50000000"/><table:table-cell office:value-type="string"><text:p>x</text:p></table:table-cell>'],
        'an empty cell at the end of a row that holds something' => ['<table:table-cell table:number-columns-repeated="50000000"/>'],
        'cells repeated a few times each' => [str_repeat('<table:table-cell office:value-type="string" table:number-columns-repeated="1000"><text:p>x</text:p></table:table-cell>', 20)],
    ]);

    it('is read when a spreadsheet application pads its rows, as it does', function (int $padding, int $columns): void {
        // `a` is the first cell; the padding is a cell repeated up to the width of the sheet, as LibreOffice (1 024 columns, or 16 384 in
        // recent versions) and Excel write it. The first is built as it is (1 024 cells), the second is not built at all.
        $path = importPatch(importSpreadsheet('ods', [['a', 'b'], ['c', 'd']]), 'content.xml', fn (string $xml): string => str_replace('</table:table-row>', '<table:table-cell table:number-columns-repeated="'.$padding.'"/></table:table-row>', $xml));

        expect(importRead($path, ImportFormat::Ods)['rows'])->toBe([1 => ['a', 'b'], 2 => ['c', 'd']]);
    })->with([
        'a sheet of 1 024 columns' => [1022, 1024],
        'a sheet of 16 384 columns' => [16382, 16384],
    ]);

    it('is refused when the padding makes a row wider than one may be built, and is not the width of an Excel sheet', function (int $padding): void {
        $path = importPatch(importSpreadsheet('ods', [['a', 'b'], ['c', 'd']]), 'content.xml', fn (string $xml): string => str_replace('</table:table-row>', '<table:table-cell table:number-columns-repeated="'.$padding.'"/></table:table-row>', $xml));

        expect(fn () => importRead($path, ImportFormat::Ods))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_COLUMNS));
    })->with([
        'one column more than 1 024' => [1023],
        'a little less than 16 384' => [16000],
    ]);

    it('gives a row with nothing in it one empty cell, however many it repeats', function (): void {
        $path = importOdsAfterRow(importSpreadsheet('ods', [['a'], ['b']]), 1, '<table:table-row table:number-rows-repeated="3"><table:table-cell table:number-columns-repeated="50000000"/></table:table-row>');

        expect(importRead($path, ImportFormat::Ods)['rows'])->toBe([1 => ['a'], 5 => ['b']]);
    });

    it('is read to its end when it ends with a million rows that hold nothing, and the rows after them are not lost', function (): void {
        $path = importOdsAfterRow(importSpreadsheet('ods', [['Name'], ['Drill'], ['Saw']]), 2, '<table:table-row table:number-rows-repeated="1048000"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>');

        expect(importRead($path, ImportFormat::Ods)['rows'])->toBe([1 => ['Name'], 2 => ['Drill'], 1048003 => ['Saw']]);
    });

    it('is refused when it has more lines than a spreadsheet holds, the empty ones counted', function (): void {
        $path = importOdsAfterRow(importSpreadsheet('ods', [['Name'], ['Drill']]), 2, '<table:table-row table:number-rows-repeated="'.ImportRows::MAX_LINES.'"><table:table-cell/></table:table-row>');

        expect(fn () => importRead($path, ImportFormat::Ods))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_LINES));
    });
});

describe('an OpenDocument sheet whose rows and cells are not where a sheet has them', function (): void {
    it('is refused as unreadable, and does not fail on the way', function (string $xml): void {
        $path = importPatch(importSpreadsheet('ods', [['a'], ['b']]), 'content.xml', fn (string $content): string => (string) preg_replace('~</table:table-cell></table:table-row>~', '</table:table-cell>'.$xml.'</table:table-row>', $content, 1));

        expect(fn () => importRead($path, ImportFormat::Ods))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::UNREADABLE));
    })->with([
        'a row that repeats, inside a row' => ['<table:table-row table:number-rows-repeated="2"/>'],
        'a row inside a row' => ['<table:table-row><table:table-cell office:value-type="string"><text:p>x</text:p></table:table-cell></table:table-row>'],
        'a row inside a row, and nothing in it' => ['<table:table-row></table:table-row>'],
        // What the library builds across them: the guard once forgot the cells of the row it left, and skipped those that came after.
        'a row that repeats inside a row, and a cell repeated many times after it' => ['<table:table-row table:number-rows-repeated="2"/><table:table-cell table:number-columns-repeated="300000" office:value-type="string"><text:p>x</text:p></table:table-cell>'],
        'a row inside a row, and a cell repeated many times after it' => ['<table:table-row><table:table-cell office:value-type="string"><text:p>y</text:p></table:table-cell></table:table-row><table:table-cell table:number-columns-repeated="300000" office:value-type="string"><text:p>x</text:p></table:table-cell>'],
    ]);

    it('is refused as unreadable when a cell is in no row', function (): void {
        $path = importOdsAfterRow(importSpreadsheet('ods', [['a'], ['b']]), 1, '<table:table-cell table:number-columns-repeated="50000000" office:value-type="string"><text:p>x</text:p></table:table-cell>');

        expect(fn () => importRead($path, ImportFormat::Ods))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::UNREADABLE));
    });

    it('is read when its rows are in the groups a sheet has them in', function (): void {
        $path = importPatch(importSpreadsheet('ods', [['a'], ['b']]), 'content.xml', fn (string $content): string => str_replace('</table:table-row></table:table>', '</table:table-row></table:table-header-rows></table:table>', (string) preg_replace('~<table:table-row ~', '<table:table-header-rows><table:table-row ', $content, 1)));

        expect(importRead($path, ImportFormat::Ods)['rows'])->toBe([1 => ['a'], 2 => ['b']]);
    });
});

describe('a sheet with more cells than an import can build', function (): void {
    it('has a budget of twice the rows by the columns an import takes, and of the lines of a sheet at least', function (): void {
        expect(ImportWidthGuard::budget())->toBe(4000000);

        config(['inventories.import_max_rows' => 100, 'inventories.import_max_columns' => 50]);
        expect(ImportWidthGuard::budget())->toBe(ImportRows::MAX_LINES);

        config(['inventories.import_max_rows' => 50000, 'inventories.import_max_columns' => 200]);
        expect(ImportWidthGuard::budget())->toBe(20000000);
    });

    it('is refused as soon as an Excel sheet has more cells than that, the empty ones counted', function (): void {
        config(['inventories.import_max_rows' => 1, 'inventories.import_max_columns' => 1]);
        $row = fn (int $number): string => '<row r="'.$number.'">'.str_repeat('<c/>', ImportWidthGuard::MATERIALISED_XLSX_COLUMNS).'</row>';
        $rows = fn (int $count): string => implode('', array_map($row, range(1, $count)));
        $budget = ImportWidthGuard::budget();
        $fits = intdiv($budget, ImportWidthGuard::MATERIALISED_XLSX_COLUMNS);

        // The cells of the last row are counted too, though no row starts after it.
        ImportWidthGuard::xlsx(importXlsxRows($rows($fits)));

        expect(fn () => ImportWidthGuard::xlsx(importXlsxRows($rows($fits + 1))))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_CELLS));
    });

    it('counts a row of an Excel sheet by the width the sheet announces for it, whatever its cells are', function (): void {
        config(['inventories.import_max_rows' => 1, 'inventories.import_max_columns' => 1]);
        // 2 200 rows with no cell at all, in a sheet whose dimension says 512 columns: the library fills each of them with that many.
        $rows = implode('', array_map(fn (int $number): string => '<row r="'.$number.'"/>', range(1, 2200)));
        $path = importPatch(importXlsxRows($rows), 'xl/worksheets/sheet1.xml', fn (string $xml): string => str_replace('<sheetData>', '<dimension ref="A1:SR2200"/><sheetData>', $xml));

        expect(fn () => ImportWidthGuard::xlsx($path))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_CELLS));
    });

    it('is refused when an OpenDocument sheet has more cells than that, a row counting as many times as it is repeated', function (string $row, bool $refused): void {
        $path = importOdsAfterRow(importSpreadsheet('ods', [['a'], ['b']]), 1, $row);
        $attempt = fn () => ImportWidthGuard::ods($path);

        if ($refused) {
            expect($attempt)->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_CELLS));
        } else {
            $attempt();
            expect(true)->toBeTrue();
        }
    })->with([
        // 1 000 cells (the library builds all of them) given 3 999 times, and the two rows of one cell around them, fit in the budget; 4 000 times do not.
        'a row of a thousand cells, repeated up to the budget' => ['<table:table-row table:number-rows-repeated="3999"><table:table-cell office:value-type="string" table:number-columns-repeated="1000"><text:p>x</text:p></table:table-cell></table:table-row>', false],
        'a row of a thousand cells, repeated beyond it' => ['<table:table-row table:number-rows-repeated="4000"><table:table-cell office:value-type="string" table:number-columns-repeated="1000"><text:p>x</text:p></table:table-cell></table:table-row>', true],
        // Nothing in it, but the library builds the cells that come before the last one, and gives the row as often as it repeats.
        'a row that looks empty, of many cells, repeated' => ['<table:table-row table:number-rows-repeated="9000"><table:table-cell table:number-columns-repeated="500"/><table:table-cell table:number-columns-repeated="500"/></table:table-row>', true],
    ]);

    it('is judged for each sheet of an OpenDocument spreadsheet, not for all of them', function (): void {
        $row = '<table:table-row table:number-rows-repeated="2400"><table:table-cell office:value-type="string" table:number-columns-repeated="1000"><text:p>x</text:p></table:table-cell></table:table-row>';
        $path = importPatch(importSpreadsheet('ods', [['a']]), 'content.xml', fn (string $xml): string => str_replace('</table:table>', $row.'</table:table><table:table table:name="Second">'.$row.'</table:table>', $xml));

        // 2.4 million cells in each, 4.8 million in all: more than the budget of one.
        ImportWidthGuard::ods($path);

        expect(true)->toBeTrue();
    });

    it('is refused when a text file has more fields than that', function (string $ending, string $tail): void {
        config(['inventories.import_max_rows' => 1, 'inventories.import_max_columns' => 1]);
        $line = implode(',', array_fill(0, 1000, 'x')).$tail;
        $lines = fn (int $count): string => implode("\n", array_fill(0, $count, $line));
        // The separators of a line, the first field of the record apart.
        $fits = intdiv(ImportWidthGuard::budget(), 999 + strlen($tail));

        ImportWidthGuard::csv(importFile($lines($fits).$ending), ',');

        expect(fn () => ImportWidthGuard::csv(importFile($lines($fits + 1).$ending), ','))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_CELLS));
    })->with([
        'with a line feed at the end' => ["\n", ''],
        'with none, the last record being the one that goes over' => ['', ''],
        'with lines that end with a separator' => ["\n", ','],
    ]);

    it('says how many cells it may hold', function (): void {
        $failure = new ImportFileException(ImportFileException::TOO_MANY_CELLS);

        expect($failure->getMessage())->toBe('This file holds more than 4,000,000 cells, counting the empty ones that a spreadsheet keeps for its formatting. Delete the rows and columns you do not use, then send it again.');
    });
});

describe('an OpenDocument sheet whose repeats are not whole numbers', function (): void {
    it('is refused as unreadable when a row says it is repeated 0 times, a negative number or a text: the library then gives it for ever', function (string $repeats): void {
        $path = importOdsAfterRow(importSpreadsheet('ods', [['a'], ['b']]), 1, '<table:table-row table:number-rows-repeated="'.$repeats.'"><table:table-cell office:value-type="string" table:number-columns-repeated="1000"><text:p>x</text:p></table:table-cell></table:table-row>');

        expect(fn () => ImportWidthGuard::ods($path))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::UNREADABLE));
    })->with(['0', '-3', 'abc', '', '2.5', '01', ' 3']);

    it('is refused as unreadable when a cell says the same', function (string $repeats): void {
        $path = importOdsAfterRow(importSpreadsheet('ods', [['a'], ['b']]), 1, '<table:table-row><table:table-cell office:value-type="string"><text:p>x</text:p></table:table-cell><table:table-cell table:number-columns-repeated="'.$repeats.'"/></table:table-row>');

        expect(fn () => ImportWidthGuard::ods($path))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::UNREADABLE));
    })->with(['0', '-3', 'abc', '', '2.5']);

    it('gives a row repeated twice twice, and the one after it once', function (): void {
        $path = importOdsAfterRow(importSpreadsheet('ods', [['a'], ['c']]), 1, '<table:table-row table:number-rows-repeated="2"><table:table-cell office:value-type="string"><text:p>b</text:p></table:table-cell></table:table-row>');

        expect(importRead($path, ImportFormat::Ods)['rows'])->toBe([1 => ['a'], 2 => ['b'], 3 => ['b'], 4 => ['c']]);
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
