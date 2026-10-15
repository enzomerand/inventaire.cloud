<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\Accounts;
use App\Auth\AdministratorChange;
use App\Auth\EmailAddress;
use App\Installer\InstallationState;
use App\Models\User;
use App\Rules\PersonName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\text;

/**
 * Makes an email address the account of an administrator: creates the account
 * or promotes the existing one.
 *
 * It serves instances that were installed before administrators existed, and
 * recovery when nobody can sign in as one. It is safe to run again.
 */
final class MakeAdminCommand extends Command
{
    protected $signature = 'inventaire:make-admin
        {email : Email address of the administrator, who signs in with a code sent to it}
        {--name= : Name of the account, needed when it has to be created}';

    protected $description = 'Create an administrator account, or promote an existing account to administrator';

    /**
     * Creates or promotes the account.
     */
    public function handle(InstallationState $state, Accounts $accounts): int
    {
        if (! $state->isInstalled()) {
            $this->components->error(__('The instance is not installed yet: install it first.'));

            return self::FAILURE;
        }

        $argument = $this->argument('email');
        $email = EmailAddress::normalize(is_string($argument) ? $argument : '');
        $validator = Validator::make(['email' => $email], ['email' => EmailAddress::rules()], [], ['email' => __('Email address')]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::INVALID;
        }

        $name = $this->nameFor($email);

        if ($name === null) {
            $this->components->error(__('There is no account for :email yet: give its name with --name.', ['email' => $email]));

            return self::INVALID;
        }

        // The name of an existing account is not used (and `nameFor()` answers with an empty one).
        if ($name !== '') {
            $validator = Validator::make(['name' => $name], ['name' => PersonName::rules()], [], ['name' => mb_strtolower(__('Name'))]);

            if ($validator->fails()) {
                $this->components->error($validator->errors()->first());

                return self::INVALID;
            }
        }

        [$user, $change] = $accounts->administrator($email, $name);

        $this->components->info(match ($change) {
            AdministratorChange::Created => __('The administrator account of :email was created.', ['email' => $user->email]),
            AdministratorChange::Promoted => __(':email is now an administrator.', ['email' => $user->email]),
            AdministratorChange::Unchanged => __(':email is already an administrator.', ['email' => $user->email]),
        });

        if ($change === AdministratorChange::Created) {
            $this->line(__('There is no password: sign in with the code sent to this address.'));
        }

        return self::SUCCESS;
    }

    /**
     * The name for an account that has to be created, trimmed like the account forms do ({@see PersonName::trim()}), or
     * an empty placeholder when the account exists (its own name is kept). Null when a name is needed and cannot be had.
     * It is judged by {@see PersonName} afterwards.
     */
    private function nameFor(string $email): ?string
    {
        if (User::query()->where('email', $email)->exists()) {
            return '';
        }

        $option = $this->option('name');
        $name = is_string($option) ? PersonName::trim($option) : '';

        if ($name === '' && $this->input->isInteractive()) {
            $name = PersonName::trim(text(label: __('Name'), required: true));
        }

        return $name === '' ? null : $name;
    }
}
