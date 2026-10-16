<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

/**
 * What the header of an image file says: its size as stored, and the orientation its EXIF data asks to show it in.
 */
final readonly class ImageInfo
{
    /**
     * @param  int  $width  Pixels, as stored.
     * @param  int  $height  Pixels, as stored.
     * @param  int  $orientation  The EXIF orientation, 1 to 8 (1: as stored; 6: turned a quarter clockwise...).
     */
    public function __construct(
        public int $width,
        public int $height,
        public int $orientation = 1,
    ) {}

    /**
     * Whether the orientation turns the image a quarter (the width and the height trade places once it is shown).
     */
    public function isTurned(): bool
    {
        return $this->orientation >= 5;
    }

    /**
     * The width the image is shown with.
     */
    public function displayWidth(): int
    {
        return $this->isTurned() ? $this->height : $this->width;
    }

    /**
     * The height the image is shown with.
     */
    public function displayHeight(): int
    {
        return $this->isTurned() ? $this->width : $this->height;
    }
}
