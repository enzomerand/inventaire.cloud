<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\Attachments\FileNames;

/**
 * Names for what an export writes outside the application: the sheet of a workbook, the file of an export, the folders and files
 * of a zip. Each is cleaned up so that opening the file, or unzipping it, can never write outside the folder it is unzipped in, or
 * fail on a system that refuses a character.
 *
 * Lengths are counted in **bytes**, not characters: file systems limit a name in bytes (255 on most), and a name in Chinese or
 * emoji takes three or four of them for each character. A name is cut on a character boundary, never in the middle of one.
 */
final class ExportNames
{
    /** The longest name of a file, in bytes, whatever is added to it (the number of a duplicate, the date, the extension). */
    public const FILE_MAX_BYTES = 200;

    /** The longest label of the folder of an item in a zip, in bytes: the ULID of the item follows it (3 + 26 bytes more). */
    public const FOLDER_LABEL_MAX_BYTES = 150;

    /** The longest name of a sheet: Excel's limit. */
    public const SHEET_MAX_LENGTH = 31;

    /**
     * A name for a folder or a file: no separator (each becomes an underscore), no character Windows refuses (`: * ? " < > |`), no control or invisible
     * character, no `..`, no dot or space at either end, at most `$max` bytes (the extension of a file is kept). `$fallback`
     * when nothing is left.
     */
    public static function entry(string $name, string $fallback, int $max = self::FILE_MAX_BYTES): string
    {
        // A separator inside a name is a character of it ("Pen/pencil"), not a folder: it must not drop what comes before it.
        $clean = FileNames::sanitize(str_replace(['/', '\\'], '_', $name));
        // Nothing but invisible characters and separators is left: the name the attachments get then is not a name.
        $name = $clean === FileNames::FALLBACK && trim($name) !== FileNames::FALLBACK ? '' : $clean;
        $name = self::replace('/[:*?"<>|]+/u', '_', $name);
        $name = self::replace('/\.{2,}/u', '.', $name);
        $name = trim($name, " .\t");

        if ($name === '') {
            return $fallback;
        }

        if (strlen($name) <= $max) {
            return $name;
        }

        // A dot is one byte and never part of another character, so the byte position of the last one is safe to cut at.
        $dot = strrpos($name, '.');
        $extension = $dot === false || $dot === 0 || strlen($name) - $dot > 12 ? '' : substr($name, $dot);

        return rtrim(mb_strcut($name, 0, max(1, $max - strlen($extension)), 'UTF-8'), ' .').$extension;
    }

    /**
     * The name of a file in a folder that may already hold one of that name: `photo.jpg`, then `photo (2).jpg`, `photo (3).jpg`...
     * The names are compared whatever the case, as a system that does not keep it would. The number is part of the length: the
     * name is cut before it so that the whole stays within `$max` bytes.
     *
     * @param  array<string, true>  $taken  The names already used in the folder (lowercase), which this adds the new one to.
     */
    public static function unique(string $name, array &$taken, int $max = self::FILE_MAX_BYTES): string
    {
        $candidate = $name;
        $dot = mb_strrpos($name, '.');
        $stem = $dot === false || $dot === 0 ? $name : mb_substr($name, 0, $dot);
        $extension = $dot === false || $dot === 0 ? '' : mb_substr($name, $dot);

        for ($number = 2; isset($taken[mb_strtolower($candidate)]); $number++) {
            $suffix = " ({$number})";
            $room = max(1, $max - strlen($suffix) - strlen($extension));
            $candidate = (strlen($stem) > $room ? rtrim(mb_strcut($stem, 0, $room, 'UTF-8'), ' .') : $stem).$suffix.$extension;
        }

        $taken[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /**
     * The name of the sheet of a workbook, from the name of the inventory: at most 31 characters and none of `[ ] : * ? / \`, no
     * quote (Excel refuses one at either end, and a spreadsheet written for LibreOffice names the sheet inside a quoted address),
     * none of the characters XML needs escaped (the library writes the name in its files as it is in some places).
     *
     * @return non-empty-string
     */
    public static function sheet(string $name): string
    {
        $name = self::replace('/[\p{Cc}\p{Cf}\p{Co}\p{Zl}\p{Zp}]+/u', '', mb_scrub($name));
        $name = self::replace('/[\[\]:*?\/\\\\<>"]+/u', ' ', $name);
        $name = str_replace(["'", '&'], ['’', '+'], $name);
        $name = trim(self::replace('/\s+/u', ' ', $name));
        $name = rtrim(mb_substr($name, 0, self::SHEET_MAX_LENGTH));

        if ($name !== '') {
            return $name;
        }

        $fallback = __('Items');

        return is_string($fallback) && $fallback !== '' ? $fallback : 'Items';
    }

    /**
     * Applies a regular expression, and keeps the subject when it cannot (invalid UTF-8 is cleaned up before).
     */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }
}
