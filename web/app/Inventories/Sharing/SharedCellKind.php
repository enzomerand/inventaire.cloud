<?php

declare(strict_types=1);

namespace App\Inventories\Sharing;

/**
 * What a cell of the public page of a share link shows, which decides the element that draws it (see resources/views/share/cell.blade.php).
 */
enum SharedCellKind: string
{
    /** Nothing: the item holds no value for the field. */
    case Empty = 'empty';

    /** Plain text. */
    case Text = 'text';

    /** A code (a barcode), in a monospace font. */
    case Mono = 'mono';

    /** The choices of a select or a multiselect, and the tags, as badges. */
    case Badges = 'badges';

    /** A link: a web address, an email address, a phone number. */
    case Link = 'link';

    /** A rating as filled and empty stars. */
    case Stars = 'stars';

    /** A color: a swatch and its code. */
    case Swatch = 'swatch';

    /** The thumbnails of the photos of a photo field. */
    case Photos = 'photos';

    /** The names of the files of a file field. */
    case Files = 'files';
}
