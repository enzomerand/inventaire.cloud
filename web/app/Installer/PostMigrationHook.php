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
 * during the installation plugs in here: {@see CreateAdministrator} creates the
 * first administrator from the values collected by the "administrator" step,
 * which need the `users` table, and {@see RecordSearchTextVersion} records the version of the search text, which needs the
 * `settings` table.
 *
 * Register a hook from a service provider by tagging it:
 *
 *     $this->app->tag([CreateAdministrator::class], PostMigrationHook::TAG);
 *
 * A hook must be idempotent. The installation can fail after the migrations
 * (or after another hook) and be run again, so a hook may see the effect of a
 * previous, interrupted run and must cope with it. An exception aborts the
 * installation, which then stays not installed and can be retried.
 *
 * Hooks belong to the installation only: `inventaire:upgrade` never runs them.
 * They run before the "installed" marker, while the values collected by the
 * wizard still exist (they are deleted when the installation completes), so
 * running them later would find those values gone.
 */
interface PostMigrationHook
{
    /** Container tag under which hooks are registered. */
    public const TAG = 'installer.post-migration';

    /**
     * Does the work. Runs after the migrations, with the database being
     * installed as the default connection: the application's own default
     * connection may have been configured before the wizard wrote the database
     * settings, so a hook must use the default connection (`DB`, models) and
     * never one by name.
     *
     * @throws Exceptions\InstallationException To abort the installation with a message people can act on.
     */
    public function run(): void;
}
