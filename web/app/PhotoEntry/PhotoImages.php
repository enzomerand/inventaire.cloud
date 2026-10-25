<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Inventories\Attachments\IncomingFile;
use App\Inventories\Attachments\Thumbnails;
use App\Inventories\Attachments\UnreadableImageException;

/**
 * Prepares a photo for the analysis: the image as a JPEG of 1568 pixels on its longest side at most (the size Claude reads best: a larger
 * image is scaled down on its side anyway, and costs more), quality 85, turned the way up, **without any metadata**.
 *
 * The photos leave the instance for Anthropic, and a photo taken with a phone says where, when and with what it was taken (EXIF, GPS). The
 * image is decoded and drawn again with GD, which writes none of it, so nothing but the pixels is sent. The type is detected from the
 * content, never from the name or the type the client announced; JPEG, PNG and WebP are accepted (the front sends JPEG, but a client
 * that does not scale may send what the camera gives).
 *
 * The work is {@see Thumbnails}', at another size and quality.
 */
final class PhotoImages
{
    /** Longest side of a prepared photo, in pixels. */
    public const MAX_SIDE = 1568;

    /** Quality of the JPEG, from 0 to 100. */
    public const QUALITY = 85;

    /** The types of image that are accepted. */
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Gets the code that decodes and draws images.
     */
    public function __construct(private readonly Thumbnails $thumbnails) {}

    /**
     * The bytes of the prepared photo.
     *
     * @param  string  $path  Where the photo is on the server.
     *
     * @throws UnreadablePhotoException When the file is not a JPEG, PNG or WebP image, cannot be decoded or is too large to be.
     */
    public function prepare(string $path): string
    {
        $mime = IncomingFile::detect($path);

        if (! in_array($mime, self::TYPES, true)) {
            throw new UnreadablePhotoException(UnreadablePhotoException::UNSUPPORTED_TYPE, __('This file is not a photo: send a JPEG, PNG or WebP image.'));
        }

        $info = $this->thumbnails->inspect($path, $mime);

        if ($info === null) {
            throw new UnreadablePhotoException(UnreadablePhotoException::UNREADABLE, __('This photo cannot be read.'));
        }

        try {
            $jpeg = $this->thumbnails->reencode($path, $mime, $info, self::MAX_SIDE, self::QUALITY);
        } catch (UnreadableImageException) {
            throw new UnreadablePhotoException(UnreadablePhotoException::UNREADABLE, __('This photo cannot be read.'));
        }

        if ($jpeg === null) {
            throw new UnreadablePhotoException(UnreadablePhotoException::TOO_LARGE, __('This photo is too large to be analysed: send a smaller one.'));
        }

        return $jpeg;
    }
}
