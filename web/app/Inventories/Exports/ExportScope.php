<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

/**
 * Which items an export holds: all those of the inventory, or only those the grid shows (its search, filters and sort).
 */
enum ExportScope: string
{
    case All = 'all';
    case View = 'view';
}
