<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Exceptions\ImportFileException;
use App\Inventories\Exports\CsvWriter;
use App\Inventories\Exports\ExportTemp;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\CSV\Sheet as CsvSheet;
use OpenSpout\Reader\ODS\Options as OdsOptions;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ODS\Sheet as OdsSheet;
use OpenSpout\Reader\SheetWithVisibilityInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\XLSX\Sheet as XlsxSheet;
use Throwable;

/**
 * The rows of an opened import file, one at a time ({@see ImportReader::open()}).
 *
 * The sheet read is the one that was active when the spreadsheet was saved, else the first visible one; a CSV has one. A row is a
 * list of {@see ImportCell}, the empty ones at its end cut off, and is given with its number in the file, counted from 1 with the
 * empty rows counted too: the number an error is reported with is the one the person sees in their spreadsheet. A row with nothing in
 * it is not given.
 *
 * A formula is read as its calculated value, or as the text of the formula when the file holds none. In a CSV, the apostrophe that the
 * export puts in front of a text a spreadsheet would run as a formula (it starts with `=`, `+`, `-`, `@`, a tab or a return) is taken
 * off again, and a NUL character, which a database refuses, is dropped.
 *
 * Holds a copy of a CSV that is not in UTF-8 until {@see self::close()}, which is called when the object is released.
 */
final class ImportRows
{
    /** The characters that, after an apostrophe, mean the apostrophe was put there by an export. */
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /** Rows of the file, empty ones included, that are read per row of the limit before the file is taken for a runaway. */
    private const SCAN_FACTOR = 10;

    private bool $closed = false;

    /**
     * @param  string  $path  The file to read: the one of the import, or its copy in UTF-8.
     * @param  string|null  $delimiter  A key of the delimiters, for a CSV.
     * @param  string|null  $encoding  The encoding the file was found in or given, for a CSV (informative: it is read from `$path` in UTF-8).
     * @param  string|null  $scratch  A copy to delete when done.
     */
    public function __construct(
        private readonly string $path,
        public readonly ImportFormat $format,
        public readonly ?string $delimiter,
        public readonly ?string $encoding,
        private ?string $scratch,
    ) {}

    /**
     * Deletes the copy of the file, if one was made.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Releases what the object holds: the copy of a CSV that is not in UTF-8.
     */
    public function close(): void
    {
        if ($this->scratch !== null) {
            @unlink($this->scratch);
            $this->scratch = null;
        }

        $this->closed = true;
    }

    /**
     * The rows that have something in them, by their number in the file.
     *
     * @return Generator<int, list<ImportCell>>
     *
     * @throws ImportFileException When the file cannot be read, or a row uses too many columns.
     */
    public function rows(): Generator
    {
        if ($this->closed) {
            throw new ImportFileException(ImportFileException::UNREADABLE);
        }

        $reader = $this->reader();
        $columns = config()->integer('inventories.import_max_columns');
        $scanned = config()->integer('inventories.import_max_rows') * self::SCAN_FACTOR + 1000;

        try {
            $reader->open($this->path);
            $sheet = $this->sheet($reader);
            $number = 0;

            foreach ($sheet->getRowIterator() as $row) {
                $number++;
                $cells = $this->cells($row->cells);

                if (count($cells) > $columns) {
                    throw new ImportFileException(ImportFileException::TOO_MANY_COLUMNS);
                }

                if ($cells === []) {
                    if ($number > $scanned) {
                        throw new ImportFileException(ImportFileException::TOO_MANY_ROWS);
                    }

                    continue;
                }

                yield $number => $cells;
            }
        } catch (ImportFileException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new ImportFileException(ImportFileException::UNREADABLE, $failure);
        } finally {
            try {
                $reader->close();
            } catch (Throwable) {
                // Nothing more can be done for a file that is being given up on.
            }
        }
    }

    /**
     * The reader of the format, with the options that give exact row numbers.
     */
    private function reader(): CsvReader|OdsReader|XlsxReader
    {
        return match ($this->format) {
            ImportFormat::Xlsx => new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true, tempFolder: $this->scratchFolder())),
            ImportFormat::Ods => new OdsReader(new OdsOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)),
            ImportFormat::Csv => new CsvReader(new CsvOptions(
                SHOULD_PRESERVE_EMPTY_ROWS: true,
                FIELD_DELIMITER: CsvWriter::DELIMITERS[$this->delimiter ?? 'comma'] ?? ',',
            )),
        };
    }

    /**
     * The sheet to read: the active one, else the first visible one, else the first.
     *
     * @throws ImportFileException When the file holds no sheet.
     */
    private function sheet(CsvReader|OdsReader|XlsxReader $reader): CsvSheet|OdsSheet|XlsxSheet
    {
        $first = null;
        $visible = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            $first ??= $sheet;
            $shown = ! $sheet instanceof SheetWithVisibilityInterface || $sheet->isVisible();

            if (! $shown) {
                continue;
            }

            $visible ??= $sheet;

            if ($sheet instanceof SheetWithVisibilityInterface && $sheet->isActive()) {
                return $sheet;
            }
        }

        return $visible ?? $first ?? throw new ImportFileException(ImportFileException::UNREADABLE);
    }

    /**
     * The folder the library that reads a spreadsheet keeps its scratch files in.
     *
     * @return non-empty-string
     */
    private function scratchFolder(): string
    {
        $folder = ExportTemp::directory('imports');

        return $folder !== '' ? $folder : throw new ImportFileException(ImportFileException::UNREADABLE);
    }

    /**
     * The cells of a row as cells of the import, the empty ones at the end cut off.
     *
     * @param  array<int, Cell>  $row
     * @return list<ImportCell>
     */
    private function cells(array $row): array
    {
        $cells = [];

        foreach ($row as $cell) {
            $cells[] = new ImportCell($this->value($cell), $this->format === ImportFormat::Csv);
        }

        while ($cells !== [] && $cells[array_key_last($cells)]->isEmpty()) {
            array_pop($cells);
        }

        return $cells;
    }

    /**
     * What a cell of the library holds, as a value of the import.
     */
    private function value(Cell $cell): string|int|float|bool|DateTimeImmutable|DateInterval|null
    {
        if ($cell instanceof EmptyCell) {
            return null;
        }

        $value = $cell instanceof FormulaCell ? ($cell->getComputedValue() ?? $cell->getValue()) : $cell->getValue();

        return match (true) {
            is_string($value) => $this->text($value),
            is_int($value), is_float($value), is_bool($value), $value instanceof DateInterval => $value,
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value),
            default => null,
        };
    }

    /**
     * A text of the file made safe: valid UTF-8, no NUL, and (CSV) without the apostrophe of an export.
     */
    private function text(string $text): string
    {
        $text = str_replace("\0", '', mb_scrub($text));

        if ($this->format === ImportFormat::Csv && strlen($text) >= 2 && $text[0] === "'" && in_array($text[1], self::FORMULA_STARTS, true)) {
            return substr($text, 1);
        }

        return $text;
    }
}
