<?php

declare(strict_types=1);

namespace App\Installer;

use App\Inventories\SearchText;
use App\Settings\InstanceSettings;
use Illuminate\Database\DatabaseManager;

/**
 * Records the version of the form of the search text ({@see SearchText::VERSION}) on a fresh installation, so that the first
 * `inventaire:upgrade` does not build again, for nothing, the texts of an instance that has none to build.
 *
 * Idempotent, as every {@see PostMigrationHook} must be: it writes the same value each time. It does not name its database
 * connection: the finalizer runs hooks with the database being installed as the default one.
 *
 * It records nothing when the tables already hold items (an installation run again over the database of an earlier one):
 * the texts of those items were built by nobody this hook knows of, so the upgrade is left to build them.
 */
final class RecordSearchTextVersion implements PostMigrationHook
{
    public function __construct(
        private readonly InstanceSettings $settings,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Records the version, unless there are items whose texts it cannot vouch for.
     */
    public function run(): void
    {
        if ($this->db->table('items')->exists()) {
            return;
        }

        $this->settings->recordSearchTextVersion(SearchText::VERSION);
    }
}
