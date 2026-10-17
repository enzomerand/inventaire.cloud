<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\Fields\ExportKind;
use App\Inventories\Fields\ExportValue;
use App\Inventories\Fields\QueryKind;
use App\Inventories\Fields\UploadedFilesType;
use App\Models\InventoryField;
use App\Models\Item;
use App\Support\DisplayTime;
use Dompdf\Dompdf;

/**
 * The items of a PDF as the page shows them, every value already formatted: what the PDF of an export ({@see PdfWriter}) and the
 * PDF of an official version share.
 *
 * Up to {@see PdfWriter::TABLE_FIELDS} fields the items are a table, one row each; with more fields they would not fit on a line,
 * and each item is a card instead (`field: value`, the first text as its title). A file or photo field shows how many files the
 * item holds ("2 photos"), not their names. A long text is cut short (the document is to be read, the full text is in the other
 * formats). The widths of the columns of a table are worked out here, once ({@see PdfColumns}).
 */
final readonly class PdfBody
{
    /** Rows of a table before another one starts: the library's memory grows faster than a table does. */
    public const TABLE_ROWS = 25;

    /** The width of a table, in points: A4 on its side (842) less the two side margins of 24. A hair less, to be safe. */
    public const TABLE_WIDTH_PT = 793.0;

    /** The size of the font of the header of the columns, in points. */
    public const HEADER_SIZE_PT = 8.0;

    /**
     * Most characters of a value in a table cell, and in a card. The library's memory goes with the number of lines it lays out, and
     * a cell of a table is a narrow column: a text that is 4 lines in a cell costs as much as one 4 times as long in a card, which
     * is as wide as the page. A table of 500 items of 8 texts that all reach the limit takes 148 MB with 120 characters (293 MB with
     * 300); the same number of values as cards (4000) takes 143 to 156 MB with 800 characters (242 MB with 2000).
     */
    private const TABLE_TEXT = 120;

    private const CARD_TEXT = 800;

    /**
     * @param  bool  $asTable  Whether the items are the rows of a table (else cards).
     * @param  list<array{label: string, share: float, numeric: bool}>  $columns  The columns of the table: empty for cards, and for a table with no row.
     * @param  list<list<string>|array{title: string, lines: list<array{0: string, 1: string}>, updated: string}>  $rows  The cells of each row, or the card of each item.
     * @param  int  $count  The number of items.
     */
    public function __construct(
        public bool $asTable,
        public array $columns,
        public array $rows,
        public int $count,
    ) {}

    /**
     * Lays the items out as rows or cards, and plans the columns of a table.
     *
     * @param  iterable<Item>  $items  The items, in the order they are written.
     */
    public static function make(Dompdf $library, ExportTable $table, ExportFormatter $formatter, iterable $items): self
    {
        $asTable = $table->fields()->count() <= PdfWriter::TABLE_FIELDS;
        $rows = [];

        foreach ($items as $item) {
            $rows[] = $asTable ? self::cells($table, $formatter, $item) : self::card($table, $formatter, $item);
        }

        $columns = [];

        if ($asTable && $rows !== []) {
            $metrics = $library->getFontMetrics();
            $headers = array_values($table->fields()->keys()->map(fn (int $position): string => $table->header($position))->all());
            /** @var list<list<string>> $rows */
            $columns = PdfColumns::plan($headers, $rows, self::numericColumns($table), $metrics, $metrics->getFont(PdfLibrary::FONT, 'bold') ?? PdfLibrary::FONT, self::TABLE_WIDTH_PT, self::HEADER_SIZE_PT);
        }

        return new self($asTable, $columns, $rows, count($rows));
    }

    /**
     * The cells of an item for a table: the value of each field, as text.
     *
     * @return list<string>
     */
    private static function cells(ExportTable $table, ExportFormatter $formatter, Item $item): array
    {
        $cells = [];

        foreach ($table->row($item) as $position => $value) {
            $cells[] = self::text($table, $formatter, $item, $position, $value, self::TABLE_TEXT);
        }

        return $cells;
    }

    /**
     * An item as a card: its title, and the label and value of each field that has one.
     *
     * @return array{title: string, lines: list<array{0: string, 1: string}>, updated: string}
     */
    private static function card(ExportTable $table, ExportFormatter $formatter, Item $item): array
    {
        $title = '';
        $lines = [];

        foreach ($table->row($item) as $position => $value) {
            $text = self::text($table, $formatter, $item, $position, $value, self::CARD_TEXT);

            if ($title === '' && $value->kind === ExportKind::Text && $table->field($position)->type === 'text') {
                $title = $text;
            }

            if ($text !== '') {
                $lines[] = [$table->field($position)->name, $text];
            }
        }

        return [
            'title' => $title === '' ? __('Item') : $title,
            'lines' => $lines,
            'updated' => $item->updated_at === null ? '' : $formatter->moment(DisplayTime::of($item->updated_at)->toDateTimeImmutable()),
        ];
    }

    /**
     * A value as the text a printed page shows: formatted, cut short when it is very long, "2 photos" for the files of a field.
     */
    private static function text(ExportTable $table, ExportFormatter $formatter, Item $item, int $position, ExportValue $value, int $max): string
    {
        $type = $table->typeAt($position);

        if ($type instanceof UploadedFilesType) {
            $count = $table->fileCount($item, $position);

            return $count === 0 ? '' : ($type->imagesOnly()
                ? trans_choice(':count photo|:count photos', $count)
                : trans_choice(':count file|:count files', $count));
        }

        $text = $formatter->format($value);

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    /**
     * Which columns of a table hold numbers (aligned to the right).
     *
     * @return list<bool>
     */
    private static function numericColumns(ExportTable $table): array
    {
        return array_values($table->fields()->map(fn (InventoryField $field, int $position): bool => $table->typeAt($position)->queryKind() === QueryKind::Number)->all());
    }
}
