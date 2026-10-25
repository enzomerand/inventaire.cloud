<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

use GdImage;

/**
 * Makes the thumbnail of an image with GD: a JPEG whose longest side is 480 pixels at most, without any metadata. The same work, at
 * another size and quality, prepares the photos the photo entry sends to Claude ({@see self::reencode()}).
 *
 * - JPEG, PNG, WebP and GIF are supported (the first image of an animation);
 * - the orientation of a JPEG that the camera recorded in its EXIF data is applied to the pixels, so that the thumbnail
 *   shows the picture the right way up whatever the viewer does with EXIF; the thumbnail carries no EXIF data itself, which
 *   also means it never gives away where a photo was taken;
 * - transparency is flattened on a white background (JPEG has none);
 * - an image is not decoded when GD would need more memory than PHP has left (a margin is kept for the rest of the
 *   request), or when it has more than 40 million pixels: the file is kept without a thumbnail.
 *
 * The original is never changed.
 */
final class Thumbnails
{
    /** Longest side of a thumbnail, in pixels. */
    public const MAX_SIDE = 480;

    /** Quality of the JPEG, from 0 to 100. */
    public const QUALITY = 80;

    /** Most pixels an image may have to be decoded. */
    public const MAX_PIXELS = 40_000_000;

    /** The types of image that get a thumbnail. */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Memory kept free for the rest of the request while an image is decoded, in bytes. */
    private const MEMORY_MARGIN = 16 * 1024 * 1024;

    /**
     * What the header of the file says about the image, or null when it is not one of the supported types or its header
     * cannot be read. Only the header is read: this is cheap whatever the size of the image.
     *
     * @param  string  $mime  The type detected from the content.
     */
    public function inspect(string $path, string $mime): ?ImageInfo
    {
        if (! in_array($mime, self::IMAGE_TYPES, true)) {
            return null;
        }

        $size = @getimagesize($path);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        return new ImageInfo($size[0], $size[1], $mime === 'image/jpeg' ? $this->orientationOf($path) : 1);
    }

    /**
     * The thumbnail of an image (the bytes of a JPEG without metadata), or null when none is made on purpose: GD is not there,
     * the image has too many pixels, or PHP has not the memory to decode it.
     *
     * @param  string  $mime  The type detected from the content.
     * @param  ImageInfo  $info  What {@see self::inspect()} said about the file.
     *
     * @throws UnreadableImageException When the image cannot be decoded.
     */
    public function make(string $path, string $mime, ImageInfo $info): ?string
    {
        return $this->reencode($path, $mime, $info, self::MAX_SIDE, self::QUALITY);
    }

    /**
     * The image as a JPEG without any metadata (no EXIF, so no place, no date, no camera), turned upright, and brought down to
     * `$maxSide` pixels on its longest side if it is larger (a smaller image is never enlarged). A thumbnail is the same work at 480
     * pixels and quality 80 ({@see self::make()}). Null on purpose when GD is not there, the image has too many pixels, or PHP has not
     * the memory to decode it.
     *
     * @param  string  $mime  The type detected from the content.
     * @param  ImageInfo  $info  What {@see self::inspect()} said about the file.
     * @param  int  $maxSide  Longest side of the result, in pixels.
     * @param  int  $quality  Quality of the JPEG, from 0 to 100.
     *
     * @throws UnreadableImageException When the image cannot be decoded.
     */
    public function reencode(string $path, string $mime, ImageInfo $info, int $maxSide, int $quality): ?string
    {
        if (! function_exists('imagecreatetruecolor') || $this->tooLargeToDecode($info)) {
            return null;
        }

        $source = $this->decode($path, $mime);

        if ($source === null) {
            throw new UnreadableImageException('The image could not be decoded.');
        }

        // A palette image (a GIF) marks its transparent colour by an index that resampling ignores: as true colour it has alpha.
        if (! imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        $source = $this->orient($source, $info->orientation);
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, $maxSide / max($width, $height));
        $thumbWidth = max(1, (int) round($width * $scale));
        $thumbHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($thumbWidth, $thumbHeight);
        imagefilledrectangle($canvas, 0, 0, $thumbWidth, $thumbHeight, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);

        ob_start();
        $encoded = imagejpeg($canvas, null, $quality);
        $jpeg = (string) ob_get_clean();

        if (! $encoded || $jpeg === '') {
            return null;
        }

        return $jpeg;
    }

    /**
     * Whether the image has too many pixels to be decoded, or more than PHP has the memory for. It is what makes {@see self::reencode()}
     * answer null on purpose, as opposed to GD missing or an encoder that fails, which are faults of the server.
     *
     * @param  ImageInfo  $info  What {@see self::inspect()} said about the file.
     */
    public function tooLargeToDecode(ImageInfo $info): bool
    {
        return $info->width * $info->height > self::MAX_PIXELS || ! $this->fitsInMemory($info);
    }

    /**
     * Decodes the file, or null when GD cannot (a broken file, an animated WebP).
     */
    private function decode(string $path, string $mime): ?GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };

        return $image === false ? null : $image;
    }

    /**
     * The image turned and flipped the way its EXIF orientation asks (see the EXIF specification, tag 0x0112).
     */
    private function orient(GdImage $image, int $orientation): GdImage
    {
        // `imagerotate()` turns counter-clockwise: 270 is a quarter clockwise.
        return match ($orientation) {
            2 => $this->flipped($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotated($image, 180),
            4 => $this->flipped($image, IMG_FLIP_VERTICAL),
            5 => $this->flipped($this->rotated($image, 270), IMG_FLIP_HORIZONTAL),
            6 => $this->rotated($image, 270),
            7 => $this->flipped($this->rotated($image, 90), IMG_FLIP_HORIZONTAL),
            8 => $this->rotated($image, 90),
            default => $image,
        };
    }

    /**
     * The image flipped (in place).
     */
    private function flipped(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    /**
     * The image turned by an angle, counter-clockwise, as a new image; the image itself when GD cannot turn it.
     */
    private function rotated(GdImage $image, int $degrees): GdImage
    {
        return imagerotate($image, $degrees, 0) ?: $image;
    }

    /**
     * The orientation a JPEG records in its EXIF data (1 when there is none, or the data cannot be read or the extension
     * is not there).
     */
    private function orientationOf(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path, 'IFD0');
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Whether PHP has the memory to decode the image: GD holds the image as 4 bytes a pixel, and turning it holds a second
     * copy, so about twice that is needed, with a margin left for the rest of the request.
     */
    private function fitsInMemory(ImageInfo $info): bool
    {
        $limit = $this->memoryLimit();

        if ($limit === null) {
            return true;
        }

        $needed = (int) ($info->width * $info->height * 4 * 2.2) + 2_000_000;

        return $limit - memory_get_usage() - self::MEMORY_MARGIN >= $needed;
    }

    /**
     * PHP's memory limit in bytes, or null when there is none.
     */
    private function memoryLimit(): ?int
    {
        $setting = trim((string) ini_get('memory_limit'));

        if ($setting === '' || $setting === '-1') {
            return null;
        }

        $number = (int) $setting;

        return match (strtolower(substr($setting, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
