<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\Fields\ExportKind;
use App\Inventories\Fields\ExportValue;
use App\Models\Item;
use App\Support\DisplayTime;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\BooleanCell;
use OpenSpout\Common\Entity\Cell\DateIntervalCell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\ODS\Entity\ViewSettings;
use OpenSpout\Writer\ODS\Options as OdsOptions;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

/**
 * Writes the items as a workbook with one sheet, XLSX or ODS (the same cells, two containers).
 *
 * The cells are typed: a number is a number, a date a date, a yes or no a boolean, whatever the format of the cell shows. Every
 * cell is built explicitly and **never from a bare value**: the library's shortcut makes a formula of any string that starts with
 * `=`, and a text a person typed in an inventory must never be run by the spreadsheet that opens the export. A string cell is
 * text, always.
 *
 * - A number with more than 15 significant digits is written as text (a spreadsheet keeps 15 exactly: the rest would be lost).
 * - A date before 1900 is written as text (a spreadsheet has no such date).
 * - A moment is the wall-clock time in the timezone of the instance, as the people of the instance read it (a spreadsheet has no
 *   timezone). The date formats are the built-in ones (numbers 14, 20 and 22), which the spreadsheet shows in the language of
 *   the person who opens it.
 * - The header is bold and frozen, with an automatic filter on the whole table, and the sheet is named after the inventory.
 *
 * The library writes the rows to a scratch file as they come: memory does not grow with the number of items.
 */
final class SpreadsheetWriter implements ExportWriter
{
    /** The number of significant digits a spreadsheet keeps exactly. */
    private const EXACT_DIGITS = 15;

    /** The first year a spreadsheet can hold. */
    private const FIRST_YEAR = 1900;

    /** The built-in formats (14, 22 and 20) that the spreadsheet shows in the language of the person who opens it. */
    private const DATE_FORMAT = 'mm-dd-yy';

    private const DATETIME_FORMAT = 'm/d/yy h:mm';

    private const TIME_FORMAT = 'h:mm';

    /** @var array<non-empty-string, Style> The styles already built, by number format. */
    private array $styles = [];

    /**
     * Remembers which of the two formats this writes.
     */
    public function __construct(private readonly ExportFormat $format) {}

    /**
     * Writes the workbook.
     *
     * @throws RuntimeException When the format is not a spreadsheet.
     */
    public function write(ExportContext $context, string $path): int
    {
        if (! $this->format->isSpreadsheet()) {
            throw new RuntimeException("The format [{$this->format->value}] is not a spreadsheet.");
        }

        // One font for the whole sheet: the library's default for the plain cells would otherwise differ from the styled ones.
        $options = ['FALLBACK_STYLE' => new Style, 'tempFolder' => ExportTemp::directory()];
        $writer = $this->format === ExportFormat::Xlsx ? new XlsxWriter(new XlsxOptions(...$options)) : new OdsWriter(new OdsOptions(...$options));

        // A number is written with the shortest text that gives it back exactly (the library writes a float as a string).
        $precision = ini_set('precision', '-1');
        $rows = 0;

        try {
            $writer->openToFile($path);

            $sheet = $writer->getCurrentSheet();
            $name = ExportNames::sheet($context->inventory->name);
            $sheet->setName($name);
            $sheet->setSheetView($writer instanceof XlsxWriter
                // The first row that scrolls: 2 freezes the first one.
                ? new SheetView(freezeRow: 2)
                : new ViewSettings($name, freezeRow: 1));

            $headers = $context->table->headers();
            $bold = new Style(fontBold: true);
            $writer->addRow(new Row(array_map(fn (string $header): Cell => new StringCell($header, $bold), $headers)));

            foreach ($context->items() as $item) {
                $writer->addRow(new Row($this->cells($context, $item)));
                $rows++;
            }

            $last = max(0, count($headers) - 1);
            $sheet->setAutoFilter(new AutoFilter(0, 1, $last, $rows + 1));

            // Columns are numbered from 1 here: the identifier, the fields, then the two dates.
            foreach ($headers as $index => $header) {
                $sheet->setColumnWidth($this->width($index, $last, $header), $index + 1);
            }

            $writer->close();
        } finally {
            if ($precision !== false) {
                ini_set('precision', $precision);
            }
        }

        return $rows;
    }

    /**
     * The cells of an item: its identifier, a cell for each field, the dates it was created and last changed.
     *
     * @return list<Cell>
     */
    private function cells(ExportContext $context, Item $item): array
    {
        $cells = [new StringCell($item->ulid)];

        foreach ($context->table->row($item) as $value) {
            $cells[] = $this->cell($value);
        }

        $cells[] = $this->moment($item->created_at === null ? null : DisplayTime::of($item->created_at));
        $cells[] = $this->moment($item->updated_at === null ? null : DisplayTime::of($item->updated_at));

        return $cells;
    }

    /**
     * The cell for a value: the one that keeps what it is.
     */
    private function cell(ExportValue $value): Cell
    {
        return match ($value->kind) {
            ExportKind::Empty => new EmptyCell(null),
            ExportKind::Text => new StringCell($value->string()),
            ExportKind::Number => $this->number($value),
            ExportKind::Date => $this->date($value->string()),
            ExportKind::DateTime => $this->moment($value->moment()),
            ExportKind::Time => $this->time($value->string()),
            ExportKind::Boolean => new BooleanCell($value->answer() === true),
        };
    }

    /**
     * A number cell with the decimals of its field, or a text cell when a spreadsheet could not keep the number whole.
     */
    private function number(ExportValue $value): Cell
    {
        if ($value->significantDigits() > self::EXACT_DIGITS) {
            return new StringCell($value->numberText());
        }

        // At least the decimals of the field, and more when the value holds more.
        $text = $value->numberText();
        $point = strpos($text, '.');
        $decimals = $point === false ? 0 : strlen($text) - $point - 1;
        $format = ($value->currency !== null ? '#,##0' : '0').($decimals > 0 ? '.'.str_repeat('0', $decimals) : '');

        return new NumericCell($point === false ? (int) $text : (float) $text, $this->style($format));
    }

    /**
     * A date cell, or a text cell for a day before 1900.
     */
    private function date(string $day): Cell
    {
        if ((int) substr($day, 0, 4) < self::FIRST_YEAR) {
            return new StringCell($day);
        }

        return new DateTimeCell(new DateTimeImmutable("{$day} 00:00:00", new DateTimeZone('UTC')), $this->style(self::DATE_FORMAT));
    }

    /**
     * A cell for a moment, as the wall-clock time of its timezone; empty for none; text before 1900.
     */
    private function moment(?DateTimeInterface $moment): Cell
    {
        if ($moment === null) {
            return new EmptyCell(null);
        }

        if ((int) $moment->format('Y') < self::FIRST_YEAR) {
            return new StringCell($moment->format('Y-m-d H:i'));
        }

        return new DateTimeCell(new DateTimeImmutable($moment->format('Y-m-d H:i:s'), new DateTimeZone('UTC')), $this->style(self::DATETIME_FORMAT));
    }

    /**
     * A time cell: the length of time since midnight, shown as a time of the day.
     */
    private function time(string $time): Cell
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', $time));

        return new DateIntervalCell(new DateInterval("PT{$hours}H{$minutes}M"), $this->style(self::TIME_FORMAT));
    }

    /**
     * The style that shows a value in a format, built once.
     *
     * @param  non-empty-string  $format
     */
    private function style(string $format): Style
    {
        return $this->styles[$format] ??= new Style(format: $format);
    }

    /**
     * How wide a column is, in characters: wide enough for its header, between 12 and 40, more for the identifier and the dates.
     */
    private function width(int $index, int $last, string $header): float
    {
        return match (true) {
            $index === 0 => 28.0,
            $index >= $last - 1 => 18.0,
            default => (float) min(40, max(12, mb_strlen($header) + 4)),
        };
    }
}
