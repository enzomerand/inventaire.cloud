<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

/**
 * How high the repeated header of a PDF is, and so how big the top margin of its pages: what the layout of the PDF of an export
 * and of an official version share.
 *
 * The header is written once, in a block with `position: fixed`, which the library repeats on every page. It sits in the top margin
 * of the page, whose height is made of it, so the library must be told how high the block is: the empty margin above it, the line
 * of the title, the lines of text under it, a gap and the row of the columns (for a table). The numbers are CSS pixels (the library
 * draws 96 pixels to the inch and 72 points: a pixel is 0.75 of a point).
 */
final readonly class PdfHead
{
    /** The empty margin above the header. */
    public const MARGIN = 26;

    /** The line of the title. */
    public const TITLE = 24;

    /** A line of text under the title. */
    public const LINE = 16;

    /** The gap above the row of the columns. */
    public const GAP = 6;

    /** The row of the columns of a table. */
    public const COLUMNS = 24;

    /** What stands between the repeated header and the first line of the page. */
    public const BODY_GAP = 3;

    /**
     * @param  int  $height  The height of the fixed block.
     * @param  int  $top  How far above the content of the page the block starts: its height and the gap under it.
     * @param  int  $margin  The top margin of the page, in which the block sits.
     */
    public function __construct(public int $height, public int $top, public int $margin) {}

    /**
     * The header of a document.
     *
     * @param  int  $lines  The lines of text under the title.
     * @param  bool  $columns  Whether the row of the columns of a table sits under them.
     */
    public static function of(int $lines, bool $columns): self
    {
        $height = self::TITLE + self::LINE * $lines + ($columns ? self::GAP + self::COLUMNS : 0);
        // A table is right under its header; cards, which have a border of their own, get some air.
        $top = $height + ($columns ? self::BODY_GAP : self::BODY_GAP + 5);

        return new self($height, $top, self::MARGIN + $top);
    }

    /**
     * What the stylesheet of the page needs.
     *
     * @return array<string, int>
     */
    public function view(): array
    {
        return [
            // The header is fixed in the top margin of every page: the margin is as high as it is, with what is above and below.
            'headHeight' => $this->height,
            'headTop' => $this->top,
            'headMargin' => self::MARGIN,
            'marginTop' => $this->margin,
            'titleLine' => self::TITLE,
            'summaryLine' => self::LINE,
            'columnsLine' => self::COLUMNS,
            'headGap' => self::GAP,
        ];
    }
}
