<?php

declare(strict_types=1);

namespace App\Inventories\Sharing;

/**
 * The value of a field of an item as the public page of a share link shows it: the kind that decides the element, and what that element
 * needs. Everything in it is text or a path the page escapes, never markup.
 */
final readonly class SharedCell
{
    /**
     * @param  string  $text  The text of the cell: what a link says, the stars of a rating, the code of a color.
     * @param  string|null  $href  Where a link leads, only ever a `http:` or `https:` address, a `mailto:` or a `tel:` one.
     * @param  bool  $external  Whether the link is a web page, opened in a new tab.
     * @param  string|null  $title  The sentence that says what a rating stands for ("3 out of 5").
     * @param  bool  $multiline  Whether the line breaks of the text are kept (a long text).
     * @param  list<array{text: string, class: string}>  $badges
     * @param  list<SharedFile>  $files
     */
    public function __construct(
        public SharedCellKind $kind,
        public string $text = '',
        public ?string $href = null,
        public bool $external = false,
        public ?string $title = null,
        public bool $multiline = false,
        public array $badges = [],
        public array $files = [],
    ) {}

    /**
     * A cell with nothing in it.
     */
    public static function empty(): self
    {
        return new self(SharedCellKind::Empty);
    }

    /**
     * A cell of plain text.
     */
    public static function text(string $text, bool $multiline = false): self
    {
        return new self(SharedCellKind::Text, $text, multiline: $multiline);
    }
}
