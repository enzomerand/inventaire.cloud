<?php

declare(strict_types=1);

namespace App\Installer;

use App\Auth\Accounts;
use App\Installer\Exceptions\InstallationException;
use Illuminate\Support\Facades\Validator;

/**
 * Creates the first administrator, from the "administrator" step of the
 * installation, once the tables exist.
 *
 * Idempotent, as every {@see PostMigrationHook} must be: an installation that
 * is run again after a failure finds the account made by the previous run and
 * leaves it as it is. The account has no password: its owner signs in with a
 * code sent to the address.
 *
 * It does not name its database connection: the finalizer runs hooks with the
 * database being installed as the default one.
 */
final class CreateAdministrator implements PostMigrationHook
{
    public function __construct(
        private readonly InstallerSettings $settings,
        private readonly Accounts $accounts,
    ) {}

    /**
     * Creates the administrator, or promotes the account that already has the address.
     *
     * @throws InstallationException When the step's values are missing or invalid (a configuration edited by hand).
     */
    public function run(): void
    {
        $values = $this->settings->current(InstallStep::Administrator);

        $validator = Validator::make($values, InstallStep::Administrator->rules(), [], InstallStep::Administrator->attributes());

        if ($validator->fails()) {
            throw new InstallationException('The administrator cannot be created: :reason', ['reason' => $validator->errors()->first()]);
        }

        $this->accounts->administrator(trim((string) $values['email']), trim((string) $values['name']));
    }
}
