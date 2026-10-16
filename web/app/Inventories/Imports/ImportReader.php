<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Exceptions\ImportFileException;
use App\Inventories\Exports\CsvWriter;
use ZipArchive;

/**
 * Opens the file of an import to read its rows: a spreadsheet (XLSX, ODS) or a text file of delimited values (CSV, TSV, TXT).
 *
 * Before anything is read from a spreadsheet, which is a zip archive, the archive is looked at: it must be one, hold the parts of the
 * format asked for, have a reasonable number of parts and weigh at most `inventories.import_max_unpacked_size` bytes once unpacked,
 * the sizes being those the archive announces ({@see ZipArchive::statIndex()}); a few kilobytes of zip can unpack to gigabytes, and
 * the library that reads the sheet does not stop at a size. A text file is checked for being text, then its encoding and separator
 * are worked out ({@see ImportCsv}).
 *
 * The rows are read by {@see ImportRows}, one at a time.
 */
final class ImportReader
{
    /** Most parts a spreadsheet may hold: a real one has a few dozen, and a archive of thousands of tiny parts is not one. */
    public const MAX_PARTS = 10000;

    /**
     * Opens a file for reading.
     *
     * @param  string|null  $delimiter  For a CSV, a key of {@see CsvWriter::DELIMITERS}, or null to detect it.
     * @param  string|null  $encoding  For a CSV, one of {@see ImportOptions::ENCODINGS}, or null to detect it.
     *
     * @throws ImportFileException When the file is not of the format its name says, is empty, or is too large once unpacked.
     */
    public function open(string $path, ImportFormat $format, ?string $delimiter = null, ?string $encoding = null): ImportRows
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ImportFileException(ImportFileException::UNREADABLE);
        }

        if ($format->isArchive()) {
            $this->assertArchive($path, $format);

            return new ImportRows($path, $format, null, null, null);
        }

        $csv = ImportCsv::prepare($path, $encoding, $delimiter);

        return new ImportRows($csv['path'], $format, $csv['delimiter'], $csv['encoding'], $csv['scratch']);
    }

    /**
     * Checks that a spreadsheet is a zip archive of the format, that is not a bomb.
     *
     * @throws ImportFileException
     */
    private function assertArchive(string $path, ImportFormat $format): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            // Not a zip: a legacy spreadsheet (XLS), a web page, a text renamed...
            throw new ImportFileException(ImportFileException::UNRECOGNIZED);
        }

        try {
            if ($zip->numFiles > self::MAX_PARTS) {
                throw new ImportFileException(ImportFileException::UNPACKED_TOO_LARGE);
            }

            $limit = config()->integer('inventories.import_max_unpacked_size');
            $unpacked = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $part = $zip->statIndex($index);

                if ($part === false) {
                    throw new ImportFileException(ImportFileException::UNREADABLE);
                }

                $unpacked += (int) $part['size'];

                if ($unpacked > $limit) {
                    throw new ImportFileException(ImportFileException::UNPACKED_TOO_LARGE);
                }
            }

            $required = $format === ImportFormat::Xlsx ? 'xl/workbook.xml' : 'content.xml';

            if ($zip->locateName($required) === false) {
                throw new ImportFileException(ImportFileException::UNRECOGNIZED);
            }
        } finally {
            $zip->close();
        }
    }
}
