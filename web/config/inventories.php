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
    | - `uploads_per_minute`: files one person may upload in a minute, in all
    |   their workspaces (`INVENTORIES_UPLOADS_PER_MINUTE`, 30): every upload
    |   writes a file, makes a thumbnail and takes locks, so a script in a loop
    |   must not be able to keep the server busy with them.
    |
    | Beyond a limit the API answers 409 (`attachment_limit_reached`,
    | `field_file_limit_reached`, `storage_limit_reached`), 422 (size) or 429
    | (the pace of uploads).
    |
    */
    'attachments_disk' => env('ATTACHMENTS_DISK') ?: null,

    'max_attachment_size' => (int) round((float) env('INVENTORIES_MAX_ATTACHMENT_SIZE_MB', 20) * 1048576),

    'max_attachments_per_item' => (int) env('INVENTORIES_MAX_ATTACHMENTS_PER_ITEM', 20),

    'storage_per_workspace' => (int) round((float) env('INVENTORIES_STORAGE_PER_WORKSPACE_MB', 1024) * 1048576),

    'uploads_per_minute' => (int) env('INVENTORIES_UPLOADS_PER_MINUTE', 30),

    /*
    |--------------------------------------------------------------------------
    | Exports
    |--------------------------------------------------------------------------
    |
    | A member of a workspace can take the items of an inventory out as a
    | spreadsheet, a CSV, a JSON, a PDF or a zip of the photos. A queued job
    | writes the file, which is stored with the other private files and can be
    | downloaded by the person who asked for it only.
    |
    | - `exports_per_minute`: exports one person may ask for in a minute
    |   (`INVENTORIES_EXPORTS_PER_MINUTE`, 10), `429` beyond.
    | - `max_active_exports`: exports one person may have at once, not expired
    |   ones, whatever their state (`INVENTORIES_MAX_ACTIVE_EXPORTS`, 10): the
    |   API answers 409 (`export_limit_reached`) beyond, until one is deleted or
    |   expires. Counted under the lock of the person's row.
    | - `export_ttl_hours`: hours a finished export is kept, then purged by
    |   `inventaire:prune-exports` (`INVENTORIES_EXPORT_TTL_HOURS`, 24).
    | - `pdf_max_items`: items a PDF may hold (`INVENTORIES_PDF_MAX_ITEMS`,
    |   1000): the library lays the whole document out in memory. The API
    |   answers 422 beyond; the other formats are streamed and have no limit but
    |   the quota of items of an inventory.
    | - `export_stale_minutes`: minutes after which an export that is still
    |   waiting or running is given up as failed by `inventaire:prune-exports`
    |   (`INVENTORIES_EXPORT_STALE_MINUTES`, 60): its worker stopped.
    |
    */
    'exports_per_minute' => (int) env('INVENTORIES_EXPORTS_PER_MINUTE', 10),

    'max_active_exports' => (int) env('INVENTORIES_MAX_ACTIVE_EXPORTS', 10),

    'export_ttl_hours' => (int) env('INVENTORIES_EXPORT_TTL_HOURS', 24),

    'pdf_max_items' => (int) env('INVENTORIES_PDF_MAX_ITEMS', 1000),

    'export_stale_minutes' => (int) env('INVENTORIES_EXPORT_STALE_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Inventory templates
    |--------------------------------------------------------------------------
    |
    | - `templates_path`: the folder of the template files, one `<slug>.json` each
    |   (`inventory-templates/` of the application by default). They are the
    |   catalogue: the public gallery, the API and the "new inventory" dialog read
    |   them, and a file that is not valid is ignored and logged.
    | - `templates_per_minute`: requests per minute the public endpoints of the
    |   catalogue (the API) answer, per user when signed in, else per network
    |   (`INVENTORIES_TEMPLATES_PER_MINUTE`, 60).
    | - `public_pages_per_minute`: requests per minute the public pages of the
    |   gallery (every language) and `/sitemap.xml` answer, counted the same way
    |   but apart from the API (`INVENTORIES_PUBLIC_PAGES_PER_MINUTE`, 300): a
    |   visitor loads many pages, and a crawler reads them one after the other.
    |
    */
    'templates_path' => base_path('inventory-templates'),

    'templates_per_minute' => (int) env('INVENTORIES_TEMPLATES_PER_MINUTE', 60),

    'public_pages_per_minute' => (int) env('INVENTORIES_PUBLIC_PAGES_PER_MINUTE', 300),

];
