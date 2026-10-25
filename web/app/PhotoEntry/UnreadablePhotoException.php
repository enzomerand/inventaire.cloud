<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use RuntimeException;

/**
 * A photo that cannot be prepared for the analysis: it is not an image of a type that is accepted, it cannot be decoded, or it is too
 * large to be decoded with the memory a request has. `reason` is the machine-readable `code` of the `422` the API answers.
 */
final class UnreadablePhotoException extends RuntimeException
{
    /** The file is not a JPEG, PNG or WebP image. */
    public const UNSUPPORTED_TYPE = 'unsupported_type';

    /** The file claims to be an image but cannot be decoded (broken, truncated, an animated WebP...). */
    public const UNREADABLE = 'unreadable_photo';

    /** The image has too many pixels to be decoded here. */
    public const TOO_LARGE = 'photo_too_large';

    /**
     * Says why the photo is refused.
     *
     * @param  string  $reason  One of the constants above.
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
