<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

/**
 * A thumbnail that was made: the bytes of a JPEG without metadata, and its size.
 */
final readonly class Thumbnail
{
    /**
     * @param  string  $jpeg  The file.
     * @param  int  $width  Pixels.
     * @param  int  $height  Pixels.
     */
    public function __construct(
        public string $jpeg,
        public int $width,
        public int $height,
    ) {}
}
