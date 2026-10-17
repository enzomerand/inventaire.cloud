<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A stream wrapper whose reads fail after some bytes (a file on a disk that dies, a connection that drops), to test what happens
 * when a file cannot be read to its end.
 *
 * Register it with `stream_wrapper_register('name', FailingReadStream::class)` and set `$bytes` before the file is opened: it gives that
 * many bytes (of `x`), then every read fails, and the end of the file is never reached. With `$stall` the reads after those bytes do not
 * fail but give nothing, which is not the end of the file either.
 */
final class FailingReadStream
{
    /** How many bytes are given before the reads fail. */
    public static int $bytes = 0;

    /** Whether the reads after those bytes give an empty string, instead of failing. */
    public static bool $stall = false;

    /** How many empty reads in a row are given before the test is failed: a reader that asks again for ever would hang it. */
    private const MAX_EMPTY_READS = 1000;

    /** @var resource|null */
    public $context;

    private int $given = 0;

    private int $empty = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->given = 0;
        $this->empty = 0;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->given >= self::$bytes) {
            if (! self::$stall) {
                return false;
            }

            if (++$this->empty > self::MAX_EMPTY_READS) {
                throw new \RuntimeException('The stream was read again and again though it gives nothing.');
            }

            return '';
        }

        $length = min($count, self::$bytes - $this->given);
        $this->given += $length;

        return str_repeat('x', $length);
    }

    public function stream_eof(): bool
    {
        return false;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['mode' => 0100644, 'size' => self::$bytes];
    }

    /**
     * @return array<string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100644, 'size' => self::$bytes];
    }
}
