<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The PDF library, set the way every PDF of the application needs it: not to read anything outside the document (no remote file,
 * no PHP, no script), its font cache under `storage/framework` and never in `vendor/`, and the font that is embedded whole so that
 * every accent of the languages of the application is drawn.
 *
 * What the PDF of an export ({@see PdfWriter}) and the PDF of an official version share.
 */
final class PdfLibrary
{
    /** The font of the whole document: DejaVu Sans, which the library ships and embeds. */
    public const FONT = 'DejaVu Sans';

    /**
     * A new library, ready to lay out a document. One is made for each document: its font metrics measure the header before the
     * same instance lays the document out.
     */
    public static function make(): Dompdf
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
     * Writes a note at the left and "page X / Y" at the right of the foot of every page.
     *
     * @param  Dompdf  $dompdf  The library that lays the document out, once the document is loaded.
     * @param  string  $note  What the foot says at the left of every page, as it is to be written.
     */
    public static function footer(Dompdf $dompdf, string $note): void
    {
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont(self::FONT) ?? self::FONT;
        $size = 7.0;
        $page = __('Page :page / :pages', ['page' => '{PAGE_NUM}', 'pages' => '{PAGE_COUNT}']);
        $width = $metrics->getTextWidth(__('Page :page / :pages', ['page' => '00', 'pages' => '00']), $font, $size);
        $y = $canvas->get_height() - 26;

        $canvas->page_text(32, $y, $note, $font, $size, [0.4, 0.4, 0.4]);
        $canvas->page_text($canvas->get_width() - 32 - $width, $y, $page, $font, $size, [0.4, 0.4, 0.4]);
    }
}
