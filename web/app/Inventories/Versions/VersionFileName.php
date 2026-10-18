<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

use App\Inventories\Exports\ExportNames;

/**
 * The name an official version is downloaded under: the name of the inventory, then "version" and the number of the version
 * (`Workshop - version 3.pdf`, and `Workshop - version 3 (signed).pdf` for the PDF with the signatures), cleaned up as the name of an export is so that no system refuses it. The whole name, number and
 * extension included, is at most {@see ExportNames::FILE_MAX_BYTES} bytes.
 */
final class VersionFileName
{
    /**
     * The name of a file of a version.
     *
     * @param  string  $inventory  The name of the inventory.
     * @param  int  $number  The number of the version.
     * @param  string  $extension  The extension, without the dot (`pdf`, `json`).
     * @param  bool  $signed  Whether it is the PDF with the signatures.
     */
    public static function for(string $inventory, int $number, string $extension, bool $signed = false): string
    {
        $suffix = ' - '.($signed ? __('version :number (signed)', ['number' => $number]) : __('version :number', ['number' => $number])).'.'.$extension;

        return ExportNames::entry($inventory, __('Inventory'), ExportNames::FILE_MAX_BYTES - strlen($suffix)).$suffix;
    }
}
