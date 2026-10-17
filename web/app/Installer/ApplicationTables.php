<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * The tables the application's own migrations create.
 *
 * The database step refuses a database that holds anything else: installing
 * into a database shared with another application (or a WordPress, say) would
 * mix the two and make later migrations dangerous. A database that only holds
 * these tables is accepted so an interrupted installation can be resumed.
 *
 * Keep this list in step with the migrations: a test migrates a database and
 * fails when a table is missing here.
 */
final class ApplicationTables
{
    /** @var list<string> */
    public const NAMES = [
        'migrations',
        'users',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'personal_access_tokens',
        'login_codes',
        'workspaces',
        'workspace_user',
        'account_deletion_codes',
        'settings',
        'inventories',
        'inventory_fields',
        'items',
        'item_revisions',
        'attachments',
        'exports',
        'imports',
        'inventory_versions',
    ];

    /**
     * Whether the table belongs to the application.
     */
    public static function owns(string $table): bool
    {
        return in_array($table, self::NAMES, true);
    }
}
