<?php

declare(strict_types=1);

namespace App\Installer\Support;

use App\Installer\Exceptions\InstallationException;

/**
 * Writes files so readers never see a half-written one.
 *
 * The installer's files (marker, progress, setup token, .env) are read by
 * concurrent web requests while a wizard step rewrites them. Writing to a
 * temporary sibling then renaming it is atomic on a POSIX filesystem, so a
 * reader gets either the old or the new content.
 */
final class AtomicFile
{
    /**
     * Replaces (or creates) a file atomically.
     *
     * @param  int  $mode  Permissions of the resulting file.
     *
     * @throws InstallationException When the directory or file cannot be written.
     */
    public static function write(string $path, string $contents, int $mode = 0600): void
    {
        $directory = dirname($path);

        self::ensureDirectory($directory);

        $temporary = @tempnam($directory, '.tmp-');

        if ($temporary === false) {
            throw new InstallationException('Cannot write in :path.', ['path' => $directory]);
        }

        if (@file_put_contents($temporary, $contents) === false || ! @chmod($temporary, $mode) || ! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new InstallationException('Cannot write :path.', ['path' => $path]);
        }

        clearstatcache(true, $path);
    }

    /**
     * Creates a directory (and its parents) reachable by its owner only.
     *
     * @throws InstallationException When it cannot be created.
     */
    public static function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new InstallationException('Cannot create the directory :path.', ['path' => $directory]);
        }
    }
}
