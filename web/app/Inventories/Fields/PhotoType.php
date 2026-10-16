<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Inventories\Attachments\Thumbnails;

/**
 * A photo or several, which the grid and the gallery show as thumbnails. Only JPEG, PNG, WebP and GIF images are taken, and an
 * image that cannot be read is refused: a thumbnail is made of each one (see {@see UploadedFilesType} and
 * {@see Thumbnails}).
 */
final class PhotoType extends UploadedFilesType
{
    /**
     * The word that identifies the type in the API and in the database: `photo`.
     */
    public function key(): string
    {
        return 'photo';
    }

    /**
     * What the type is called for a person ("Photo"), in the language of the request.
     */
    public function label(): string
    {
        return __('Photo');
    }

    /**
     * Whether the field takes images only.
     */
    public function imagesOnly(): bool
    {
        return true;
    }
}
