<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Exceptions\ImportFileException;
use App\Inventories\Exports\CsvWriter;
use App\Inventories\Exports\ExportTemp;

/**
 * What it takes to read a text file of delimited values: which encoding it is in, which character separates the values, and a copy
 * of it in UTF-8 when it is in another encoding.
 *
 * Spreadsheets save a CSV in the encoding and with the separator of the language of the computer: a French Excel writes Windows-1252
 * and semicolons, a Unicode text export is UTF-16 with tabs, most other tools write UTF-8 with commas. None of it is announced, so
 * it is guessed, and the person can correct the guess ({@see ImportOptions}).
 *
 * The encoding is, in this order: UTF-16 when the file starts with its byte order mark, UTF-8 when the whole file is valid UTF-8
 * (a Windows-1252 text with accents almost never is), Windows-1252 otherwise. The separator is the one of `; , tab` that appears
 * the same number of times, outside quotes, on each of the first lines (the most frequent when several do), else the most frequent
 * on the first line.
 */
final class ImportCsv
{
    /** The beginning of the file the separator is looked for in, in bytes. */
    private const SAMPLE_BYTES = 65536;

    /** The lines of the beginning of the file that tell the separator. */
    private const SAMPLE_LINES = 5;

    /** Bytes read at a time to check that a file is UTF-8, or to convert it. */
    private const CHUNK_BYTES = 1048576;

    /** The first bytes of a file that tell it is not text. */
    private const BINARY_PROBE_BYTES = 8192;

    /** The characters of the separators, by the name the options give them, in the order they win a tie. */
    private const CANDIDATES = ['semicolon', 'comma', 'tab'];

    /**
     * Works out how to read a file: the encoding and the separator (the ones given are kept), and the path of a UTF-8 copy to read from
     * when the file is not UTF-8 (otherwise the file itself, whose byte order mark the reader skips).
     *
     * @param  string|null  $encoding  One of {@see ImportOptions::ENCODINGS}, or null to detect it.
     * @param  string|null  $delimiter  A key of {@see CsvWriter::DELIMITERS}, or null to detect it.
     * @return array{encoding: string, delimiter: string, path: string, scratch: string|null} `scratch` is the copy to delete when done, if one was made.
     *
     * @throws ImportFileException When the file is not text, or empty.
     */
    public static function prepare(string $path, ?string $encoding, ?string $delimiter): array
    {
        $size = filesize($path);

        if ($size === false) {
            throw new ImportFileException(ImportFileException::UNREADABLE);
        }

        if ($size === 0) {
            throw new ImportFileException(ImportFileException::EMPTY);
        }

        $bom = self::byteOrderMark($path);
        $encoding ??= $bom === 'utf-16' ? 'utf-16' : (self::isUtf8($path) ? 'utf-8' : 'windows-1252');

        if ($encoding !== 'utf-16' && str_contains((string) file_get_contents($path, false, null, 0, self::BINARY_PROBE_BYTES), "\0")) {
            // A text file has no NUL byte: this is an image, an archive, a legacy spreadsheet...
            throw new ImportFileException(ImportFileException::UNRECOGNIZED);
        }

        $scratch = null;
        $usable = $path;

        if ($encoding !== 'utf-8') {
            $scratch = self::convert($path, $encoding);
            $usable = $scratch;
        }

        try {
            $delimiter ??= self::detectDelimiter((string) file_get_contents($usable, false, null, 0, self::SAMPLE_BYTES));
        } catch (\Throwable $failure) {
            if ($scratch !== null) {
                @unlink($scratch);
            }

            throw $failure;
        }

        return ['encoding' => $encoding, 'delimiter' => $delimiter, 'path' => $usable, 'scratch' => $scratch];
    }

    /**
     * Which separator a text uses, from its first lines (see the class).
     *
     * @return string A key of {@see CsvWriter::DELIMITERS}.
     */
    public static function detectDelimiter(string $sample): string
    {
        $sample = str_starts_with($sample, "\u{FEFF}") ? substr($sample, 3) : $sample;
        $characters = ['semicolon' => ';', 'comma' => ',', 'tab' => "\t"];
        $lines = [];
        $line = array_fill_keys(self::CANDIDATES, 0);
        $started = false;
        $quoted = false;
        $length = strlen($sample);

        for ($position = 0; $position < $length && count($lines) < self::SAMPLE_LINES; $position++) {
            $character = $sample[$position];

            if ($character === '"') {
                $quoted = ! $quoted;
                $started = true;

                continue;
            }

            if (! $quoted && ($character === "\n" || $character === "\r")) {
                if ($started) {
                    $lines[] = $line;
                    $line = array_fill_keys(self::CANDIDATES, 0);
                    $started = false;
                }

                continue;
            }

            $started = true;

            if (! $quoted) {
                foreach ($characters as $name => $candidate) {
                    if ($character === $candidate) {
                        $line[$name]++;
                    }
                }
            }
        }

        // A last line without a line break counts when the sample is the whole file (or the only line there is); cut short by the size
        // of the sample, it would have fewer separators than the others.
        if ($started && count($lines) < self::SAMPLE_LINES && ($lines === [] || $length < self::SAMPLE_BYTES)) {
            $lines[] = $line;
        }

        $best = null;
        $bestCount = 0;

        foreach (self::CANDIDATES as $name) {
            $first = $lines[0][$name] ?? 0;

            if ($first === 0) {
                continue;
            }

            foreach ($lines as $counted) {
                if ($counted[$name] !== $first) {
                    continue 2;
                }
            }

            if ($first > $bestCount) {
                $best = $name;
                $bestCount = $first;
            }
        }

        if ($best !== null) {
            return $best;
        }

        // No separator splits every line the same way: the most frequent on the header, a comma when there is none.
        $mostOften = 'comma';
        $mostCount = 0;

        foreach (self::CANDIDATES as $name) {
            if (($lines[0][$name] ?? 0) > $mostCount) {
                $mostOften = $name;
                $mostCount = $lines[0][$name];
            }
        }

        return $mostOften;
    }

    /**
     * Whether the whole file is valid UTF-8 (read a megabyte at a time, so that a large file is not held in memory).
     */
    public static function isUtf8(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new ImportFileException(ImportFileException::UNREADABLE);
        }

        try {
            $carry = '';

            while (! feof($handle)) {
                $chunk = $carry.(string) fread($handle, self::CHUNK_BYTES);
                $carry = '';

                if (! feof($handle)) {
                    // The end of the chunk may cut a character in two: the cut part is checked with the next chunk.
                    $length = strlen($chunk);

                    for ($back = 1; $back <= 3 && $back <= $length; $back++) {
                        $byte = ord($chunk[$length - $back]);

                        if ($byte < 0x80) {
                            break;
                        }

                        if ($byte >= 0xC0) {
                            $needed = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                            if ($back < $needed) {
                                $carry = substr($chunk, $length - $back);
                                $chunk = substr($chunk, 0, $length - $back);
                            }

                            break;
                        }
                    }
                }

                if (! mb_check_encoding($chunk, 'UTF-8')) {
                    return false;
                }
            }

            return mb_check_encoding($carry, 'UTF-8');
        } finally {
            fclose($handle);
        }
    }

    /**
     * Writes a UTF-8 copy of a file that is in another encoding, in the scratch folder of the imports, and returns its path. The byte
     * order mark is left out.
     *
     * @throws ImportFileException When the file cannot be read or the copy cannot be written.
     */
    private static function convert(string $path, string $encoding): string
    {
        $copy = ExportTemp::file('imports', 'import-');
        $source = fopen($path, 'rb');
        $target = fopen($copy, 'wb');

        try {
            if ($source === false || $target === false) {
                throw new ImportFileException(ImportFileException::UNREADABLE);
            }

            if ($encoding === 'utf-16') {
                // Two bytes a character, four for some: converted whole (an import is at most a few megabytes).
                $raw = (string) stream_get_contents($source);
                $bigEndian = str_starts_with($raw, "\xFE\xFF");
                $raw = str_starts_with($raw, "\xFF\xFE") || $bigEndian ? substr($raw, 2) : $raw;

                fwrite($target, mb_convert_encoding($raw, 'UTF-8', $bigEndian ? 'UTF-16BE' : 'UTF-16LE'));
            } else {
                // One byte a character: any cut between two chunks is clean.
                while (! feof($source)) {
                    fwrite($target, mb_convert_encoding((string) fread($source, self::CHUNK_BYTES), 'UTF-8', 'Windows-1252'));
                }
            }

            return $copy;
        } catch (\Throwable $failure) {
            @unlink($copy);

            throw $failure;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($target)) {
                fclose($target);
            }
        }
    }

    /**
     * Whether the file starts with the byte order mark of UTF-16 (`utf-16`), of UTF-8 (`utf-8`), or none.
     */
    private static function byteOrderMark(string $path): ?string
    {
        $start = (string) file_get_contents($path, false, null, 0, 3);

        return match (true) {
            str_starts_with($start, "\xEF\xBB\xBF") => 'utf-8',
            str_starts_with($start, "\xFF\xFE"), str_starts_with($start, "\xFE\xFF") => 'utf-16',
            default => null,
        };
    }
}
