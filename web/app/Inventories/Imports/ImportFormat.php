<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

/**
 * The kinds of file an import can read: a spreadsheet of the two families (Office Open XML, OpenDocument) or a text file of
 * delimited values (CSV, and the TSV or TXT that spreadsheets save as).
 *
 * The kind is told by the extension of the name the file was sent with, then checked against what the file contains
 * ({@see ImportReader}).
 */
enum ImportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Ods = 'ods';

    /**
     * The extensions a file may be sent with, by format.
     *
     * @var array<string, list<string>>
     */
    public const EXTENSIONS = [
        'csv' => ['csv', 'tsv', 'txt'],
        'xlsx' => ['xlsx'],
        'ods' => ['ods'],
    ];

    /**
     * The format a file name stands for, or null when its extension is not one the import reads.
     */
    public static function fromFileName(string $name): ?self
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        foreach (self::EXTENSIONS as $format => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return self::from($format);
            }
        }

        return null;
    }

    /**
     * Every extension an import reads, for the validation of the upload.
     *
     * @return list<string>
     */
    public static function allExtensions(): array
    {
        return array_merge(...array_values(self::EXTENSIONS));
    }

    /**
     * The extension the source is stored with: the one of the format, whatever the person called the file.
     */
    public function storedExtension(): string
    {
        return $this->value;
    }

    /**
     * Whether the file is a zip archive (a spreadsheet) rather than text.
     */
    public function isArchive(): bool
    {
        return $this !== self::Csv;
    }
}
