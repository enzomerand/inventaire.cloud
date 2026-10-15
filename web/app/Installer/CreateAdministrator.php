<?php

declare(strict_types=1);

namespace App\Installer;

use App\Auth\Accounts;
use App\Auth\EmailAddress;
use App\Installer\Exceptions\InstallationException;
use App\Rules\PersonName;
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

        // Whatever form the address was stored in (a configuration edited by hand), it is judged and used in canonical form.
        $values['email'] = EmailAddress::normalize((string) $values['email']);

        // The name too, as the forms do: trimmed first, so that one made of spaces is missing, and then judged by the
        // same rules as the name of any account.
        $values['name'] = PersonName::trim((string) $values['name']);

        $validator = Validator::make($values, InstallStep::Administrator->rules(), [], InstallStep::Administrator->attributes());

        if ($validator->fails()) {
            throw new InstallationException('The administrator cannot be created: :reason', ['reason' => $validator->errors()->first()]);
        }

        $this->accounts->administrator($values['email'], $values['name']);
    }
}
