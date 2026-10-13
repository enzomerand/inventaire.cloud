<?php

declare(strict_types=1);

namespace App\Installer\Support;

use App\Installer\Exceptions\InstallationException;

/**
 * Writes the installer's files so readers never see a half-written one, and so
 * the web server can still read them afterwards.
 *
 * Two people touch these files: the web server (the wizard) and whoever runs
 * `php artisan inventaire:install` (an SSH user, or root in a container). A file
 * the command creates with owner-only permissions would lock the web server
 * out, and the site would loop on /install. So files are group-readable
 * (0640), directories 0750, and when the command runs as root what it creates
 * is handed to the owner and group of the directory it lives in.
 *
 * Writing to a temporary sibling then renaming it is atomic on a POSIX
 * filesystem, so a concurrent reader gets either the old or the new content.
 */
final class AtomicFile
{
    /** Permissions of files created by the installer: owner read/write, group read. */
    public const FILE_MODE = 0640;

    /** Permissions of directories created by the installer. */
    public const DIRECTORY_MODE = 0750;

    /**
     * Replaces (or creates) a file atomically.
     *
     * An existing file keeps its owner and group (the caller decides, with
     * {@see self::canPreserveOwnership()}, whether an atomic replacement is
     * acceptable); a new file takes the owner and group of its directory when
     * running as root.
     *
     * @param  int  $mode  Permissions of the resulting file.
     *
     * @throws InstallationException When the directory or file cannot be written.
     */
    public static function write(string $path, string $contents, int $mode = self::FILE_MODE): void
    {
        $directory = dirname($path);

        self::ensureDirectory($directory);

        $owner = self::ownerOf(is_file($path) ? $path : $directory);
        $temporary = @tempnam($directory, '.tmp-');

        if ($temporary === false) {
            throw new InstallationException('Cannot write in :path.', ['path' => self::display($directory)]);
        }

        if (@file_put_contents($temporary, $contents) === false || ! @chmod($temporary, $mode)) {
            @unlink($temporary);

            throw new InstallationException('Cannot write :path.', ['path' => self::display($path)]);
        }

        self::giveTo($temporary, $owner);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new InstallationException('Cannot write :path.', ['path' => self::display($path)]);
        }

        clearstatcache(true, $path);
    }

    /**
     * Creates a directory (and its parents) readable by the group.
     *
     * When running as root, every directory created is handed to the owner and
     * group of the closest directory that already existed.
     *
     * @throws InstallationException When it cannot be created.
     */
    public static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        $missing = [];
        $existing = $directory;

        while (! is_dir($existing) && dirname($existing) !== $existing) {
            $missing[] = $existing;
            $existing = dirname($existing);
        }

        $owner = self::ownerOf($existing);

        foreach (array_reverse($missing) as $path) {
            if (! @mkdir($path, self::DIRECTORY_MODE) && ! is_dir($path)) {
                throw new InstallationException('Cannot create the directory :path.', ['path' => self::display($path)]);
            }

            @chmod($path, self::DIRECTORY_MODE);
            self::giveTo($path, $owner);
        }
    }

    /**
     * Creates an empty file readable by the group if it does not exist yet, or
     * leaves an existing one alone.
     *
     * @throws InstallationException When it cannot be created.
     */
    public static function touch(string $path): void
    {
        if (is_file($path)) {
            return;
        }

        self::ensureDirectory(dirname($path));

        $owner = self::ownerOf(dirname($path));

        if (@file_put_contents($path, '', FILE_APPEND) === false) {
            throw new InstallationException('Cannot write :path.', ['path' => self::display($path)]);
        }

        @chmod($path, self::FILE_MODE);
        self::giveTo($path, $owner);
    }

    /**
     * Whether replacing the file by a new one keeps its owner and group.
     *
     * Only root can give a file to somebody else; another user replacing a file
     * owned by a third party would silently take it over, which is the very
     * problem this class exists to avoid. The caller then rewrites the file in
     * place, which keeps its owner.
     */
    public static function canPreserveOwnership(string $path): bool
    {
        if (! file_exists($path) || self::isRoot()) {
            return true;
        }

        return fileowner($path) === self::effectiveUser();
    }

    /**
     * Whether the process runs as root.
     */
    public static function isRoot(): bool
    {
        return self::effectiveUser() === 0;
    }

    /**
     * The numeric id of the user the process runs as.
     */
    public static function effectiveUser(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : (getmyuid() ?: -1);
    }

    /**
     * A path as shown to people: relative to the application when it is inside
     * it, so messages never reveal where the application lives on the server.
     */
    public static function display(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : basename($path);
    }

    /**
     * Owner and group of an existing path.
     *
     * @return array{uid: int, gid: int}|null
     */
    private static function ownerOf(string $path): ?array
    {
        $uid = @fileowner($path);
        $gid = @filegroup($path);

        return $uid === false || $gid === false ? null : ['uid' => $uid, 'gid' => $gid];
    }

    /**
     * Gives a new file or directory to an owner when allowed: root can chown
     * freely, anybody else can at best pass the group on (and only to a group
     * they belong to, so a failure is simply ignored).
     *
     * @param  array{uid: int, gid: int}|null  $owner
     */
    private static function giveTo(string $path, ?array $owner): void
    {
        if ($owner === null) {
            return;
        }

        if (self::isRoot()) {
            @chown($path, $owner['uid']);
            @chgrp($path, $owner['gid']);

            return;
        }

        if (@filegroup($path) !== $owner['gid']) {
            @chgrp($path, $owner['gid']);
        }
    }
}
