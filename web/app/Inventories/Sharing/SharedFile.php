<?php

declare(strict_types=1);

namespace App\Inventories\Sharing;

/**
 * A file of an item as the public page of a share link shows it: its name, where it is read from (a route of the link, never the address
 * of the API), its thumbnail when it has one and the icon that stands for it otherwise.
 */
final readonly class SharedFile
{
    /**
     * @param  string  $name  The name the file was uploaded with (text: the page escapes it).
     * @param  string  $href  Where the file is read from, a path of the link. An image or a PDF is shown in the page (`?inline=1`), any other file is downloaded.
     * @param  string|null  $thumbnail  Where its thumbnail is read from, null when it has none.
     * @param  'image'|'pdf'|'file'  $icon  The icon that stands for it when it has no thumbnail.
     * @param  string  $size  Its size as a person reads it ("1.2 KB").
     * @param  bool  $displayable  Whether the browser shows it in the page (and so opens it in a new tab) rather than downloads it.
     */
    public function __construct(
        public string $name,
        public string $href,
        public ?string $thumbnail,
        public string $icon,
        public string $size,
        public bool $displayable,
    ) {}
}
