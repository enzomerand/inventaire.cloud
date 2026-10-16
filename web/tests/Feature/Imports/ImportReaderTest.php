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

    it('is refused when a record has more fields than a spreadsheet has columns, before the record is built', function (string $text): void {
        expect(fn () => importRead(importFile($text), ImportFormat::Csv))
            ->toThrow(fn (ImportFileException $failure) => expect($failure->reason)->toBe(ImportFileException::TOO_MANY_COLUMNS));
    })->with([
        'separators' => ['a'.str_repeat(',', ImportWidthGuard::SPREADSHEET_COLUMNS)."\r\n"],
        'a quote that does not open a field does not hide the separators' => ['a"b'.str_repeat(',', ImportWidthGuard::SPREADSHEET_COLUMNS)."\r\n"],
        'a field closed by its quote, and the separators after it' => ['"a"'.str_repeat(',', ImportWidthGuard::SPREADSHEET_COLUMNS)."\r\n"],
        'a doubled quote inside quotes does not close the field' => ['"a""b",'.str_repeat(',', ImportWidthGuard::SPREADSHEET_COLUMNS)."\r\n"],
        'after lines that are not wide' => ["a,b\nc,d\n".str_repeat(',', ImportWidthGuard::SPREADSHEET_COLUMNS)],
        'spaces before the quote' => ['  "a"'.str_repeat(',', ImportWidthGuard::SPREADSHEET_COLUMNS)],
    ]);

    it('lets through a record that holds its separators in quotes, and rows that end with a few empty fields', function (): void {
        $commas = str_repeat(',', ImportWidthGuard::SPREADSHEET_COLUMNS + 10);
        $path = importFile("Name,Notes\r\n\"{$commas}\",x\r\n\"a\"\"{$commas}\",y\r\nz,w".str_repeat(',', 300)."\r\n");

        $rows = importRead($path, ImportFormat::Csv)['rows'];

        expect($rows[2])->toBe([$commas, 'x'])
            ->and($rows[3])->toBe(['a"'.$commas, 'y'])
            ->and($rows[4])->toBe(['z', 'w']);
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

    it('counts the fields of a record with the separator it is given, and judges the width against the limit of the application', function (): void {
        $line = fn (int $fields, string $separator): string => implode($separator, array_fill(0, $fields, 'x'))."\n";

        foreach ([',', ';', "\t"] as $separator) {
            expect(fn () => ImportWidthGuard::csv(importFile($line(ImportWidthGuard::SPREADSHEET_COLUMNS, $separator)), $separator))->not->toThrow(ImportFileException::class)
                ->and(fn () => ImportWidthGuard::csv(importFile($line(ImportWidthGuard::SPREADSHEET_COLUMNS + 1, $separator)), $separator))->toThrow(ImportFileException::class)
                // The other characters are text.
                ->and(fn () => ImportWidthGuard::csv(importFile($line(ImportWidthGuard::SPREADSHEET_COLUMNS + 1, $separator === ',' ? ';' : ',')), $separator))->not->toThrow(ImportFileException::class);
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
        'a cell far to the right' => ['<c r="B1"', '<c r="ZZZZZZ1"'],
        'a cell past the last column' => ['<c r="B1"', '<c r="XFE1"'],
    ]);

    it('is read when it is as wide as a sheet can be', function (): void {
        config(['inventories.import_max_columns' => 20000]);
        $path = importPatch(importSpreadsheet('xlsx', [['a', 'b']]), 'xl/worksheets/sheet1.xml', fn (string $xml): string => str_replace(['<dimension ref="A1:B1"/>', 'spans="1:2"', '<c r="B1"'], ['<dimension ref="A1:XFD1"/>', 'spans="1:16384"', '<c r="XFD1"'], $xml));

        $row = importRead($path, ImportFormat::Xlsx)['rows'][1];

        expect($row[0])->toBe('a')
            ->and($row[16383])->toBe('b');
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
        // recent versions) and Excel write it.
        $path = importPatch(importSpreadsheet('ods', [['a', 'b'], ['c', 'd']]), 'content.xml', fn (string $xml): string => str_replace('</table:table-row>', '<table:table-cell table:number-columns-repeated="'.$padding.'"/></table:table-row>', $xml));

        expect(importRead($path, ImportFormat::Ods)['rows'])->toBe([1 => ['a', 'b'], 2 => ['c', 'd']]);
    })->with([
        'a sheet of 1 024 columns' => [1022, 1024],
        'a sheet of 16 384 columns' => [16382, 16384],
        'a little less' => [16000, 16002],
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
