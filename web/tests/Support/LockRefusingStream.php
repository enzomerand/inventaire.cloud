<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * An in-memory stream wrapper whose files open normally but can never be locked,
 * to test what happens on a filesystem that refuses `flock()` for a reason other
 * than "somebody else holds the lock" (no lock support, no lock slot left).
 *
 * Register it with `stream_wrapper_register('lockrefuse', LockRefusingStream::class)`
 * and use paths such as `lockrefuse://folder/file`: every path exists as a file.
 */
final class LockRefusingStream
{
    /** @var int How many times a lock was requested, across all instances. */
    public static int $attempts = 0;

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    /**
     * Every lock request is refused, and counted.
     */
    public function stream_lock(int $operation): bool
    {
        self::$attempts++;

        return false;
    }

    public function stream_close(): void {}

    /**
     * @return array<string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100644, 'size' => 0];
    }
}
