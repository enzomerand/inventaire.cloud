<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

use App\Models\InventoryField;

/**
 * The file or photo field an upload is for, with what its type says about it.
 */
final readonly class TargetField
{
    /**
     * @param  InventoryField  $field  The field.
     * @param  int  $maxFiles  How many files it takes (its `max_files` option).
     * @param  bool  $imagesOnly  Whether it takes images only (a photo field).
     */
    public function __construct(
        public InventoryField $field,
        public int $maxFiles,
        public bool $imagesOnly,
    ) {}
}
