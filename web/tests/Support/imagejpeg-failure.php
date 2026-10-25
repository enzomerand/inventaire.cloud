<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

use GdImage;

/*
 * Lets a test make GD's JPEG encoder fail for the code of this namespace (Thumbnails): PHP looks for a function in the namespace of the
 * caller before the global one. It does exactly what the real function does until a test sets `$GLOBALS['photo_imagejpeg_fails']`.
 */

/**
 * GD's `imagejpeg()`, unless a test made it fail.
 *
 * @param  resource|string|null  $file
 */
function imagejpeg(GdImage $image, mixed $file = null, int $quality = -1): bool
{
    return ($GLOBALS['photo_imagejpeg_fails'] ?? false) === true ? false : \imagejpeg($image, $file, $quality);
}
