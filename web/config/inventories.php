<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Quotas on inventories
    |--------------------------------------------------------------------------
    |
    | Anybody who can edit a workspace can add rows to it, so what a workspace
    | can hold is capped: a script in a loop must not fill the database. The
    | API answers 409 beyond a quota, with a machine-readable `code`:
    |
    | - `max_per_workspace`: inventories in one workspace (`inventory_limit_reached`);
    | - `max_fields`: fields of one inventory (`field_limit_reached`);
    | - `max_items`: items of one inventory (`item_limit_reached`).
    |
    | Each is counted under a row lock (the workspace's, the inventory's), so
    | requests made at the same moment cannot cross it together.
    */
    'max_per_workspace' => (int) env('INVENTORIES_MAX_PER_WORKSPACE', 100),

    'max_fields' => (int) env('INVENTORIES_MAX_FIELDS', 100),

    'max_items' => (int) env('INVENTORIES_MAX_ITEMS', 10000),

];
