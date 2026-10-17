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
    | - `max_active_exports`: exports one person may have at once **for one
    |   inventory**, not expired ones, whatever their state
    |   (`INVENTORIES_MAX_ACTIVE_EXPORTS`, 10): the API answers 409
    |   (`export_limit_reached`) beyond, until one is deleted or expires.
    |   Counted under the lock of the person's row.
    | - `export_storage_per_user`: bytes the exports of one person may weigh in
    |   all, across their inventories (`INVENTORIES_EXPORT_STORAGE_PER_USER`,
    |   megabytes, 2048): the sum of the sizes of their exports that did not
    |   expire, plus the one asked for. A zip counts for the sum of the files it
    |   will hold from the moment it is asked for, the other formats once
    |   written; a failed export weighs nothing. The API answers 409
    |   (`export_storage_full`) beyond, until some are deleted or expire. Counted
    |   under the lock of the person's row.
    | - `export_ttl_hours`: hours a finished export is kept, then purged by
    |   `inventaire:prune-exports` (`INVENTORIES_EXPORT_TTL_HOURS`, 24).
    | - `pdf_max_items`: items a PDF may hold (`INVENTORIES_PDF_MAX_ITEMS`,
    |   500). The library lays the whole document out in memory, and its
    |   memory goes with the text it lays out: 500 items of 8 short values take
    |   71 MB and 1.5 seconds, and 500 items of 8 texts cut at the limit of a
    |   cell (120 characters) take 148 MB and 5 seconds, over the 24 MB of a
    |   worker, with a 256 MB `memory_limit`. The limit is for an inventory of up
    |   to 8 fields, and lower in proportion beyond (12 fields: 333 items). The
    |   API answers 422 beyond; the job fails with `pdf_too_large` for an
    |   inventory that has grown since, or whose HTML is over 1 MB (a table) or
    |   4 MiB (cards): raise the limit with that in mind. The other formats are
    |   streamed and have no limit but the quota of items of an inventory.
    | - `export_stale_minutes`: minutes after which an export that is still
    |   waiting (`pending`) is marked failed by `inventaire:prune-exports`
    |   (`INVENTORIES_EXPORT_STALE_MINUTES`, 60), counted from the day it was
    |   asked for: the queue is assumed not to be running. Its job may still be
    |   in the queue, and exits without writing anything when it is picked up,
    |   since the export is no longer pending. One that is running is judged on
    |   the time since it started instead: it is given up on after the time a
    |   job may run (15 minutes) plus 5, whenever it was asked for.
    |
    | The jobs that write the files go on their own queue, `exports` (the imports
    |   use it too: both are heavy): the Docker
    |   example runs a worker for it (`export-worker`, `QUEUE_WORKER_QUEUES=exports`)
    |   next to the one of the short jobs (`default`). A single worker must listen
    |   to both, `--queue=default,exports`, which the image's worker does without
    |   `QUEUE_WORKER_QUEUES` and `composer dev` does.
    |
    */
    'exports_per_minute' => (int) env('INVENTORIES_EXPORTS_PER_MINUTE', 10),

    'max_active_exports' => (int) env('INVENTORIES_MAX_ACTIVE_EXPORTS', 10),

    'export_storage_per_user' => (int) round((float) env('INVENTORIES_EXPORT_STORAGE_PER_USER', 2048) * 1048576),

    'export_ttl_hours' => (int) env('INVENTORIES_EXPORT_TTL_HOURS', 24),

    'pdf_max_items' => (int) env('INVENTORIES_PDF_MAX_ITEMS', 500),

    'export_stale_minutes' => (int) env('INVENTORIES_EXPORT_STALE_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Imports
    |--------------------------------------------------------------------------
    |
    | An editor of a workspace can bring the rows of a spreadsheet (XLSX, ODS) or
    | of a CSV, TSV or TXT file into an inventory, creating the fields it needs
    | from the columns. The file is read once when it is sent, to propose where
    | each column goes, then a queued job (on the same `exports` queue as the
    | exports: the heavy jobs) converts, checks and writes the rows in one
    | transaction. The file is stored with the other private files, under the
    | folder of its inventory, and deleted as soon as the job ends.
    |
    | - `imports_per_minute`: files one person may send, or analyse again, in a
    |   minute (`INVENTORIES_IMPORTS_PER_MINUTE`, 10), `429` beyond.
    | - `max_active_imports`: imports one person may have at once **for one
    |   inventory**, not yet finished (sent, waiting or running)
    |   (`INVENTORIES_MAX_ACTIVE_IMPORTS`, 3): the API answers 409
    |   (`import_limit_reached`) beyond, until one is started and ends, or
    |   deleted. Counted under the lock of the person's row.
    | - `import_max_size`: bytes the file may weigh
    |   (`INVENTORIES_IMPORT_MAX_SIZE_MB`, megabytes, 10), `422` beyond.
    | - `import_max_rows`: rows with something in them the file may hold, the
    |   header not counted (`INVENTORIES_IMPORT_MAX_ROWS`, 10000): the import
    |   fails (`too_many_rows`) beyond. The quota of items of the inventory
    |   (`max_items`) applies too.
    | - `import_max_columns`: columns the file may use
    |   (`INVENTORIES_IMPORT_MAX_COLUMNS`, 200): `422` beyond. It counts the
    |   columns that hold something; a row that would be built with more than
    |   this or 1024 cells (512 for an Excel sheet), whichever is larger, is
    |   refused before it is built, whatever it holds (`too_many_columns`: the
    |   width of the rows that an application pads, and the time a row of an
    |   Excel sheet takes to build grows with the square of its width). A sheet that would be built with more than twice
    |   `import_max_rows` times this many cells, the empty ones of its
    |   formatting and the repeated rows counted (1100000 at least), is refused
    |   too (`too_many_cells`). The analysis of a file looks at its first 1000
    |   lines.
    | - `import_max_unpacked_size`: bytes all the parts of a spreadsheet may
    |   weigh once unpacked (`INVENTORIES_IMPORT_MAX_UNPACKED_SIZE_MB`,
    |   megabytes, 100): a spreadsheet is a zip archive, and a small one can
    |   unpack to gigabytes. The sizes announced by the archive are summed
    |   first, then the parts are unpacked into nothing and the bytes that
    |   really come out are counted (the announced sizes can be forged); the
    |   file is refused (`422`) beyond, before anything is read.
    | - `import_ttl_hours`: hours a file that was sent but not started is kept,
    |   and a finished import with its report is kept
    |   (`INVENTORIES_IMPORT_TTL_HOURS`, 24), then purged by
    |   `inventaire:prune-imports`.
    | - `import_stale_minutes`: minutes after which an import that is still
    |   waiting (`pending`) is marked failed by `inventaire:prune-imports`
    |   (`INVENTORIES_IMPORT_STALE_MINUTES`, 60), counted from the day it was
    |   started: the queue is assumed not to be running. One that is running is
    |   judged on the time since it began instead: it is given up on after the
    |   time a job may run (15 minutes) plus 5.
    |
    */
    'imports_per_minute' => (int) env('INVENTORIES_IMPORTS_PER_MINUTE', 10),

    'max_active_imports' => (int) env('INVENTORIES_MAX_ACTIVE_IMPORTS', 3),

    'import_max_size' => (int) round((float) env('INVENTORIES_IMPORT_MAX_SIZE_MB', 10) * 1048576),

    'import_max_rows' => (int) env('INVENTORIES_IMPORT_MAX_ROWS', 10000),

    'import_max_columns' => (int) env('INVENTORIES_IMPORT_MAX_COLUMNS', 200),

    'import_max_unpacked_size' => (int) round((float) env('INVENTORIES_IMPORT_MAX_UNPACKED_SIZE_MB', 100) * 1048576),

    'import_ttl_hours' => (int) env('INVENTORIES_IMPORT_TTL_HOURS', 24),

    'import_stale_minutes' => (int) env('INVENTORIES_IMPORT_STALE_MINUTES', 60),

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

    /*
    |--------------------------------------------------------------------------
    | API tokens
    |--------------------------------------------------------------------------
    |
    | A person makes tokens on their account page, for the scripts and the
    | applications that call the API (see App\Auth\ApiTokens).
    |
    | - `api_token_requests_per_minute`: requests per minute one token may make
    |   (`API_TOKEN_REQUESTS_PER_MINUTE`, 120), whatever it asks for, `429`
    |   beyond. Counted for each token apart, on top of the limits of the
    |   endpoints; the web front, which has no token, is not counted.
    | - `max_api_tokens`: tokens one person may have at once, not counting the
    |   ones that expired (`MAX_API_TOKENS`, 20): the API answers 409
    |   (`token_limit_reached`) beyond, until one is revoked or expires.
    |   Counted under the lock of the person's row.
    | - `api_token_creations_per_hour`: tokens one person may make in an hour,
    |   the ones they revoke straight away included (10), `429` beyond.
    |
    */
    'api_token_requests_per_minute' => (int) env('API_TOKEN_REQUESTS_PER_MINUTE', 120),

    'max_api_tokens' => (int) env('MAX_API_TOKENS', 20),

    'api_token_creations_per_hour' => 10,

];
