<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Exceptions\InstallationException;
use App\Installer\Support\AtomicFile;
use App\Installer\Support\FileLock;
use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;

/**
 * Reads and updates the .env file in place.
 *
 * Every wizard step persists its values here as soon as it is validated, so
 * this class must never lose what the administrator wrote: comments, blank
 * lines, ordering, unrelated keys, multi-line values and line endings survive,
 * and only the targeted assignments are rewritten. Values are quoted so that
 * the parser Laravel loads the file with (vlucas/phpdotenv) reads back exactly
 * what was written.
 */
final class EnvironmentFile
{
    /**
     * Permissions of the backup kept during an in-place rewrite: it holds every
     * secret of the .env file, so it is private to the user who writes it.
     */
    private const BACKUP_MODE = 0600;

    /**
     * Settings given to a .env that the installer creates: .env.example holds
     * development defaults (local environment, debug on, the credentials and
     * ports of the docker services of compose.dev.yaml, the product's own
     * sender address, a placeholder administrator), which must not leak into a server or be pre-filled in
     * the wizard. An existing file is never touched this way.
     */
    public const NEW_FILE_DEFAULTS = [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'DB_PORT' => '5432',
        'DB_USERNAME' => '',
        'DB_PASSWORD' => '',
        'MAIL_HOST' => '',
        'MAIL_PORT' => '587',
        'MAIL_USERNAME' => 'null',
        'MAIL_PASSWORD' => 'null',
        'MAIL_FROM_ADDRESS' => '',
        'ADMIN_NAME' => '',
        'ADMIN_EMAIL' => '',
    ];

    /**
     * @param  string  $path  The file to maintain (usually base_path('.env')).
     * @param  string  $examplePath  Template copied when the file does not exist yet.
     * @param  string|null  $backupPath  Where the previous contents are kept while the file is rewritten in place (see {@see self::write()}).
     * @param  string|null  $lockPath  File locked during a read-modify-write; defaults to a lock file derived from the path.
     */
    public function __construct(
        private readonly string $path,
        private readonly string $examplePath,
        private readonly ?string $backupPath = null,
        private readonly ?string $lockPath = null,
    ) {}

    /**
     * Path of the file this instance maintains.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Whether {@see self::set()} can succeed: the file is writable, or it can
     * be created in its (writable) directory.
     */
    public function isWritable(): bool
    {
        if (is_file($this->path)) {
            return is_writable($this->path);
        }

        return is_dir(dirname($this->path)) && is_writable(dirname($this->path));
    }

    /**
     * Every assignment of the file, with quotes removed and ${VARIABLES}
     * expanded exactly as Laravel sees them. A file that cannot be parsed
     * yields nothing rather than an error: the wizard then starts blank and
     * overwrites only the keys it manages.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            return [];
        }

        try {
            $parsed = Dotenv::parse($contents);
        } catch (InvalidFileException) {
            return [];
        }

        return array_map(static fn (?string $value): string => $value ?? '', $parsed);
    }

    /**
     * The value of a key, or null when it is not assigned.
     */
    public function get(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Assigns values, in place.
     *
     * Existing assignments are rewritten where they stand (the last one wins,
     * as it does when Laravel loads the file), new keys are appended at the
     * end. When the file does not exist it starts as a copy of .env.example.
     *
     * @param  array<string, string>  $values  Key => raw value; quoting is handled here.
     * @param  array<string, string>  $whenCreated  Further values applied only when the file is created by this call, below $values.
     * @return bool True when the file did not exist and was created.
     *
     * @throws InstallationException When the file cannot be written.
     */
    public function set(array $values, array $whenCreated = []): bool
    {
        // The file is read, changed and written back: two requests doing it together would lose one change.
        return FileLock::exclusively($this->lockPath ?? sys_get_temp_dir().'/inventaire-env-'.md5($this->path).'.lock', function () use ($values, $whenCreated): bool {
            $created = ! is_file($this->path);

            if ($created) {
                $values += $whenCreated;
            }

            $contents = $created ? (string) @file_get_contents($this->examplePath) : (string) @file_get_contents($this->path);

            $this->write($this->render($contents, $values));

            return $created;
        });
    }

    /**
     * Writes a value the way the dotenv parser reads it back: bare when it is
     * made of safe characters, double-quoted and escaped otherwise.
     */
    public static function format(string $value): string
    {
        $value = str_replace("\0", '', $value);

        // \z, not $: a trailing newline would match $ and be lost when the value is read back.
        if ($value === '' || preg_match('/^[A-Za-z0-9_.\/:@+,=-]+\z/', $value) === 1) {
            return $value;
        }

        // "$" is escaped because the parser would otherwise expand ${VARIABLES}.
        return '"'.strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]).'"';
    }

    /**
     * Rewrites the targeted assignments inside the file contents.
     *
     * @param  array<string, string>  $values
     */
    private function render(string $contents, array $values): string
    {
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $segments = $this->segments($contents, $eol);
        $last = [];

        foreach ($segments as $index => [$key]) {
            if ($key !== null) {
                $last[$key] = $index;
            }
        }

        $pending = $values;

        foreach ($last as $key => $index) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            // Keep an "export " prefix and indentation the file already uses.
            $segments[$index] = [$key, $segments[$index][2].$key.'='.self::format($values[$key]), $segments[$index][2]];
            unset($pending[$key]);
        }

        $output = implode($eol, array_column($segments, 1));

        if ($pending !== []) {
            if ($output !== '' && ! str_ends_with($output, $eol)) {
                $output .= $eol;
            }

            if ($output !== '' && ! str_ends_with($output, $eol.$eol)) {
                $output .= $eol;
            }

            foreach ($pending as $key => $value) {
                $output .= $key.'='.self::format($value).$eol;
            }
        }

        return $output;
    }

    /**
     * Splits the contents into lines, joining the lines of a multi-line quoted
     * value so an assignment is always one segment.
     *
     * @param  string  $eol  Line ending of the file, kept inside multi-line values.
     * @return list<array{0: ?string, 1: string, 2: string}> Assigned key (null for comments and blanks), original text,
     *                                                       and the indentation / "export " that precedes the key.
     */
    private function segments(string $contents, string $eol): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [''];
        $segments = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (preg_match('/^(\s*(?:export\s+)?)([A-Za-z_][A-Za-z0-9_.]*)\s*=(.*)$/', $line, $match) !== 1) {
                $segments[] = [null, $line, ''];

                continue;
            }

            $value = ltrim($match[3]);
            $quote = $value[0] ?? '';

            while (($quote === '"' || $quote === "'") && ! $this->isClosed($value, $quote) && $i + 1 < $count) {
                $line .= $eol.$lines[++$i];
                $value .= $eol.$lines[$i];
            }

            $segments[] = [$match[2], $line, $match[1]];
        }

        return $segments;
    }

    /**
     * Whether a quoted value is closed within the given text.
     */
    private function isClosed(string $value, string $quote): bool
    {
        $length = strlen($value);

        for ($i = 1; $i < $length; $i++) {
            if ($quote === '"' && $value[$i] === '\\') {
                $i++;

                continue;
            }

            if ($value[$i] === $quote) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stores the new contents.
     *
     * The preferred way is the atomic one: a temporary sibling renamed over the
     * file, which also keeps the file's mode, owner and group. It cannot be
     * used when .env is a file mounted into a container (rename over a mount
     * point fails), when only the file, not its directory, is writable, or when
     * replacing it would hand a file owned by somebody else to the current
     * user. In those cases the file is rewritten in place under an exclusive
     * lock, which keeps its inode and owner, but is not atomic: the previous
     * contents are kept in memory (and in a backup file when one is configured)
     * so that a write that fails halfway restores them instead of leaving an
     * empty or truncated .env.
     *
     * The file holds secrets (application key, database, SMTP and S3
     * passwords): an existing one keeps the mode it has and is never made more
     * accessible, a new one is private to its owner and group
     * ({@see AtomicFile::FILE_MODE}), whatever the umask says.
     *
     * @throws InstallationException
     */
    private function write(string $contents): void
    {
        $target = is_link($this->path) ? (realpath($this->path) ?: $this->path) : $this->path;
        $mode = is_file($target) ? (fileperms($target) & 0777) : AtomicFile::FILE_MODE;

        if (is_writable(dirname($target)) && AtomicFile::canPreserveOwnership($target)) {
            try {
                AtomicFile::write($target, $contents, $mode);
                $this->discardBackup();

                return;
            } catch (InstallationException) {
                // Fall back to writing in place below.
            }
        }

        $this->writeInPlace($target, $contents);
    }

    /**
     * Rewrites the file where it stands, restoring its previous contents if the
     * write fails.
     *
     * @throws InstallationException
     */
    private function writeInPlace(string $target, string $contents): void
    {
        $this->createPrivately($target);

        $handle = @fopen($target, 'c+b');

        if ($handle === false) {
            throw new InstallationException('The .env file is not writable.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new InstallationException('The .env file is not writable.');
            }

            $previous = (string) stream_get_contents($handle);

            $this->keepBackup($previous);

            if (! $this->overwrite($handle, $contents)) {
                // Put back what was there; if even that fails the backup file is the last resort and stays.
                if (! $this->overwrite($handle, $previous)) {
                    throw $this->notRestored();
                }

                $this->discardBackup();

                throw new InstallationException('The .env file could not be written; its previous contents were restored.');
            }

            $this->discardBackup();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            clearstatcache(true, $target);
        }
    }

    /**
     * The failure to report when the file could neither be rewritten nor put
     * back as it was: it says where the last copy is, if there is one, with a
     * path relative to the application (never an absolute one).
     */
    private function notRestored(): InstallationException
    {
        if ($this->backupPath !== null && is_file($this->backupPath)) {
            return new InstallationException('The .env file could not be written and its previous contents could not be restored; they were kept in :path.', ['path' => AtomicFile::display($this->backupPath)]);
        }

        return new InstallationException('The .env file could not be written and its previous contents could not be restored: check that it is intact.');
    }

    /**
     * Creates the file empty, with its final permissions, when it does not
     * exist yet: opening it for writing would create it with the umask's
     * permissions (usually world-readable) before the secrets are written to it.
     *
     * @throws InstallationException
     */
    private function createPrivately(string $target): void
    {
        if (file_exists($target)) {
            return;
        }

        try {
            AtomicFile::touch($target);
        } catch (InstallationException) {
            throw new InstallationException('The .env file is not writable.');
        }
    }

    /**
     * Replaces everything in an open file; false if any step fails.
     *
     * @param  resource  $handle
     */
    private function overwrite($handle, string $contents): bool
    {
        if (! rewind($handle) || ! ftruncate($handle, 0)) {
            return false;
        }

        $length = strlen($contents);
        $written = 0;

        while ($written < $length) {
            $chunk = fwrite($handle, substr($contents, $written));

            if ($chunk === false || $chunk === 0) {
                return false;
            }

            $written += $chunk;
        }

        return fflush($handle);
    }

    /**
     * Saves the previous contents before an in-place rewrite.
     */
    private function keepBackup(string $previous): void
    {
        if ($this->backupPath === null || $previous === '') {
            return;
        }

        try {
            AtomicFile::write($this->backupPath, $previous, self::BACKUP_MODE);
        } catch (InstallationException) {
            // The in-memory copy still protects against a failed write.
        }
    }

    /**
     * Removes the backup once it is no longer needed: the new contents are
     * safely written, or the previous ones are back in place (it holds secrets).
     * Also clears a stale one left by an earlier run that crashed.
     */
    private function discardBackup(): void
    {
        if ($this->backupPath !== null) {
            @unlink($this->backupPath);
        }
    }
}
