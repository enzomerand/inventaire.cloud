<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * An in-memory stream wrapper that creates files normally but refuses to change
 * their permissions, to test what happens when a file cannot be made private.
 *
 * Register it with `stream_wrapper_register('chmodrefuse', ChmodRefusingStream::class)`,
 * list the folders that exist in {@see self::$directories} and use paths such as
 * `chmodrefuse://folder/file`.
 */
final class ChmodRefusingStream
{
    /** @var array<string, string> Contents by path. */
    public static array $files = [];

    /** @var list<string> Paths that exist as writable folders. */
    public static array $directories = [];

    /** @var resource|null */
    public $context;

    private string $key = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->key = $path;
        self::$files[$path] ??= '';

        return true;
    }

    public function stream_write(string $data): int
    {
        self::$files[$this->key] .= $data;

        return strlen($data);
    }

    public function stream_close(): void {}

    /**
     * Every permission change is refused.
     */
    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return false;
    }

    public function unlink(string $path): bool
    {
        unset(self::$files[$path]);

        return true;
    }

    /**
     * @return array<string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        if (isset(self::$files[$path])) {
            return ['mode' => 0100644, 'size' => strlen(self::$files[$path])];
        }

        return in_array($path, self::$directories, true) ? ['mode' => 040777, 'size' => 0] : false;
    }
}
