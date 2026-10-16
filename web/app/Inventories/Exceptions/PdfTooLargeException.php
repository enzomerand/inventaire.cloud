<?php

declare(strict_types=1);

namespace App\Inventories\Exceptions;

use RuntimeException;

/**
 * A PDF that the library could not lay out in the memory of a worker: it holds more items than a PDF may
 * (`inventories.pdf_max_items`, which the inventory can have outgrown since the export was asked for), or its HTML is bigger than
 * the library can take. The export fails with `pdf_too_large`, and the person is told to filter the view or to choose another format.
 */
final class PdfTooLargeException extends RuntimeException {}
