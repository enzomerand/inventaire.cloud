<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use RuntimeException;

/**
 * A photo that cannot be prepared for the analysis: it is not an image of a type that is accepted, it cannot be decoded, or it is too
 * large to be decoded with the memory a request has. `reason` is the machine-readable `code` of the `422` the API answers, and `photo`
 * the position (from 0) of the photo among those that were sent, once the code that knows it has said so.
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
     * @param  int|null  $photo  Which of the photos that were sent it is (from 0), when that is known.
     */
    public function __construct(public readonly string $reason, string $message, public readonly ?int $photo = null)
    {
        parent::__construct($message);
    }

    /**
     * The same refusal, for the photo at this position among those that were sent.
     */
    public function at(int $photo): self
    {
        return new self($this->reason, $this->getMessage(), $photo);
    }
}
