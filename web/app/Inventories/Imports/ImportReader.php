<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Exceptions\ImportFileException;
use App\Inventories\Exports\CsvWriter;
use Throwable;
use ZipArchive;

/**
 * Opens the file of an import to read its rows: a spreadsheet (XLSX, ODS) or a text file of delimited values (CSV, TSV, TXT).
 *
 * Before anything is read from a spreadsheet, which is a zip archive, the archive is looked at: it must be one, hold the parts of the
 * format asked for, have a reasonable number of parts and weigh at most `inventories.import_max_unpacked_size` bytes once unpacked;
 * a few kilobytes of zip can unpack to gigabytes, and the library that reads the sheet does not stop at a size. The sizes the archive
 * announces ({@see ZipArchive::statIndex()}) cost nothing to read and are looked at first, but they are written by whoever made the
 * file and can say anything, so the parts are then unpacked, as a stream and into nothing, and the bytes that really come out are
 * counted. Then the width of the rows is checked ({@see ImportWidthGuard}), still before the library builds any of them. A text file is
 * checked for being text, then its encoding and separator are worked out ({@see ImportCsv}) and the width of its records is checked.
 *
 * The rows are read by {@see ImportRows}, one at a time.
 */
final class ImportReader
{
    /** Most parts a spreadsheet may hold: a real one has a few dozen, and a archive of thousands of tiny parts is not one. */
    public const MAX_PARTS = 10000;

    /** Bytes unpacked at a time to count the size of an archive. */
    private const BLOCK_BYTES = 65536;

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

            if ($format === ImportFormat::Xlsx) {
                ImportWidthGuard::xlsx($path);
            } else {
                ImportWidthGuard::ods($path);
            }

            return new ImportRows($path, $format, null, null, null);
        }

        $csv = ImportCsv::prepare($path, $encoding, $delimiter);
        $rows = new ImportRows($csv['path'], $format, $csv['delimiter'], $csv['encoding'], $csv['scratch']);

        try {
            ImportWidthGuard::csv($csv['path'], CsvWriter::DELIMITERS[$csv['delimiter']] ?? ',');
        } catch (Throwable $failure) {
            // The copy in UTF-8, if one was made, goes with the object.
            $rows->close();

            throw $failure;
        }

        return $rows;
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
            $declared = 0;

            // What the archive says, which costs nothing to read: enough to refuse the honest bomb at once.
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $part = $zip->statIndex($index);

                if ($part === false) {
                    throw new ImportFileException(ImportFileException::UNREADABLE);
                }

                $declared += (int) $part['size'];

                if ($declared > $limit) {
                    throw new ImportFileException(ImportFileException::UNPACKED_TOO_LARGE);
                }
            }

            $this->assertUnpackedSize($zip, $limit);

            $required = $format === ImportFormat::Xlsx ? 'xl/workbook.xml' : 'content.xml';

            if ($zip->locateName($required) === false) {
                throw new ImportFileException(ImportFileException::UNRECOGNIZED);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Unpacks every part of the archive, as a stream that is thrown away, and refuses it as soon as the bytes that came out are more
     * than the limit. The size a part announces is not trusted: the library gives what the compressed data holds, whatever the
     * announced size says.
     *
     * @throws ImportFileException When the parts weigh more than the limit once unpacked, or one cannot be unpacked (damaged, or protected by a password).
     */
    private function assertUnpackedSize(ZipArchive $zip, int $limit): void
    {
        $unpacked = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stream = $zip->getStreamIndex($index);

            if ($stream === false) {
                throw new ImportFileException(ImportFileException::UNREADABLE);
            }

            try {
                while (! feof($stream)) {
                    $block = fread($stream, self::BLOCK_BYTES);

                    if ($block === false) {
                        throw new ImportFileException(ImportFileException::UNREADABLE);
                    }

                    $unpacked += strlen($block);

                    if ($unpacked > $limit) {
                        throw new ImportFileException(ImportFileException::UNPACKED_TOO_LARGE);
                    }
                }
            } finally {
                fclose($stream);
            }
        }
    }
}
