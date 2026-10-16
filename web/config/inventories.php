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

    /*
    |--------------------------------------------------------------------------
    | History of the items
    |--------------------------------------------------------------------------
    |
    | Every change to an item writes a revision (who, when, which values before
    | and after). An item keeps this many; the oldest ones are deleted when a
    | newer one is written.
    |
    */
    'max_revisions_per_item' => (int) env('INVENTORIES_MAX_REVISIONS_PER_ITEM', 100),

    /*
    |--------------------------------------------------------------------------
    | Files attached to items
    |--------------------------------------------------------------------------
    |
    | - `attachments_disk`: the filesystem disk the files are written to, by
    |   name (`ATTACHMENTS_DISK`); the default disk of `filesystems.php` when
    |   empty. It must be private: the `public` disk is refused (the local
    |   private disk is used instead, and the refusal is logged).
    | - `max_attachment_size`: bytes one file may weigh
    |   (`INVENTORIES_MAX_ATTACHMENT_SIZE_MB`, megabytes, 20).
    | - `max_attachments_per_item`: files one item may hold, those of its file
    |   and photo fields included (`INVENTORIES_MAX_ATTACHMENTS_PER_ITEM`, 20).
    | - `storage_per_workspace`: bytes the files of one workspace may weigh in
    |   all (`INVENTORIES_STORAGE_PER_WORKSPACE_MB`, megabytes, 1024), counted
    |   under the lock of the workspace's row. Thumbnails are not counted.
    |
    | Beyond a limit the API answers 409 (`attachment_limit_reached`,
    | `field_file_limit_reached`, `storage_limit_reached`) or 422 (size).
    |
    */
    'attachments_disk' => env('ATTACHMENTS_DISK') ?: null,

    'max_attachment_size' => (int) round((float) env('INVENTORIES_MAX_ATTACHMENT_SIZE_MB', 20) * 1048576),

    'max_attachments_per_item' => (int) env('INVENTORIES_MAX_ATTACHMENTS_PER_ITEM', 20),

    'storage_per_workspace' => (int) round((float) env('INVENTORIES_STORAGE_PER_WORKSPACE_MB', 1024) * 1048576),

];
