<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The local folders an export is written in before it is stored: the file itself, the scratch files of the spreadsheet library and
 * the fonts cache of the PDF library.
 *
 * They are under `storage/framework/` (the volume of a container, writable by the application), never in the code of the
 * application: a library that writes next to its own files would fail in an image where the code is read-only. Created on first
 * use, so that a fresh volume needs nothing.
 */
final class ExportTemp
{
    /** How long, in seconds, a scratch file or folder may stay before it is taken for a leftover: an hour, more than an export may run. */
    private const LEFTOVER_AGE = 3600;

    /**
     * The folder of a kind of scratch files (`exports`, `dompdf`), created if it is missing.
     *
     * @throws RuntimeException When it cannot be created.
     */
    public static function directory(string $name = 'exports'): string
    {
        $directory = storage_path("framework/{$name}");

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("The folder [{$directory}] could not be created.");
        }

        return $directory;
    }

    /**
     * A new empty file in the folder of the exports (or of another kind of scratch files, such as the `imports`), to write one into.
     *
     * @param  string  $directory  The kind of scratch files, as for {@see self::directory()}.
     * @param  string  $prefix  What the name of the file starts with.
     *
     * @throws RuntimeException When it cannot be created.
     */
    public static function file(string $directory = 'exports', string $prefix = 'export-'): string
    {
        $path = tempnam(self::directory($directory), $prefix);

        if ($path === false) {
            throw new RuntimeException('A temporary file could not be created.');
        }

        return $path;
    }

    /**
     * Deletes the scratch files and folders left in the folder of the exports (or of another kind of scratch files) for longer than
     * an hour (more than an export or an import may run): the leftovers of a worker that was stopped while it wrote one.
     *
     * @param  string  $directory  The kind of scratch files, as for {@see self::directory()}.
     * @return int How many files and folders were deleted.
     */
    public static function prune(string $directory = 'exports'): int
    {
        $deleted = 0;

        foreach (glob(self::directory($directory).'/*') ?: [] as $path) {
            // A file that vanished since the listing (another worker is done with it) has no age: it is not ours to delete.
            $modified = @filemtime($path);

            if ($modified === false || $modified >= time() - self::LEFTOVER_AGE) {
                continue;
            }

            // The spreadsheet library works in a folder of its own, which it empties when it is done.
            if (is_dir($path) ? File::deleteDirectory($path) : @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
