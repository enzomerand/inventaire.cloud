<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Exceptions\ImportFileException;
use XMLReader;
use ZipArchive;

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
 * Each scan counts what the library would build, down to its shortcuts: in an OpenDocument sheet a row with nothing in it gets one
 * cell however many it repeats, and a trailing cell that completes the width of an Excel sheet is not expanded.
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
     * @throws ImportFileException When a row would be wider than the limit.
     */
    public static function ods(string $path): void
    {
        $limit = self::limit();
        $row = null;

        self::read('zip://'.$path.'#content.xml', function (XMLReader $xml) use (&$row, $limit): void {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'table:table-row') {
                // Cells before the last one, the last one (repeats and whether it holds something), and whether any holds something.
                $row = $xml->isEmptyElement ? null : ['before' => 0, 'last' => null, 'filled' => false];

                return;
            }

            if ($row === null) {
                return;
            }

            if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'table:table-cell') {
                $repeats = max(0, (int) ($xml->getAttribute('table:number-columns-repeated') ?? 1));
                $filled = $xml->getAttribute('office:value-type') !== null || ! $xml->isEmptyElement;

                if ($row['last'] !== null) {
                    $row['before'] += $row['last']['repeats'];

                    if ($row['before'] > $limit) {
                        throw new ImportFileException(ImportFileException::TOO_MANY_COLUMNS);
                    }
                }

                $row['last'] = ['repeats' => $repeats, 'filled' => $filled];
                $row['filled'] = $row['filled'] || $filled;

                return;
            }

            if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->name === 'table:table-row') {
                if ($row['last'] !== null) {
                    // As the library builds the last cell: once for a row with nothing in it, not at all when it completes the width of
                    // an Excel sheet (such a row is padded with it by the application, and stays as wide as the cells that hold something).
                    $last = ! $row['filled'] ? 1 : ($row['before'] + $row['last']['repeats'] === self::SPREADSHEET_COLUMNS ? 0 : $row['last']['repeats']);

                    if ($limit < $row['before'] + $last) {
                        throw new ImportFileException(ImportFileException::TOO_MANY_COLUMNS);
                    }
                }

                $row = null;
            }
        });
    }

    /**
     * Checks the worksheets of an Excel workbook: the dimension a sheet announces, the width its rows announce, and the column of each cell.
     *
     * @throws ImportFileException When a sheet would be filled to a width beyond the limit.
     */
    public static function xlsx(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportFileException(ImportFileException::UNRECOGNIZED);
        }

        $parts = [];

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                // The library reads the sheets under `xl/`, wherever the workbook says they are there.
                if (str_starts_with($name, 'xl/') && str_ends_with(strtolower($name), '.xml')) {
                    $parts[] = $name;
                }
            }
        } finally {
            $zip->close();
        }

        $limit = self::limit();

        foreach ($parts as $part) {
            self::read('zip://'.$path.'#'.$part, function (XMLReader $xml) use ($limit): void {
                if ($xml->nodeType !== XMLReader::ELEMENT) {
                    return;
                }

                $reference = match ($xml->localName) {
                    // `A1:M13`: the library fills every row with as many cells as the last column.
                    'dimension' => preg_match('/[A-Z]+\d+:([A-Z]+)\d+/', (string) $xml->getAttribute('ref'), $found) === 1 ? $found[1] : null,
                    // `1:5`, when the sheet gives no dimension.
                    'row' => preg_match('/:(\d+)$/', (string) $xml->getAttribute('spans'), $found) === 1 ? (int) $found[1] : null,
                    'c' => preg_match('/^([A-Za-z]+)\d*$/', (string) $xml->getAttribute('r'), $found) === 1 ? $found[1] : null,
                    default => null,
                };

                if (is_int($reference) ? $reference > $limit : ($reference !== null && self::columnsOf($reference) > $limit)) {
                    throw new ImportFileException(ImportFileException::TOO_MANY_COLUMNS);
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
