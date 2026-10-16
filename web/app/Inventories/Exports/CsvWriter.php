<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\Fields\ExportKind;
use App\Inventories\Fields\ExportValue;
use App\Support\DisplayTime;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;
use Throwable;

/**
 * Writes the items as a CSV: UTF-8 with a byte order mark (the spreadsheets that open it by double-click read it right), the
 * identifier, the fields and the two dates, one item a line.
 *
 * The separator is the person's choice (`comma`, `semicolon` or `tab`), the semicolon by default in French and the comma in the
 * other languages. **The separator also decides the decimal sign**, on purpose: a spreadsheet in French reads a semicolon file and
 * expects a comma for its decimals (`12,5`), and a comma-separated file could not hold one at all without quoting every number,
 * so the semicolon writes the decimals with a comma and the comma and the tab write them with a point (`12.5`). The dialog says
 * so next to each separator, and an API client that wants a point with a semicolon must choose the comma or the tab. Dates are ISO (`2026-10-15`, `2026-10-15 14:30`), a yes or no is "Yes" or "No" in the language of the
 * export, a number has the decimals of its field and no thousands separator.
 *
 * A text that starts with one of `= + - @` or a tab or a carriage return is read as a formula by the spreadsheet that opens it,
 * and a formula can read other files or call out: such a text, and such a header, is written with a quote in front (`'=SUM(A1)`),
 * which the spreadsheets drop when they show it. Numbers are not texts: `-5` is written as is.
 */
final class CsvWriter implements ExportWriter
{
    /** The separators a person can choose, by name. */
    public const DELIMITERS = ['comma' => ',', 'semicolon' => ';', 'tab' => "\t"];

    /** The characters that make a spreadsheet read a text as a formula. */
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Writes the CSV.
     */
    public function write(ExportContext $context, string $path): int
    {
        $name = $context->export->option('delimiter');
        // Without a (known) choice: the semicolon in French, the comma otherwise.
        $name = is_string($name) && isset(self::DELIMITERS[$name]) ? $name : ($context->export->locale === 'fr' ? 'semicolon' : 'comma');
        $decimal = $name === 'semicolon' ? ',' : '.';

        $writer = new Writer(new Options(FIELD_DELIMITER: self::DELIMITERS[$name], EOL: "\r\n"));
        $writer->openToFile($path);
        $closed = false;

        try {
            $writer->addRow($this->row(array_map($this->safe(...), $context->table->headers())));

            $rows = 0;

            foreach ($context->items() as $item) {
                $cells = [$item->ulid];

                foreach ($context->table->row($item) as $value) {
                    $cells[] = $this->text($value, $decimal);
                }

                $cells[] = $item->created_at === null ? '' : DisplayTime::of($item->created_at)->format('Y-m-d H:i');
                $cells[] = $item->updated_at === null ? '' : DisplayTime::of($item->updated_at)->format('Y-m-d H:i');

                $writer->addRow($this->row($cells));
                $rows++;
            }

            // Not in the `finally`: a file that could not be completed is a failure to report, not one to tolerate.
            $closed = true;
            $writer->close();
        } finally {
            if (! $closed) {
                // Something went wrong half way: release the file, whatever it says about it (the first error is the one that counts).
                try {
                    $writer->close();
                } catch (Throwable) {
                    // Nothing more can be done for a file that is going to be deleted.
                }
            }
        }

        return $rows;
    }

    /**
     * A value as the text of a cell.
     */
    private function text(ExportValue $value, string $decimal): string
    {
        return match ($value->kind) {
            ExportKind::Empty => '',
            ExportKind::Text => $this->safe($value->string()),
            ExportKind::Number => str_replace('.', $decimal, $value->numberText()),
            ExportKind::Date, ExportKind::Time => $value->string(),
            ExportKind::DateTime => $value->moment()?->format('Y-m-d H:i') ?? '',
            ExportKind::Boolean => $value->answer() === true ? __('Yes') : __('No'),
        };
    }

    /**
     * A text that no spreadsheet reads as a formula: with a quote in front when it starts like one.
     */
    private function safe(string $text): string
    {
        return $text !== '' && in_array($text[0], self::FORMULA_STARTS, true) ? "'".$text : $text;
    }

    /**
     * A line of the file from the texts of its cells.
     *
     * @param  list<string>  $texts
     */
    private function row(array $texts): Row
    {
        return new Row(array_map(fn (string $text): Cell => new StringCell($text), $texts));
    }
}
