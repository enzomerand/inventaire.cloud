<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Exceptions\ImportFileException;
use XMLReader;

/**
 * Refuses a file with a row so wide that reading it would exhaust the memory, before the library that reads the file builds that row.
 *
 * The library expands a row to its full width before giving it: a cell of an OpenDocument sheet that says it is repeated fifty million
 * times becomes fifty million cells, a worksheet whose dimension or whose `spans` claim as many columns is filled with as many, a cell
 * of a worksheet whose reference is `ZZZZZZ1` is padded with every column before it, and a text file of ten megabytes of separators is
 * ten million fields. The width the import accepts ({@see ImportRows}) is judged on the row once it is built, which is too late, so each
 * format is read here first, as a stream and without building anything, and a file is refused as soon as a row would be wider than
 * {@see self::limit()}.
 *
 * That limit is the number of columns of a spreadsheet application, or the import's own if it is larger: spreadsheets pad their rows
 * with a trailing empty cell that is repeated up to the width of the sheet (1 024 columns in older versions of LibreOffice, 16 384 in
 * Excel and recent ones), and a text file may end its rows with separators, so a row is only refused here for what it would take to
 * hold it, not for the columns that hold something (that is judged later, once the empty cells at the end of the row are cut off).
 *
 * The guard does not follow the library case by case: it bounds what the library can be made to build, and refuses what it cannot bound.
 *
 * - An Excel sheet is whatever the workbook says is one (its relationships, followed as the library follows them: a sheet may be named
 *   anything, in any folder of `xl/`), and no other part: the list of the formulas of a workbook holds a `<c>` for each of them, and is no
 *   sheet. In a sheet, two things are bounded between one `<row>` and the next: how many `<c>` there are, and the highest column any of
 *   them is at, a cell with no reference being the one after the last. The dimension and the `spans` a sheet announces are bounded too.
 * - An OpenDocument sheet is refused unless it is built the way a sheet is: rows hold the cells, and cells hold no row. The library keeps
 *   what it gathered across a row that opens in the middle of another, and cells that are in no row, and a guard that tried to follow
 *   that would have to be as wrong as the library is. The cells of a row are counted down to the shortcuts the library takes: a row
 *   with nothing in it gets one cell however many it repeats, and a trailing cell that completes the width of an Excel sheet is not
 *   expanded.
 */
final class ImportWidthGuard
{
    /** The columns of a sheet in Excel and in LibreOffice (`XFD`): no real row is wider. */
    public const SPREADSHEET_COLUMNS = 16384;

    /** The most letters of a column in a cell reference: `XFD` is the last column, `ZZZ` the last that three letters can name. */
    private const MAX_LETTERS = 3;

    /**
     * The widest row, in cells, that is let through.
     */
    public static function limit(): int
    {
        return max(config()->integer('inventories.import_max_columns'), self::SPREADSHEET_COLUMNS);
    }

    /**
     * Checks the sheets of an OpenDocument spreadsheet.
     *
     * @throws ImportFileException When a row would be wider than the limit, or the rows and the cells are not built as those of a sheet.
     */
    public static function ods(string $path): void
    {
        $limit = self::limit();
        // Whether a row is open, the cells counted in it so far, how many times the last one seen is repeated (it is counted once the
        // next one comes, or at the end of the row, whose rules differ), and whether any cell holds something.
        $inRow = false;
        $before = 0;
        $last = null;
        $filled = false;

        self::read('zip://'.$path.'#content.xml', function (XMLReader $xml) use (&$inRow, &$before, &$last, &$filled, $limit): void {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'table:table-row') {
                // A row with no content (`<table:table-row table:number-rows-repeated="3"/>`) has no end to wait for. A row that starts in
                // another one ends the first: the end of the other, or a cell before it, finds no row open.
                $inRow = ! $xml->isEmptyElement;
                $before = 0;
                $last = null;
                $filled = false;

                return;
            }

            if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'table:table-cell') {
                if (! $inRow) {
                    throw new ImportFileException(ImportFileException::UNREADABLE);
                }

                $repeats = max(0, (int) ($xml->getAttribute('table:number-columns-repeated') ?? 1));
                $holds = $xml->getAttribute('office:value-type') !== null || ! $xml->isEmptyElement;

                if ($last !== null) {
                    $before += $last;

                    if ($before > $limit) {
                        throw new ImportFileException(ImportFileException::TOO_MANY_COLUMNS);
                    }
                }

                $last = $repeats;
                $filled = $filled || $holds;

                return;
            }

            if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->name === 'table:table-row') {
                if (! $inRow) {
                    throw new ImportFileException(ImportFileException::UNREADABLE);
                }

                if ($last !== null) {
                    // As the library builds the last cell: once for a row with nothing in it, not at all when it completes the width of
                    // an Excel sheet (such a row is padded with it by the application, and stays as wide as the cells that hold something).
                    $final = ! $filled ? 1 : ($before + $last === self::SPREADSHEET_COLUMNS ? 0 : $last);

                    if ($limit < $before + $final) {
                        throw new ImportFileException(ImportFileException::TOO_MANY_COLUMNS);
                    }
                }

                $inRow = false;
            }
        });
    }

    /**
     * Checks the worksheets of an Excel workbook: the dimension a sheet announces, the width its rows announce, and the cells of each row,
     * counted and placed as the library places them.
     *
     * @throws ImportFileException When a sheet would be filled to a width beyond the limit.
     */
    public static function xlsx(string $path): void
    {
        $limit = self::limit();

        foreach (self::sheets($path) as $part) {
            // The cells of the row being read, and the column of the last one: a cell with no reference is put after it.
            $cells = 0;
            $last = -1;

            self::read('zip://'.$path.'#'.$part, function (XMLReader $xml) use (&$cells, &$last, $limit): void {
                if ($xml->nodeType !== XMLReader::ELEMENT) {
                    return;
                }

                switch ($xml->localName) {
                    case 'dimension':
                        // `A1:M13`: the library fills every row with as many cells as the last column.
                        if (preg_match('/[A-Z]+\d+:([A-Z]+)\d+/', (string) $xml->getAttribute('ref'), $found) === 1) {
                            self::assertColumns(self::columnsOf($found[1]), $limit);
                        }

                        break;
                    case 'row':
                        // The library starts a list of cells again, and counts the columns from the first.
                        $cells = 0;
                        $last = -1;
                        // `1:5`, when the sheet gives no dimension: the library takes what follows the first colon, whatever else there is.
                        self::assertColumns((int) (explode(':', (string) $xml->getAttribute('spans'))[1] ?? 0), $limit);

                        break;
                    case 'c':
                        $cells++;
                        $reference = (string) $xml->getAttribute('r');
                        // A reference the library cannot read stops it; one it reads puts the cell at its column, none puts it after the last.
                        $column = preg_match('/^([A-Za-z]+)\d*$/', $reference, $found) === 1 ? self::columnsOf($found[1]) - 1 : $last + 1;
                        $last = $column;

                        self::assertColumns($cells, $limit);
                        self::assertColumns($column + 1, $limit);

                        break;
                }
            });
        }
    }

    /**
     * Checks the records of a text file in UTF-8, counting the fields of each as the library that reads it would split them.
     *
     * The rules are those of `fgetcsv` with no escape character: a field is quoted when its first character, spaces apart, is a
     * quote; it then ends at a quote that is not doubled, and whatever follows, up to the next separator, is part of it; a quote in
     * the middle of a field that does not start with one is only a quote. A record ends at a line feed outside quotes (a lone carriage
     * return does not end one).
     *
     * @param  string  $delimiter  The character that separates the fields.
     *
     * @throws ImportFileException When a record has more fields than the limit.
     */
    public static function csv(string $path, string $delimiter): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new ImportFileException(ImportFileException::UNREADABLE);
        }

        $limit = self::limit();
        // Where the next character is: at the start of a field, in a field that is not quoted, inside quotes (`$quote`: the last character
        // read was a quote, and the next tells whether it closes the field), or after the closing quote.
        $state = 'start';
        $quote = false;
        $fields = 1;
        $spaces = str_replace($delimiter, '', " \t\v\f\r");
        $plain = $delimiter."\n";

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 1048576);

                if ($chunk === false) {
                    throw new ImportFileException(ImportFileException::UNREADABLE);
                }

                $length = strlen($chunk);
                $position = 0;

                while ($position < $length) {
                    if ($state === 'quoted') {
                        if ($quote) {
                            // The last character of the chunk before was a quote: a second one is a quote in the text, anything else closes the field.
                            $quote = false;

                            if ($chunk[$position] === '"') {
                                $position++;
                            } else {
                                $state = 'after';
                            }

                            continue;
                        }

                        $found = strpos($chunk, '"', $position);

                        if ($found === false) {
                            break;
                        }

                        $position = $found + 1;

                        if ($position === $length) {
                            $quote = true;
                        } elseif ($chunk[$position] === '"') {
                            $position++;
                        } else {
                            $state = 'after';
                        }

                        continue;
                    }

                    if ($state === 'start') {
                        $position += strspn($chunk, $spaces, $position);

                        if ($position >= $length) {
                            break;
                        }

                        $character = $chunk[$position];

                        if ($character === '"') {
                            $state = 'quoted';
                            $position++;
                        } elseif ($character === $delimiter) {
                            $fields++;
                            $position++;
                        } elseif ($character === "\n") {
                            $fields = 1;
                            $position++;
                        } else {
                            $state = 'plain';
                        }
                    } else {
                        // `plain` and `after`: up to the next separator or line feed.
                        $position += strcspn($chunk, $plain, $position);

                        if ($position >= $length) {
                            break;
                        }

                        $fields = $chunk[$position] === "\n" ? 1 : $fields + 1;
                        $state = 'start';
                        $position++;
                    }

                    if ($fields > $limit) {
                        throw new ImportFileException(ImportFileException::TOO_MANY_COLUMNS);
                    }
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The parts of an Excel workbook the library reads as sheets: the targets of the relationships the sheets of the workbook point to,
     * named as the library names them (relative to `xl/`, unless the target already starts there; the leading slash is dropped).
     *
     * Every relationship with the identifier of a sheet is taken, not only the one the library ends up with, so that a workbook that
     * lists an identifier twice cannot have one of the two read unseen.
     *
     * @return list<string>
     */
    private static function sheets(string $path): array
    {
        $identifiers = [];

        self::read('zip://'.$path.'#xl/workbook.xml', function (XMLReader $xml) use (&$identifiers): void {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'sheet') {
                $identifiers[(string) $xml->getAttribute('r:id')] = true;
            }
        });

        $parts = [];

        self::read('zip://'.$path.'#xl/_rels/workbook.xml.rels', function (XMLReader $xml) use ($identifiers, &$parts): void {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'Relationship' && isset($identifiers[(string) $xml->getAttribute('Id')])) {
                $target = (string) $xml->getAttribute('Target');
                $parts[ltrim(str_starts_with($target, '/xl/') ? $target : '/xl/'.$target, '/')] = true;
            }
        });

        return array_map(strval(...), array_keys($parts));
    }

    /**
     * @throws ImportFileException When a width is beyond the limit.
     */
    private static function assertColumns(int $columns, int $limit): void
    {
        if ($columns > $limit) {
            throw new ImportFileException(ImportFileException::TOO_MANY_COLUMNS);
        }
    }

    /**
     * Walks the nodes of an XML file, as a stream.
     *
     * A file that is not well-formed ends the walk quietly: the library that reads it for real is the one that fails on it, and it
     * meets the nodes in the same order, so what was dangerous before the mistake has been seen by then.
     *
     * @param  callable(XMLReader): void  $visit
     */
    private static function read(string $uri, callable $visit): void
    {
        $xml = new XMLReader;
        $previous = libxml_use_internal_errors(true);

        try {
            if (@$xml->open($uri, null, LIBXML_NONET) === false) {
                return;
            }

            try {
                while (@$xml->read()) {
                    $visit($xml);
                }
            } finally {
                $xml->close();
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * The number of columns a column reference names up to and including it (`A` is 1, `XFD` is 16 384); more than the limit can hold for
     * a reference of more letters than a column has.
     */
    private static function columnsOf(string $letters): int
    {
        if (strlen($letters) > self::MAX_LETTERS) {
            return PHP_INT_MAX;
        }

        $columns = 0;

        foreach (str_split(strtoupper($letters)) as $letter) {
            $columns = $columns * 26 + ord($letter) - 64;
        }

        return $columns;
    }
}
