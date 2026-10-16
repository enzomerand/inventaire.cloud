<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

use Illuminate\Support\Str;
use Normalizer;

/**
 * What a file is called once it is attached: the name the client sent, cleaned up so that it is safe to show and to put in
 * a download header.
 *
 * The name is never part of a path on the disk (see {@see AttachmentStorage}), so what is done here is not what keeps files
 * where they belong; it is what keeps the name harmless wherever it is shown or sent: no folder (a client may send
 * `../../etc/passwd` or `C:\Users\me\photo.jpg`), no control character, no invisible or text-direction character (a name
 * written to read `invoice.pdf` while ending in `.exe`), no leading or trailing dot or space, valid UTF-8 in one Unicode
 * form, and at most 255 characters.
 */
final class FileNames
{
    /** Longest name, in characters: the size of the column. */
    public const MAX_LENGTH = 255;

    /** Longest extension that is kept when a long name is shortened. */
    private const MAX_EXTENSION_LENGTH = 20;

    /** What a name that is empty once cleaned up is called. */
    public const FALLBACK = 'file';

    /**
     * The name as it is kept.
     */
    public static function sanitize(string $name): string
    {
        // Only the last component: both kinds of separator are folders, whatever system the client runs on.
        $parts = explode('/', str_replace('\\', '/', $name));
        $name = mb_scrub((string) end($parts));

        $composed = Normalizer::normalize($name, Normalizer::FORM_C);
        $name = $composed === false ? $name : $composed;

        // Control, format (zero-width, text direction...), line and paragraph separators and private-use characters go;
        // the other kinds of space become a plain one, and runs of them one.
        $name = self::replace('/[\p{Cc}\p{Cf}\p{Co}\p{Zl}\p{Zp}]+/u', '', $name);
        $name = self::replace('/\p{Zs}+/u', ' ', $name);
        $name = self::replace('/^[\s.]+|[\s.]+$/u', '', $name);

        if ($name === '') {
            return self::FALLBACK;
        }

        return mb_strlen($name) > self::MAX_LENGTH ? self::shorten($name) : $name;
    }

    /**
     * An ASCII-only version of the name, for the `filename` parameter of `Content-Disposition` that old clients read (the
     * real name goes in `filename*`, RFC 6266).
     */
    public static function asciiFallback(string $name): string
    {
        $ascii = trim(self::replace('/[^A-Za-z0-9 ._()\-]+/', '_', Str::ascii($name)));

        if (trim($ascii, '._ ') === '') {
            return 'download';
        }

        // A name that began with characters that have no ASCII form is left with its extension only.
        return str_starts_with($ascii, '.') ? 'download'.$ascii : $ascii;
    }

    /**
     * A pattern replaced in a text; the text is returned as it is when the pattern cannot be applied to it.
     */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }

    /**
     * Shortens a name to the longest allowed, keeping its extension.
     */
    private static function shorten(string $name): string
    {
        $dot = mb_strrpos($name, '.');
        $extension = $dot === false ? '' : mb_substr($name, $dot);

        if ($extension === '' || mb_strlen($extension) > self::MAX_EXTENSION_LENGTH) {
            return rtrim(mb_substr($name, 0, self::MAX_LENGTH), ' .');
        }

        return rtrim(mb_substr($name, 0, self::MAX_LENGTH - mb_strlen($extension)), ' .').$extension;
    }
}
