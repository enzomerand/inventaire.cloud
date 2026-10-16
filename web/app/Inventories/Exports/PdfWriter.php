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
use Dompdf\Options;
use RuntimeException;

/**
 * Writes the items as a PDF to print: A4 landscape, a header with the name of the inventory, the date and the number of items, the
 * values written the way the language writes them ({@see ExportFormatter}), the number of the page at the foot.
 *
 * Up to 8 fields the items are a table, one row each, with its header repeated on every page; with more fields they would not fit on
 * a line, and each item is a card instead (`field: value`, the first text as its title). A file or photo field shows how many files
 * the item holds ("2 photos"), not their names. A long text is cut short (the document is to be read, the full text is in the
 * other formats).
 *
 * The library lays the whole document out in memory, and there is no streaming it: that is why a PDF has a limit of items
 * (`inventories.pdf_max_items`, checked when the export is asked for and again here). It is set not to read anything outside the
 * document (no remote file, no PHP, no script) and its font cache lives under `storage/framework`, never in `vendor/`. The text is
 * in DejaVu Sans, which the library ships and embeds, so that every accent of the languages of the application is drawn.
 */
final class PdfWriter implements ExportWriter
{
    /** Most fields a table holds: above, an item is a card. */
    public const TABLE_FIELDS = 8;

    /** Rows of a table before another one starts (with its header again): the library's memory grows faster than a table does. */
    private const TABLE_ROWS = 25;

    /** Most characters of a value in a table cell, and in a card. */
    private const TABLE_TEXT = 300;

    private const CARD_TEXT = 2000;

    /** The font of the whole document. */
    private const FONT = 'DejaVu Sans';

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
     * Writes the document.
     *
     * @throws RuntimeException When the document has more items than a PDF may hold.
     */
    public function write(ExportContext $context, string $path): int
    {
        $count = 0;
        $html = $this->html($context, $count);

        $dompdf = $this->dompdf();
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
     * The page the library lays out: the header, then a table or the cards, every value already formatted.
     *
     * @param  int  $count  Set to the number of items in the document.
     *
     * @throws RuntimeException When the document has more items than a PDF may hold.
     */
    public function html(ExportContext $context, int &$count = 0): string
    {
        $limit = self::maxItems($context->fields->count());

        if (count($context->itemIds) > $limit) {
            throw new RuntimeException("A PDF of an inventory with {$context->fields->count()} fields cannot hold more than {$limit} items.");
        }

        $formatter = new ExportFormatter($context->export->locale, DisplayTime::zone());
        $asTable = $context->fields->count() <= self::TABLE_FIELDS;
        $rows = [];

        foreach ($context->items() as $item) {
            $rows[] = $asTable ? $this->cells($context, $formatter, $item) : $this->card($context, $formatter, $item);
        }

        $count = count($rows);
        $inventory = $context->inventory;

        return view('exports.pdf', [
            'locale' => $context->export->locale,
            'title' => $inventory->name,
            'description' => $inventory->description,
            'exportedAt' => $formatter->moment(now()->toDateTimeImmutable()),
            'count' => $count,
            'filtered' => $context->export->scope === ExportScope::View,
            'asTable' => $asTable,
            'headers' => $context->table->fields()->keys()->map(fn (int $position): string => $context->table->header($position))->all(),
            'numeric' => $this->numericColumns($context),
            'rows' => $rows,
            'tableRows' => self::TABLE_ROWS,
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
     * The library, set not to read anything outside the document.
     */
    private function dompdf(): Dompdf
    {
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

        return new Dompdf($options);
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
