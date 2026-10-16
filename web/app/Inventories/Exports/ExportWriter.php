<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

/**
 * Writes the items of an export in one format, to a local file.
 *
 * A writer never touches the disk the export is stored on: it writes the file it is given, and {@see ExportGenerator} moves it to
 * its place. It reads the items through the context, a chunk at a time, so that the memory it needs does not depend on the number of
 * items (the PDF is the exception: its library lays the whole document out in memory, which is why it has a limit).
 */
interface ExportWriter
{
    /**
     * Writes the file.
     *
     * @param  string  $path  A local file the writer may create or overwrite.
     * @return int The number of items written: an item deleted since the list of the export was made is not in the file.
     */
    public function write(ExportContext $context, string $path): int;
}
