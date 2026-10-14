<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Exceptions\InstallationException;
use App\Installer\Support\AtomicFile;
use App\Installer\Support\FileLock;
use Closure;

/**
 * What the installer remembers between requests: whether the instance is
 * installed, and which wizard steps are already validated.
 *
 * Both live in small files under a private directory (see config
 * `installer.state_path`) rather than in the database, because the database is
 * exactly what is not configured yet when the wizard starts. The "installed"
 * marker is the single switch that turns the installer off for good: once it
 * exists, every /install route answers 404.
 */
final class InstallationState
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_SKIPPED = 'skipped';

    private const MARKER = 'installed.json';

    private const PROGRESS = 'progress.json';

    private const LOCK = '.lock';

    /** Once installed, always installed: the answer can be remembered. */
    private bool $installed = false;

    /**
     * @param  string  $directory  Where the state files live; created on first write.
     */
    public function __construct(private readonly string $directory) {}

    /**
     * The directory holding the installer's files (marker, progress, token).
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Whether the installation was completed.
     *
     * Checked on every request, so it is a single file test; the stat cache is
     * cleared because the marker may be created by another process.
     */
    public function isInstalled(): bool
    {
        if ($this->installed) {
            return true;
        }

        $path = $this->path(self::MARKER);

        clearstatcache(true, $path);

        return $this->installed = is_file($path);
    }

    /**
     * Writes the marker that switches the installer off.
     */
    public function markInstalled(): void
    {
        $this->exclusively(function (): void {
            AtomicFile::write(
                $this->path(self::MARKER),
                json_encode(['installed_at' => gmdate('c')], JSON_THROW_ON_ERROR)."\n",
            );
        });

        $this->installed = true;
    }

    /**
     * Status of every step: done, skipped or pending.
     *
     * @return array<string, string> Step value => status.
     */
    public function progress(): array
    {
        $contents = @file_get_contents($this->path(self::PROGRESS));
        $decoded = $contents === false ? null : json_decode($contents, true);
        $steps = is_array($decoded) && is_array($decoded['steps'] ?? null) ? $decoded['steps'] : [];
        $progress = [];

        foreach (InstallStep::cases() as $step) {
            $status = $steps[$step->value] ?? null;

            $progress[$step->value] = in_array($status, [self::STATUS_DONE, self::STATUS_SKIPPED], true)
                ? $status
                : self::STATUS_PENDING;
        }

        return $progress;
    }

    /**
     * Status of one step: done, skipped or pending.
     */
    public function status(InstallStep $step): string
    {
        return $this->progress()[$step->value];
    }

    /**
     * Whether the step no longer blocks the installation (done or skipped).
     */
    public function isSettled(InstallStep $step): bool
    {
        return $this->status($step) !== self::STATUS_PENDING;
    }

    /**
     * Records a step as validated (or skipped), so the wizard resumes after it.
     */
    public function record(InstallStep $step, string $status = self::STATUS_DONE): void
    {
        // Read, change, write: under the lock, or a concurrent request's step would be lost.
        $this->exclusively(function () use ($step, $status): void {
            $progress = array_filter($this->progress(), static fn (string $value): bool => $value !== self::STATUS_PENDING);
            $progress[$step->value] = $status;

            AtomicFile::write($this->path(self::PROGRESS), json_encode(['steps' => $progress], JSON_THROW_ON_ERROR)."\n");
        });
    }

    /**
     * Steps that must still be completed before the installation can finish.
     *
     * @return list<InstallStep>
     */
    public function missingRequiredSteps(): array
    {
        return array_values(array_filter(
            InstallStep::required(),
            fn (InstallStep $step): bool => ! $this->isSettled($step),
        ));
    }

    /**
     * Deletes the progress file (the installation is over).
     */
    public function forgetProgress(): void
    {
        $this->exclusively(fn (): bool => @unlink($this->path(self::PROGRESS)));
    }

    /**
     * Runs a callback while holding the installer's exclusive lock, so two
     * requests racing on the first visit cannot both generate a key or a
     * token, nor overwrite each other's progress.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws InstallationException When the lock cannot be taken: the sequence never runs unlocked.
     */
    public function exclusively(Closure $callback): mixed
    {
        return FileLock::exclusively($this->path(self::LOCK), $callback);
    }

    /**
     * Full path of a file inside the state directory.
     */
    public function path(string $file): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$file;
    }
}
