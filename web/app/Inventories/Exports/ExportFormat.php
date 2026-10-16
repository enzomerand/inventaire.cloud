<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

/**
 * The kinds of file an export can be: what the person chooses, and what the file is called and announced as.
 *
 * A spreadsheet is the items as rows (typed cells: numbers, dates and yes/no stay what they are), the CSV is the same
 * thing as plain text for any other tool, the JSON is the whole inventory with the definitions of its fields (a backup
 * another system can read), the PDF is a document to print, and the zip holds the files attached to the items.
 */
enum ExportFormat: string
{
    case Xlsx = 'xlsx';
    case Ods = 'ods';
    case Csv = 'csv';
    case Json = 'json';
    case Pdf = 'pdf';
    case Zip = 'zip';

    /**
     * The extension of the file (without the dot): the value itself.
     */
    public function extension(): string
    {
        return $this->value;
    }

    /**
     * The type the file is served as: always one the browser downloads and never runs.
     */
    public function mime(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Ods => 'application/vnd.oasis.opendocument.spreadsheet',
            self::Csv => 'text/csv; charset=UTF-8',
            self::Json => 'application/json',
            self::Pdf => 'application/pdf',
            self::Zip => 'application/zip',
        };
    }

    /**
     * Whether the file is a spreadsheet written cell by cell (the two formats of the same writer).
     */
    public function isSpreadsheet(): bool
    {
        return $this === self::Xlsx || $this === self::Ods;
    }

    /**
     * The words of the format, as the API accepts them.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $format): string => $format->value, self::cases());
    }
}
