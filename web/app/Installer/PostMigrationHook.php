<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * Work that has to happen once the tables exist, but before the instance is
 * marked installed.
 *
 * This is the extension point of the installation. The finalizer runs, in
 * order: the migrations, every registered hook, the production defaults, and
 * only then writes the "installed" marker. A feature that needs the database
 * during the installation plugs in here: the accounts lot will create the first
 * administrator from the values collected by its wizard step, which need the
 * `users` table and are stored in the database rather than in `.env`.
 *
 * Register a hook from a service provider by tagging it:
 *
 *     $this->app->tag([CreateAdministrator::class], PostMigrationHook::TAG);
 *
 * A hook must be idempotent. The installation can fail after the migrations
 * (or after another hook) and be run again, so a hook may see the effect of a
 * previous, interrupted run and must cope with it. An exception aborts the
 * installation, which then stays not installed and can be retried.
 */
interface PostMigrationHook
{
    /** Container tag under which hooks are registered. */
    public const TAG = 'installer.post-migration';

    /**
     * Does the work. Runs after the migrations, with the database configured
     * as the application will use it.
     *
     * @throws Exceptions\InstallationException To abort the installation with a message people can act on.
     */
    public function run(): void;
}
