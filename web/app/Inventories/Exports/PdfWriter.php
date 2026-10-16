<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\Exceptions\PdfTooLargeException;
use App\Inventories\Fields\ExportKind;
use App\Inventories\Fields\ExportValue;
use App\Inventories\Fields\QueryKind;
use App\Inventories\Fields\UploadedFilesType;
use App\Models\InventoryField;
use App\Models\Item;
use App\Support\DisplayTime;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Writes the items as a PDF to print: A4 landscape, a header with the name of the inventory, the date and the number of items, the
 * values written the way the language writes them ({@see ExportFormatter}), the number of the page at the foot.
 *
 * Up to 8 fields the items are a table, one row each; with more fields they would not fit on a line, and each item is a card
 * instead (`field: value`, the first text as its title). A file or photo field shows how many files the item holds ("2 photos"),
 * not their names. A long text is cut short (the document is to be read, the full text is in the other formats).
 *
 * **The header is written once and repeated by the library on every page**: a block with `position: fixed`, which holds the name
 * of the inventory, the line about the export and, for a table, the header of the columns ({@see PdfColumns}). The table itself is
 * many fragments of {@see self::TABLE_ROWS} rows without any header of their own, all with the same widths, so that the
 * header is on every page and only once. The block has a known height, which the top margin of the page is made of.
 *
 * The library lays the whole document out in memory, and there is no streaming it: that is why a PDF has two limits. One is on the
 * number of items (`inventories.pdf_max_items`, checked when the export is asked for and again here); the other is on the size of
 * the HTML the library is given ({@see self::MAX_TABLE_HTML_BYTES}, {@see self::MAX_CARDS_HTML_BYTES}), which is what its memory
 * really goes with: texts cut at {@see self::TABLE_TEXT} and {@see self::CARD_TEXT} characters, at the most items, stay under it.
 * Both are refused with {@see PdfTooLargeException}, which tells the person to filter the view. The library is set not to read
 * anything outside the document (no remote file, no PHP, no script) and its font cache lives under `storage/framework`, never in
 * `vendor/`. The text is in DejaVu Sans, which the library ships and embeds, so that every accent of the languages of the
 * application is drawn.
 */
final class PdfWriter implements ExportWriter
{
    /** Most fields a table holds: above, an item is a card. */
    public const TABLE_FIELDS = 8;

    /** Rows of a table before another one starts: the library's memory grows faster than a table does. */
    private const TABLE_ROWS = 25;

    /**
     * Most characters of a value in a table cell, and in a card. The library's memory goes with the number of lines it lays out, and
     * a cell of a table is a narrow column: a text that is 4 lines in a cell costs as much as one 4 times as long in a card, which
     * is as wide as the page. A table of 500 items of 8 texts that all reach the limit takes 148 MB with 120 characters (293 MB with
     * 300); the same number of values as cards (4000) takes 143 to 156 MB with 800 characters (242 MB with 2000).
     */
    private const TABLE_TEXT = 120;

    private const CARD_TEXT = 800;

    /**
     * The most bytes of HTML the library is given, for a table and for cards: the library's peak memory goes with the size of the
     * document it lays out, and this is checked before it reads any (a document over it fails with {@see PdfTooLargeException}).
     * The ratio is not the same for the two layouts, hence two limits, each measured with every value at its limit, at the most
     * items. Figures are the peak above a worker at rest (24 MB), which a 256 MB `memory_limit` must hold with room to spare:
     *
     * - tables (500 items of 8 texts): 0.51 MB of HTML with short values takes 71 MB; 0.91 MB, every cell cut at its limit, takes
     *   148 MB; 1.07 MB takes 181 MB and 1.65 MB takes 293 MB. The limit of 1 MB is about 165 MB;
     * - cards (4000 values): 0.93 MB with short values takes 105 MB; 3.8 MB, every value cut at its limit, takes 154 MB (333 items
     *   of 12 fields); 4.2 MB takes 159 MB (333 of 12), 165 MB (444 of 9) and 145 MB (40 of 100); 8.8 MB takes 242 MB. The limit of
     *   4 MiB is about 160 MB.
     */
    public const MAX_TABLE_HTML_BYTES = 1000000;

    public const MAX_CARDS_HTML_BYTES = 4194304;

    /** The font of the whole document. */
    private const FONT = 'DejaVu Sans';

    /** The width of a table, in points: A4 on its side (842) less the two side margins of 24. A hair less, to be safe. */
    private const TABLE_WIDTH_PT = 793.0;

    /** The size of the font of the header of the columns, in points. */
    private const HEADER_SIZE_PT = 8.0;

    /**
     * What the repeated header takes, in CSS pixels (the library draws 96 pixels to the inch and 72 points: a pixel is 0.75 of a
     * point): the empty margin above it, the line of the title, the line about the export, a gap and the row of the columns.
     */
    private const HEAD_MARGIN = 26;

    private const HEAD_TITLE = 24;

    private const HEAD_SUMMARY = 16;

    private const HEAD_GAP = 6;

    private const HEAD_COLUMNS = 24;

    /** What stands between the repeated header and the first line of the page. */
    private const BODY_GAP = 3;

    private ?Dompdf $library = null;

    /**
     * The most items a PDF of an inventory with this many fields may hold: `inventories.pdf_max_items` up to 8 fields (the size of a
     * table, which is what that limit is measured on), and fewer beyond, in proportion: the library's memory goes with the number of
     * values it lays out (about 30 KB each), whether they are cells of a table or lines of a card.
     */
    public static function maxItems(int $fields): int
    {
        $limit = config()->integer('inventories.pdf_max_items');

        return $fields <= self::TABLE_FIELDS ? $limit : max(1, intdiv($limit * self::TABLE_FIELDS, $fields));
    }

    /**
     * Refuses a document whose HTML is more than the library can lay out in the memory of a worker: the limit of a table up to 8
     * fields, of cards beyond ({@see self::MAX_TABLE_HTML_BYTES}).
     *
     * @throws PdfTooLargeException When the HTML is over the limit of its layout.
     */
    public static function guard(string $html, int $fields): void
    {
        $budget = $fields <= self::TABLE_FIELDS ? self::MAX_TABLE_HTML_BYTES : self::MAX_CARDS_HTML_BYTES;

        if (strlen($html) > $budget) {
            throw new PdfTooLargeException('The HTML of the PDF is '.strlen($html)." bytes: the library cannot lay out more than {$budget} in the memory of a worker.");
        }
    }

    /**
     * Writes the document.
     *
     * @throws PdfTooLargeException When the document is too big for the library to lay out in memory.
     * @throws RuntimeException When the file cannot be written.
     */
    public function write(ExportContext $context, string $path): int
    {
        $count = 0;
        $html = $this->html($context, $count);

        // Checked before the library reads a byte of it: this is the document whose layout would take the memory of the worker.
        self::guard($html, $context->fields->count());

        $dompdf = $this->library();
        $dompdf->loadHtml($html, 'UTF-8');
        unset($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $this->footer($dompdf, $context->inventory->name);

        if (file_put_contents($path, $dompdf->output()) === false) {
            throw new RuntimeException('The PDF of the export could not be written.');
        }

        return $count;
    }

    /**
     * The page the library lays out: the repeated header, then a table or the cards, every value already formatted.
     *
     * @param  int  $count  Set to the number of items in the document.
     *
     * @throws PdfTooLargeException When the document has more items than a PDF may hold.
     */
    public function html(ExportContext $context, int &$count = 0): string
    {
        $limit = self::maxItems($context->fields->count());

        if (count($context->itemIds) > $limit) {
            throw new PdfTooLargeException("A PDF of an inventory with {$context->fields->count()} fields cannot hold more than {$limit} items.");
        }

        $formatter = new ExportFormatter($context->export->locale, DisplayTime::zone());
        $asTable = $context->fields->count() <= self::TABLE_FIELDS;
        $rows = [];

        foreach ($context->items() as $item) {
            $rows[] = $asTable ? $this->cells($context, $formatter, $item) : $this->card($context, $formatter, $item);
        }

        $count = count($rows);
        $inventory = $context->inventory;
        $metrics = $this->library()->getFontMetrics();
        $columns = [];

        if ($asTable && $count > 0) {
            $headers = array_values($context->table->fields()->keys()->map(fn (int $position): string => $context->table->header($position))->all());
            /** @var list<list<string>> $rows */
            $columns = PdfColumns::plan($headers, $rows, $this->numericColumns($context), $metrics, $metrics->getFont(self::FONT, 'bold') ?? self::FONT, self::TABLE_WIDTH_PT, self::HEADER_SIZE_PT);
        }

        $head = self::HEAD_TITLE + self::HEAD_SUMMARY + ($columns === [] ? 0 : self::HEAD_GAP + self::HEAD_COLUMNS);
        // A table is right under its header; cards, which have a border of their own, get some air.
        $gap = $columns === [] ? self::BODY_GAP + 5 : self::BODY_GAP;

        return view('exports.pdf', [
            'locale' => $context->export->locale,
            'title' => $inventory->name,
            'headTitle' => PdfColumns::fit($inventory->name, self::TABLE_WIDTH_PT, $metrics, $metrics->getFont(self::FONT, 'bold') ?? self::FONT, 15.0),
            'description' => $inventory->description,
            'exportedAt' => $formatter->moment(now()->toDateTimeImmutable()),
            'count' => $count,
            'filtered' => $context->export->scope === ExportScope::View,
            'asTable' => $asTable,
            'columns' => $columns,
            'rows' => $rows,
            'tableRows' => self::TABLE_ROWS,
            // The header is fixed in the top margin of every page: the margin is as high as it is, with what is above and below.
            'headHeight' => $head,
            'headTop' => $head + $gap,
            'headMargin' => self::HEAD_MARGIN,
            'marginTop' => self::HEAD_MARGIN + $head + $gap,
            'titleLine' => self::HEAD_TITLE,
            'summaryLine' => self::HEAD_SUMMARY,
            'columnsLine' => self::HEAD_COLUMNS,
            'headGap' => self::HEAD_GAP,
        ])->render();
    }

    /**
     * The cells of an item for a table: the value of each field, as text.
     *
     * @return list<string>
     */
    private function cells(ExportContext $context, ExportFormatter $formatter, Item $item): array
    {
        $cells = [];

        foreach ($context->table->row($item) as $position => $value) {
            $cells[] = $this->text($context, $formatter, $item, $position, $value, self::TABLE_TEXT);
        }

        return $cells;
    }

    /**
     * An item as a card: its title, and the label and value of each field that has one.
     *
     * @return array{title: string, lines: list<array{0: string, 1: string}>, updated: string}
     */
    private function card(ExportContext $context, ExportFormatter $formatter, Item $item): array
    {
        $title = '';
        $lines = [];

        foreach ($context->table->row($item) as $position => $value) {
            $text = $this->text($context, $formatter, $item, $position, $value, self::CARD_TEXT);

            if ($title === '' && $value->kind === ExportKind::Text && $context->table->field($position)->type === 'text') {
                $title = $text;
            }

            if ($text !== '') {
                $lines[] = [$context->table->field($position)->name, $text];
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
    private function text(ExportContext $context, ExportFormatter $formatter, Item $item, int $position, ExportValue $value, int $max): string
    {
        $type = $context->table->typeAt($position);

        if ($type instanceof UploadedFilesType) {
            $count = $context->table->fileCount($item, $position);

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
    private function numericColumns(ExportContext $context): array
    {
        return array_values($context->table->fields()->map(fn (InventoryField $field, int $position): bool => $context->table->typeAt($position)->queryKind() === QueryKind::Number)->all());
    }

    /**
     * The library, set not to read anything outside the document. One for the whole writing: its font metrics measure the header
     * before the same instance lays the document out.
     */
    private function library(): Dompdf
    {
        if ($this->library !== null) {
            return $this->library;
        }

        $directory = ExportTemp::directory('dompdf');
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([$directory]);
        $options->setFontDir($directory);
        $options->setFontCache($directory);
        $options->setTempDir($directory);
        $options->setDefaultFont(self::FONT);

        return $this->library = new Dompdf($options);
    }

    /**
     * Writes "name" and "page X / Y" at the foot of every page.
     */
    private function footer(Dompdf $dompdf, string $name): void
    {
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont(self::FONT) ?? self::FONT;
        $size = 7.0;
        $page = __('Page :page / :pages', ['page' => '{PAGE_NUM}', 'pages' => '{PAGE_COUNT}']);
        $width = $metrics->getTextWidth(__('Page :page / :pages', ['page' => '00', 'pages' => '00']), $font, $size);
        $y = $canvas->get_height() - 26;

        $canvas->page_text(32, $y, mb_strlen($name) > 80 ? mb_substr($name, 0, 79).'…' : $name, $font, $size, [0.4, 0.4, 0.4]);
        $canvas->page_text($canvas->get_width() - 32 - $width, $y, $page, $font, $size, [0.4, 0.4, 0.4]);
    }
}
