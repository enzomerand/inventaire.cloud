<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\Exceptions\PdfTooLargeException;
use App\Inventories\Fields\FieldTypes;
use App\Models\InventoryField;
use App\Support\DisplayTime;
use Dompdf\Dompdf;
use Illuminate\Support\Collection;
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
 * many fragments of {@see PdfBody::TABLE_ROWS} rows without any header of their own, all with the same widths, so that the
 * header is on every page and only once. The block has a known height, which the top margin of the page is made of.
 *
 * The library lays the whole document out in memory, and there is no streaming it: that is why a PDF has two limits. One is on the
 * number of items (`inventories.pdf_max_items`, checked when the export is asked for and again here); the other is on the size of
 * the HTML the library is given ({@see self::MAX_TABLE_HTML_BYTES}, {@see self::MAX_CARDS_HTML_BYTES}), which is what its memory
 * really goes with: texts cut at 120 and 800 characters ({@see PdfBody}), at the most items, stay under it.
 * Both are refused with {@see PdfTooLargeException}, which tells the person to filter the view. The library is set not to read
 * anything outside the document (no remote file, no PHP, no script) and its font cache lives under `storage/framework`, never in
 * `vendor/`. The text is in DejaVu Sans, which the library ships and embeds, so that every accent of the languages of the
 * application is drawn.
 *
 * What lays the items out ({@see PdfBody}), the header ({@see PdfHead}) and the library ({@see PdfLibrary}) is shared with the PDF of
 * an official version.
 */
final class PdfWriter implements ExportWriter
{
    /** Most fields a table holds: above, an item is a card. */
    public const TABLE_FIELDS = 8;

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
     * The most items a PDF of an inventory may hold: {@see self::maxItems()} for the fields this version knows the type of (the others
     * are left out of the document). What both the request for an export and the freezing of an official version judge a PDF by.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory.
     */
    public static function limitFor(Collection $fields, FieldTypes $types): int
    {
        return self::maxItems($fields->filter(fn (InventoryField $field): bool => $types->of($field) !== null)->count());
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
        $library = $this->library();
        $body = PdfBody::make($library, $context->table, $formatter, $context->items());
        $count = $body->count;
        $inventory = $context->inventory;
        $metrics = $library->getFontMetrics();
        $head = PdfHead::of(1, $body->columns !== []);

        return view('exports.pdf', [
            'locale' => $context->export->locale,
            'title' => $inventory->name,
            'headTitle' => PdfColumns::fit($inventory->name, PdfBody::TABLE_WIDTH_PT, $metrics, $metrics->getFont(PdfLibrary::FONT, 'bold') ?? PdfLibrary::FONT, 15.0),
            'description' => $inventory->description,
            'exportedAt' => $formatter->moment(now()->toDateTimeImmutable()),
            'count' => $count,
            'filtered' => $context->export->scope === ExportScope::View,
            'asTable' => $body->asTable,
            'columns' => $body->columns,
            'rows' => $body->rows,
            'tableRows' => PdfBody::TABLE_ROWS,
        ] + $head->view())->render();
    }

    /**
     * The library, set not to read anything outside the document. One for the whole writing: its font metrics measure the header
     * before the same instance lays the document out.
     */
    private function library(): Dompdf
    {
        return $this->library ??= PdfLibrary::make();
    }

    /**
     * Writes "name" and "page X / Y" at the foot of every page.
     */
    private function footer(Dompdf $dompdf, string $name): void
    {
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont(PdfLibrary::FONT) ?? PdfLibrary::FONT;
        $size = 7.0;
        $page = __('Page :page / :pages', ['page' => '{PAGE_NUM}', 'pages' => '{PAGE_COUNT}']);
        $width = $metrics->getTextWidth(__('Page :page / :pages', ['page' => '00', 'pages' => '00']), $font, $size);
        $y = $canvas->get_height() - 26;

        $canvas->page_text(32, $y, mb_strlen($name) > 80 ? mb_substr($name, 0, 79).'…' : $name, $font, $size, [0.4, 0.4, 0.4]);
        $canvas->page_text($canvas->get_width() - 32 - $width, $y, $page, $font, $size, [0.4, 0.4, 0.4]);
    }
}
