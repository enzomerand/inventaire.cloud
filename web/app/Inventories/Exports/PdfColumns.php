<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use Dompdf\FontMetrics;

/**
 * The columns of the table of a PDF: how wide each one is, and the label its header shows.
 *
 * The table of a PDF is cut in fragments of a few rows (the library's memory grows faster than a table does), and the header of the
 * columns is not part of any of them: it is written once, in a block that the library repeats at the top of every page. Fragments
 * and header must therefore agree on the width of the columns without the library measuring anything, which is what a fixed
 * layout with the same `<colgroup>` of percentages in each of them gives. The widths are worked out here, once, from what the
 * columns hold.
 *
 * Each column first gets what it needs to show its header and its short values on one line (the width of the header, and of the
 * longest value up to {@see self::SHORT_VALUE} characters); what is left goes to the columns that hold long texts, in proportion to
 * how much text they hold. A header is one line, whatever the name of its field: a label that still does not fit its column is cut
 * short with an ellipsis (the other formats keep the full name). That is what makes the height of the header, and so the margin it
 * needs, known beforehand.
 */
final class PdfColumns
{
    /** The longest value, in characters, that a column is given the room to show on one line: beyond, it wraps. */
    private const SHORT_VALUE = 24;

    /** What a character of a value takes, in points, in the font at 8 points (a digit, the widest of them, is 5.1). */
    private const CHARACTER_PT = 5.0;

    /** The most a column is given for its header and short values, in percent of the table: one column does not take the page. */
    private const MAX_FLOOR = 30.0;

    /** The most characters of the longest value of a column that count for the share of the text. */
    private const VALUE_WEIGHT_CAP = 60;

    /** The rows above which the width of a column is worked out on a sample of its rows. */
    private const SAMPLE_ROWS = 300;

    /** The horizontal room a cell takes around its text, in points: 5px of padding on each side and the border. */
    private const CELL_PADDING_PT = 9.0;

    /** The least a column is given, in percent of the table, whatever it holds. */
    private const MIN_SHARE = 4.0;

    /**
     * The columns of a table.
     *
     * @param  list<string>  $headers  The names of the fields.
     * @param  list<list<string>>  $rows  The cells of each row, by column.
     * @param  list<bool>  $numeric  Which columns hold numbers.
     * @param  string  $font  The font file of the header, bold (see {@see FontMetrics::getFont()}).
     * @param  float  $width  The width of the table, in points.
     * @param  float  $size  The size of the header font, in points.
     * @return list<array{label: string, share: float, numeric: bool}> The label of the header, the width as a percentage of the table (the shares add up to 100), and whether the column holds numbers.
     */
    public static function plan(array $headers, array $rows, array $numeric, FontMetrics $metrics, string $font, float $width, float $size): array
    {
        $step = max(1, intdiv(count($rows), self::SAMPLE_ROWS));
        $floors = [];
        $weights = [];

        foreach ($headers as $position => $header) {
            $total = 0;
            $longest = 0;
            $seen = 0;

            for ($row = 0; $row < count($rows); $row += $step) {
                $length = mb_strlen($rows[$row][$position] ?? '');
                $total += $length;
                $longest = max($longest, $length);
                $seen++;
            }

            $mean = $seen === 0 ? 0 : $total / $seen;
            // The room to show the header and the short values on one line, and how much text the column holds beyond that.
            $needed = max($metrics->getTextWidth($header, $font, $size), min($longest, self::SHORT_VALUE) * self::CHARACTER_PT) + self::CELL_PADDING_PT;
            $floors[] = min(self::MAX_FLOOR, $needed / $width * 100);
            $weights[] = max(1.0, 0.6 * $mean + 0.4 * min($longest, self::VALUE_WEIGHT_CAP) - self::SHORT_VALUE / 2);
        }

        $shares = self::shares($floors, $weights);
        $columns = [];

        foreach ($headers as $position => $header) {
            $columns[] = [
                'label' => self::fit($header, $shares[$position] / 100 * $width - self::CELL_PADDING_PT, $metrics, $font, $size),
                'share' => $shares[$position],
                'numeric' => $numeric[$position] ?? false,
            ];
        }

        return $columns;
    }

    /**
     * The width of each column as a percentage of the table, adding up to 100: each column has at least its floor, and what is
     * left is shared in proportion to the weights. When the floors are more than the table they are scaled down together.
     *
     * @param  list<float>  $floors  The least each column needs, in percent.
     * @param  list<float>  $weights  How much of what is left each column takes, relative to the others (more than 0).
     * @return list<float>
     */
    public static function shares(array $floors, array $weights): array
    {
        $count = count($floors);

        if ($count === 0) {
            return [];
        }

        $floors = array_map(fn (float $floor): float => max(min(self::MIN_SHARE, 100 / $count), $floor), $floors);
        $needed = array_sum($floors);

        if ($needed >= 100) {
            $shares = array_map(fn (float $floor): float => round($floor / $needed * 100, 2), $floors);
        } else {
            $left = 100 - $needed;
            $total = array_sum($weights);
            $shares = [];

            foreach ($floors as $position => $floor) {
                $shares[] = round($floor + $left * $weights[$position] / $total, 2);
            }
        }

        // The rounding leaves a hundredth or two: the widest column takes it, so the shares add up to 100 exactly.
        $widest = array_search(max($shares), $shares, true);
        $rest = 100 - array_sum($shares);

        return array_map(fn (float $share, int $position): float => $position === $widest ? round($share + $rest, 2) : $share, $shares, array_keys($shares));
    }

    /**
     * A text cut short, with an ellipsis, until it fits in a width (in points).
     */
    public static function fit(string $text, float $room, FontMetrics $metrics, string $font, float $size): string
    {
        if ($metrics->getTextWidth($text, $font, $size) <= $room) {
            return $text;
        }

        // The longest start of the text that fits with the ellipsis: the width grows with the length, so it is a binary search.
        $low = 0;
        $high = mb_strlen($text);

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);

            if ($metrics->getTextWidth(rtrim(mb_substr($text, 0, $middle)).'…', $font, $size) <= $room) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low === 0 ? '…' : rtrim(mb_substr($text, 0, $low)).'…';
    }
}
