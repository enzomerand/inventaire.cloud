<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

use App\Inventories\Exceptions\PdfTooLargeException;
use App\Inventories\Exports\ExportFormatter;
use App\Inventories\Exports\ExportTable;
use App\Inventories\Exports\PdfBody;
use App\Inventories\Exports\PdfColumns;
use App\Inventories\Exports\PdfHead;
use App\Inventories\Exports\PdfLibrary;
use App\Inventories\Exports\PdfWriter;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\ItemValues;
use App\Models\InventoryVersion;
use App\Support\DisplayTime;
use DOMElement;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Frame;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes the PDF of an official version from its snapshot: A4 landscape, the items as a table or cards exactly as the PDF of an
 * export lays them out ({@see PdfBody}), and, when signatures were asked for, a last page with a box for each signer.
 *
 * **Every page** carries a header that says what the document is and what it proves: "Official version no. N" (with the label, if any),
 * the moment it was frozen in the timezone of the instance and in UTC, the name of the inventory, the author, the number of items and the
 * full SHA-256 of the snapshot; and a footer with the version, the first characters of that SHA-256 and "Page x / y". The row of the
 * columns of a table is drawn on the pages of the items, not on the page of signatures, which is why the library does not repeat it
 * (see {@see self::columnRow()}); the space it takes is kept all the same. The description of the inventory, when it has one, is the
 * first thing on the first page below the header, in at most {@see self::DESCRIPTION_MAX_CHARS} characters (a long description must not take
 * the first page); on a table the row of the columns is drawn under it, on that page, so that it stays right above the rows it names.
 *
 * **The page of signatures** is a table of boxes, two to a row, ten at most (`inventories.max_signers`): name, e-mail, and an empty
 * area the signature goes in. Where each area is on the page is read from the layout the library has just made (its padding box, the
 * inside of the border), never worked out from constants that the layout could drift from: that is what the signature service is told.
 * A position is `{page, x, y, width, height}` with the page the last one and the other four as percentages of the page, `y` from the
 * top. The writer refuses (the version fails) a layout in which the areas do not all lie on the one last page, in the printable part of it.
 * What stands above the boxes does not depend on the names it holds, so that ten signers always fit: the name of the inventory in the
 * notice is cut to {@see self::NOTICE_NAME_PT} points (it is written whole in the header of the page, up to the width of the line, and
 * in the data), which keeps the notice to two lines in the languages of the application. The notice is never clipped: it is at least
 * two lines high, so that the boxes sit at the same place for the usual notices, and it takes the room its text needs if that is more;
 * the check of the areas above then decides, on the layout the library made, whether they all fit.
 */
final class OfficialPdfWriter
{
    /** The width of the empty area a signature is drawn in, in CSS pixels. */
    public const AREA_WIDTH_PX = 330;

    /** Its height, in CSS pixels. */
    public const AREA_HEIGHT_PX = 48;

    /** The boxes of the page of signatures in a row. */
    private const SIGNERS_PER_ROW = 2;

    /** The size of the font of the lines of the header, in points. */
    private const LINE_SIZE_PT = 8.0;

    /** The size of the title, in points. */
    private const TITLE_SIZE_PT = 15.0;

    /** The width a name or an e-mail address may take in a box of the page of signatures, in points. */
    private const SIGNER_TEXT_PT = 330.0;

    /** The width the name of the inventory may take in the notice of the page of signatures, in points. */
    private const NOTICE_NAME_PT = 300.0;

    /** The most characters of the description of the inventory that are written on the first page. */
    public const DESCRIPTION_MAX_CHARS = 500;

    /** The air above and below the row of the columns when it is drawn under the description, in CSS pixels. */
    private const DESCRIPTION_COLUMNS_GAP = 3;

    /** The bottom margin of the pages, in CSS pixels (see the stylesheet). */
    private const BOTTOM_MARGIN_PX = 48;

    /** A CSS pixel in points: the library draws 96 pixels to the inch and 72 points. */
    private const PX = 0.75;

    /**
     * Gets the registry of types and the checks of values, which the table of items is made with.
     */
    public function __construct(
        private readonly FieldTypes $types,
        private readonly ItemValues $values,
    ) {}

    /**
     * Writes the PDF of a version.
     *
     * @param  InventoryVersion  $version  The version: its number, label, date, author, language and SHA-256, and the people asked to sign.
     * @param  VersionContent  $content  What the snapshot holds.
     * @param  string  $path  Where on this machine the file is written.
     * @return array{pages: int, areas: list<array{page: int, x: float, y: float, width: float, height: float}>} The number of pages, and the signature area of each signer (in the order of `$version->signers`).
     *
     * @throws PdfTooLargeException When the document is too big for the library to lay out in memory.
     * @throws RuntimeException When the file cannot be written, or the page of signatures cannot be laid out.
     */
    public function write(InventoryVersion $version, VersionContent $content, string $path): array
    {
        $library = PdfLibrary::make();
        $layout = $this->layout($version, $content, $library);
        $html = $layout['html'];
        $body = $layout['body'];
        $signers = $layout['signers'];
        $head = $layout['head'];

        // Checked before the library reads a byte of it: this is the document whose layout would take the memory of the worker.
        PdfWriter::guard($html, $content->fields->count());

        // The library frees the frames of a page as soon as it is drawn: the position of each signature area (and of the description,
        // which the row of the columns of the first page goes under) is read while it is.
        $boxes = [];
        $library->setCallbacks([[
            'event' => 'end_frame',
            'f' => function (Frame $frame, Canvas $canvas) use (&$boxes): void {
                $node = $frame->get_node();

                if ($node instanceof DOMElement && (str_starts_with($node->getAttribute('id'), 'signer-') || $node->getAttribute('id') === 'description')) {
                    $boxes[$node->getAttribute('id')] = $this->box($frame, $canvas->get_page_number());
                }
            },
        ]]);
        $library->loadHtml($html, 'UTF-8');
        unset($html);
        $library->setPaper('A4', 'landscape');
        $library->render();

        $pages = $library->getCanvas()->get_page_count();
        $this->columnRow($library, $body, $signers !== [], $boxes['description'] ?? null);
        $this->footer($library, $version);
        $areas = $signers === [] ? [] : $this->areas($library, $boxes, count($signers), $head);

        if (file_put_contents($path, $library->output()) === false) {
            throw new RuntimeException('The PDF of the version could not be written.');
        }

        return ['pages' => $pages, 'areas' => $areas];
    }

    /**
     * The page the library lays out: the repeated header, the items as a table or cards, and the page of signatures, every value
     * already formatted in the language of the version.
     *
     * @throws PdfTooLargeException When the document has more items than a PDF may hold.
     */
    public function html(InventoryVersion $version, VersionContent $content): string
    {
        return $this->layout($version, $content, PdfLibrary::make())['html'];
    }

    /**
     * The page, and what the writing of the file needs to know about it.
     *
     * @return array{html: string, body: PdfBody, signers: list<array{name: string, email: string}>, head: PdfHead}
     *
     * @throws PdfTooLargeException When the document has more items than a PDF may hold.
     */
    private function layout(InventoryVersion $version, VersionContent $content, Dompdf $library): array
    {
        $limit = PdfWriter::limitFor($content->fields, $this->types);

        if (count($content->items) > $limit) {
            throw new PdfTooLargeException('A PDF of an inventory with '.$content->fields->count()." fields cannot hold more than {$limit} items.");
        }

        $table = new ExportTable($content->fields, $this->types, $this->values);
        $formatter = new ExportFormatter($version->locale, DisplayTime::zone());
        $body = PdfBody::make($library, $table, $formatter, $content->items);
        $metrics = $library->getFontMetrics();
        $signers = $this->signers($version, $metrics);
        $head = PdfHead::of(3, $body->columns !== []);
        $bold = $metrics->getFont(PdfLibrary::FONT, 'bold') ?? PdfLibrary::FONT;
        $regular = $metrics->getFont(PdfLibrary::FONT) ?? PdfLibrary::FONT;

        $html = view('versions.pdf', [
            'locale' => $version->locale,
            'documentTitle' => $this->title($version),
            'headTitle' => PdfColumns::fit($this->title($version), PdfBody::TABLE_WIDTH_PT, $metrics, $bold, self::TITLE_SIZE_PT),
            'headLines' => $this->lines($version, $content, $body->count, $formatter, $metrics, $regular),
            'description' => $this->description($content->inventoryDescription),
            // Under the description, a table keeps the room of the row of its columns, which is drawn there on the first page.
            'descriptionGap' => $body->columns === [] ? 10 : PdfHead::COLUMNS + 2 * self::DESCRIPTION_COLUMNS_GAP,
            'count' => $body->count,
            'asTable' => $body->asTable,
            'columns' => $body->columns,
            'rows' => $body->rows,
            'tableRows' => PdfBody::TABLE_ROWS,
            'signers' => $signers,
            'signNotice' => __('By signing, the people below acknowledge version no. :number of the inventory “:inventory”, whose data has the SHA-256 fingerprint written at the top of every page.', [
                'number' => $version->number,
                'inventory' => PdfColumns::fit($content->inventoryName, self::NOTICE_NAME_PT, $metrics, $regular, self::LINE_SIZE_PT),
            ]),
            'perRow' => self::SIGNERS_PER_ROW,
            'areaWidth' => self::AREA_WIDTH_PX,
            'areaHeight' => self::AREA_HEIGHT_PX,
        ] + $head->view())->render();

        return ['html' => $html, 'body' => $body, 'signers' => $signers, 'head' => $head];
    }

    /**
     * The description of the inventory as the first page shows it: on one line of text (HTML folds the breaks anyway), cut to
     * {@see self::DESCRIPTION_MAX_CHARS} characters, the ellipsis included. Null when there is nothing to show.
     */
    private function description(?string $description): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $description));

        return $text === '' ? null : Str::limit($text, self::DESCRIPTION_MAX_CHARS - 1, '…');
    }

    /**
     * The title of the document: "Official version no. 3", and the label of the version when it has one.
     */
    private function title(InventoryVersion $version): string
    {
        $title = __('Official version no. :number', ['number' => $version->number]);

        return $version->label === null ? $title : "{$title} · {$version->label}";
    }

    /**
     * The three lines under the title, each of which fits the width of the page: when the version was frozen (in the timezone of the
     * instance, then in UTC) and of what inventory; who froze it and how many items it holds; the SHA-256 of the data, whole.
     *
     * @return list<string>
     */
    private function lines(InventoryVersion $version, VersionContent $content, int $items, ExportFormatter $local, FontMetrics $metrics, string $font): array
    {
        $moment = $version->frozen_at->toDateTimeImmutable();
        $frozen = __('Frozen on :date', ['date' => $local->moment($moment).' ('.DisplayTime::zone().')']).' · '.(new ExportFormatter($version->locale, 'UTC'))->moment($moment).' UTC';
        $width = PdfBody::TABLE_WIDTH_PT;

        return [
            PdfColumns::fit("{$frozen} · {$content->inventoryName}", $width, $metrics, $font, self::LINE_SIZE_PT),
            PdfColumns::fit(__('Author: :name', ['name' => $version->author_name]).' · '.trans_choice(':count item|:count items', $items), $width, $metrics, $font, self::LINE_SIZE_PT),
            __('SHA-256 of the data: :hash', ['hash' => $version->snapshot_sha256]),
        ];
    }

    /**
     * The people to sign, as the page of signatures shows them: their name and e-mail address, cut to fit a box.
     *
     * @return list<array{name: string, email: string}>
     */
    private function signers(InventoryVersion $version, FontMetrics $metrics): array
    {
        $bold = $metrics->getFont(PdfLibrary::FONT, 'bold') ?? PdfLibrary::FONT;
        $regular = $metrics->getFont(PdfLibrary::FONT) ?? PdfLibrary::FONT;
        $signers = [];

        foreach ($version->signers ?? [] as $signer) {
            $signers[] = [
                'name' => PdfColumns::fit(is_string($signer['name'] ?? null) ? $signer['name'] : '', self::SIGNER_TEXT_PT, $metrics, $bold, 9.0),
                'email' => PdfColumns::fit(is_string($signer['email'] ?? null) ? $signer['email'] : '', self::SIGNER_TEXT_PT, $metrics, $regular, self::LINE_SIZE_PT),
            ];
        }

        return $signers;
    }

    /**
     * Draws the row of the columns of a table at the top of every page of the items, where the library would have repeated it.
     *
     * It is drawn here and not written in the repeated header because the library repeats the header on every page, the page of
     * signatures included, where the columns of the items would stand over the boxes of the signers. The row is placed where the
     * header of an export puts it, with the same widths, borders and colours, so that the columns of the items line up under it.
     *
     * The first page has the description of the inventory right under the header when there is one: the row is then drawn under the
     * description, in the room the view keeps for it, not above it.
     *
     * @param  array{page: int, x: float, y: float, width: float, height: float}|null  $description  Where the library laid the description out.
     */
    private function columnRow(Dompdf $library, PdfBody $body, bool $skipLast, ?array $description): void
    {
        if ($body->columns === []) {
            return;
        }

        $canvas = $library->getCanvas();
        $metrics = $library->getFontMetrics();
        $font = $metrics->getFont(PdfLibrary::FONT, 'bold') ?? PdfLibrary::FONT;
        $size = PdfBody::HEADER_SIZE_PT;
        $left = 32 * self::PX;
        $width = $canvas->get_width() - 2 * $left;
        $header = (PdfHead::MARGIN + PdfHead::TITLE + 3 * PdfHead::LINE + PdfHead::GAP) * self::PX;
        $first = $description !== null && $description['page'] === 1 ? ($description['y'] + $description['height']) + self::DESCRIPTION_COLUMNS_GAP * self::PX : $header;
        $height = PdfHead::COLUMNS * self::PX;
        $padding = 5 * self::PX;
        $columns = $body->columns;
        $last = $canvas->get_page_count();

        $canvas->page_script(function (int $page) use ($canvas, $metrics, $columns, $skipLast, $last, $left, $width, $header, $first, $height, $padding, $font, $size): void {
            if ($skipLast && $page === $last) {
                return;
            }

            $top = $page === 1 ? $first : $header;
            $text = $top + ($height - $metrics->getFontHeight($font, $size)) / 2;

            $x = $left;

            foreach ($columns as $column) {
                $cell = $width * $column['share'] / 100;
                $canvas->filled_rectangle($x, $top, $cell, $height, [0.894, 0.914, 0.933]);
                $canvas->rectangle($x, $top, $cell, $height, [0.722, 0.757, 0.792], 0.75);
                $at = $column['numeric'] ? $x + $cell - $padding - $metrics->getTextWidth($column['label'], $font, $size) : $x + $padding;
                $canvas->text($at, $text, $column['label'], $font, $size, [0.122, 0.161, 0.2]);
                $x += $cell;
            }
        });
    }

    /**
     * Writes the version, the first characters of the SHA-256 and "page X / Y" at the foot of every page.
     */
    private function footer(Dompdf $library, InventoryVersion $version): void
    {
        $canvas = $library->getCanvas();
        $metrics = $library->getFontMetrics();
        $font = $metrics->getFont(PdfLibrary::FONT) ?? PdfLibrary::FONT;
        $size = 7.0;
        $page = __('Page :page / :pages', ['page' => '{PAGE_NUM}', 'pages' => '{PAGE_COUNT}']);
        $width = $metrics->getTextWidth(__('Page :page / :pages', ['page' => '00', 'pages' => '00']), $font, $size);
        $y = $canvas->get_height() - 26;
        $note = __('Official version no. :number', ['number' => $version->number]).' · '.__('SHA-256 :hash', ['hash' => substr($version->snapshot_sha256, 0, 16).'…']);

        $canvas->page_text(32, $y, $note, $font, $size, [0.4, 0.4, 0.4]);
        $canvas->page_text($canvas->get_width() - 32 - $width, $y, $page, $font, $size, [0.4, 0.4, 0.4]);
    }

    /**
     * Where the signature area of each signer is, from the boxes read while the library drew the pages.
     *
     * Each area is an element with the id `signer-N`; the library knows where it put it (its padding box: the empty inside of the
     * border) in points, the origin at the top left of the page, and on which page it drew it. The areas must all be on the last page,
     * which starts with the page of signatures (a page break comes before it), in the order of the signers with none higher up the
     * page than the one before (a page break in the table of boxes would send the next row back to the top), and all stand in the
     * printable part of the page.
     *
     * @param  array<string, array{page: int, x: float, y: float, width: float, height: float}>  $boxes  By id of the element.
     * @return list<array{page: int, x: float, y: float, width: float, height: float}>
     *
     * @throws RuntimeException When an area is missing, or the boxes do not all stand on the last page.
     */
    private function areas(Dompdf $library, array $boxes, int $count, PdfHead $head): array
    {
        $canvas = $library->getCanvas();
        $pageWidth = (float) $canvas->get_width();
        $pageHeight = (float) $canvas->get_height();
        $last = $canvas->get_page_count();
        $top = $head->margin * self::PX;
        $bottom = $pageHeight - self::BOTTOM_MARGIN_PX * self::PX;
        $areas = [];
        $previous = 0.0;

        for ($index = 0; $index < $count; $index++) {
            $box = $boxes["signer-{$index}"] ?? throw new RuntimeException("The signature area of the signer {$index} was not laid out.");

            if ($box['page'] !== $last || $box['y'] < $previous - 0.01 || $box['y'] < $top - 0.01 || $bottom + 0.01 < $box['y'] + $box['height'] || $box['x'] < 0 || $pageWidth < $box['x'] + $box['width']) {
                throw new RuntimeException('The signature areas do not all fit on the last page.');
            }

            $previous = $box['y'];
            $areas[] = [
                'page' => $box['page'],
                'x' => round($box['x'] / $pageWidth * 100, 4),
                'y' => round($box['y'] / $pageHeight * 100, 4),
                'width' => round($box['width'] / $pageWidth * 100, 4),
                'height' => round($box['height'] / $pageHeight * 100, 4),
            ];
        }

        return $areas;
    }

    /**
     * The padding box of a laid-out element, in points, with the page it is drawn on.
     *
     * @return array{page: int, x: float, y: float, width: float, height: float}
     */
    private function box(Frame $frame, int $page): array
    {
        $box = $frame->get_padding_box();

        return ['page' => $page, 'x' => (float) $box['x'], 'y' => (float) $box['y'], 'width' => (float) $box['w'], 'height' => (float) $box['h']];
    }
}
