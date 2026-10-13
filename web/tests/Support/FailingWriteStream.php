<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * An in-memory stream wrapper whose writes can be made to fail, to test what
 * happens when a file is half rewritten (disk full, quota, I/O error).
 *
 * Register it with `stream_wrapper_register('failing', FailingWriteStream::class)`
 * and use paths such as `failing://env`.
 */
final class FailingWriteStream
{
    /** @var array<string, string> Contents by path. */
    public static array $files = [];

    /** When true, the next write fails (once). */
    public static bool $failNextWrite = false;

    /** @var resource|null */
    public $context;

    private string $key = '';

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->key = $path;
        self::$files[$path] ??= '';

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$files[$this->key], $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_write(string $data): int
    {
        if (self::$failNextWrite) {
            self::$failNextWrite = false;

            return 0;
        }

        self::$files[$this->key] = substr(self::$files[$this->key], 0, $this->position).$data;
        $this->position += strlen($data);

        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$files[$this->key]);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $this->position = $offset;

        return $whence === SEEK_SET;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_truncate(int $size): bool
    {
        self::$files[$this->key] = substr(str_pad(self::$files[$this->key], $size, "\0"), 0, $size);

        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void {}

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['mode' => 0100644, 'size' => strlen(self::$files[$this->key])];
    }

    /**
     * @return array<string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return isset(self::$files[$path]) ? ['mode' => 0100644, 'size' => strlen(self::$files[$path])] : false;
    }
}
