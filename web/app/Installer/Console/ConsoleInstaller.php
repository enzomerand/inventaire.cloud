<?php

declare(strict_types=1);

namespace App\Installer\Console;

use App\Installer\ApplicationKey;
use App\Installer\Exceptions\InstallationException;
use App\Installer\Field;
use App\Installer\InstallationFinalizer;
use App\Installer\InstallationState;
use App\Installer\InstallerSettings;
use App\Installer\InstallerSteps;
use App\Installer\InstallStep;
use App\Installer\Mail\MailChallenge;
use App\Installer\Mail\MailCodeOutcome;
use App\Installer\Mail\MailVerification;
use App\Installer\Probes\ProbeResult;
use App\Installer\RequirementsChecker;
use App\Installer\Support\AtomicFile;
use Illuminate\Console\Command;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * The console version of the install wizard.
 *
 * It walks through the same steps as the web wizard and shares its services
 * ({@see InstallerSteps}, the probes, the finalizer), so a step means the same
 * thing in both. Three ways to run it:
 *
 * - interactive: prompts for every value, pre-filled with what is configured;
 * - non-interactive: values come from command options, completed by what is
 *   already configured (.env and environment), and the first problem stops it;
 * - from-env: nothing is asked or written, the current configuration is
 *   validated with the probes and the installation finalized (Docker, and
 *   `composer setup` for a developer's local instance).
 *
 * The setup token is for the web wizard only: running this command already
 * proves access to the server.
 */
final class ConsoleInstaller
{
    /** Stand-in for an empty option key, which a select prompt cannot carry. */
    private const EMPTY_CHOICE = '__none__';

    /**
     * Command option that sets each field, per step.
     *
     * @var array<string, array<string, string>>
     */
    public const OPTIONS = [
        'database' => ['driver' => 'db-driver', 'host' => 'db-host', 'port' => 'db-port', 'database' => 'db-name', 'username' => 'db-user', 'password' => 'db-password', 'password_clear' => 'db-no-password'],
        'application' => ['url' => 'app-url', 'locale' => 'locale', 'timezone' => 'timezone'],
        'mail' => ['host' => 'mail-host', 'port' => 'mail-port', 'scheme' => 'mail-scheme', 'username' => 'mail-user', 'password' => 'mail-password', 'password_clear' => 'mail-no-password', 'from_address' => 'mail-from-address', 'from_name' => 'mail-from-name', 'test_recipient' => 'mail-test-recipient'],
        'storage' => ['disk' => 'storage', 'key' => 's3-key', 'secret' => 's3-secret', 'region' => 's3-region', 'bucket' => 's3-bucket', 'endpoint' => 's3-endpoint', 'path_style' => 's3-path-style'],
        'documenso' => ['url' => 'documenso-url', 'api_key' => 'documenso-api-key'],
    ];

    private readonly bool $fromEnv;

    private readonly Factory $components;

    private InstallerSteps $steps;

    /**
     * @param  Command  $command  The running command, for its options, output and plain lines.
     * @param  bool  $interactive  Whether a person can answer prompts (false with --no-interaction and --from-env).
     */
    public function __construct(
        private readonly Command $command,
        private readonly bool $interactive,
        private readonly InstallationState $state,
        private readonly InstallerSettings $settings,
        InstallerSteps $steps,
        private readonly MailVerification $mailVerification,
        private readonly ApplicationKey $key,
        private readonly RequirementsChecker $requirements,
        private readonly InstallationFinalizer $finalizer,
    ) {
        $this->fromEnv = (bool) $command->option('from-env');
        $this->components = new Factory($command->getOutput());
        $this->steps = $this->fromEnv ? $steps->validatingOnly() : $steps;
    }

    /**
     * Runs the installation.
     *
     * @return int The command exit code: 0 on success, 1 when the installation failed, 2 for a misuse of the options.
     */
    public function run(): int
    {
        if ($this->state->isInstalled()) {
            $this->components->info(__('The instance is already installed: nothing to do.'));

            return Command::SUCCESS;
        }

        if ($this->command->option('skip-mail-test') && $this->interactive) {
            $this->components->error(__('--skip-mail-test is only allowed in non-interactive mode.'));

            return Command::INVALID;
        }

        $this->warnAboutUser();

        try {
            $this->key->ensure();

            if (! $this->checkRequirements()) {
                return Command::FAILURE;
            }

            // The same registry as the web wizard: every step, in order.
            foreach (InstallStep::cases() as $step) {
                if (! ($step->isOptional() ? $this->configureOptional($step) : $this->configure($step))) {
                    return Command::FAILURE;
                }
            }

            return $this->finalize();
        } catch (InstallationException $exception) {
            $this->components->error($exception->userMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Warns when the command runs as another user than the one that owns storage/.
     *
     * The web server normally owns storage/. Files this command creates as
     * somebody else would be unreadable for it and the site would loop on the
     * wizard; as root, files are handed over to the owner of their folder.
     */
    private function warnAboutUser(): void
    {
        $owner = @fileowner(storage_path());
        $current = AtomicFile::effectiveUser();

        if ($owner === false || $owner === $current) {
            return;
        }

        $name = static fn (int $uid): string => function_exists('posix_getpwuid') ? (string) (posix_getpwuid($uid)['name'] ?? $uid) : (string) $uid;

        $this->components->warn(__('This command runs as :user but storage/ belongs to :owner. If the web server runs as :owner, run this command as :owner (for example with sudo -u :owner), or as root so that the files it creates are handed over to the owner of their folder.', [
            'user' => $name($current),
            'owner' => $name($owner),
        ]));
    }

    /**
     * Prints the requirements and reports whether the installation can go on.
     *
     * With --from-env nothing is written to .env, so an unwritable file is not a blocker there.
     */
    private function checkRequirements(): bool
    {
        $items = $this->requirements->check();
        $blocking = false;

        foreach ($items as $item) {
            $tolerated = $this->fromEnv && $item->id === 'env';

            if ($item->ok) {
                $this->components->twoColumnDetail($item->label, '<fg=green>'.__('Met').'</>');
            } else {
                $this->components->twoColumnDetail($item->label, '<fg=red>'.__('Not met').'</>');
                $this->command->line('    '.($item->detail ?? ''));
                $blocking = $blocking || ! $tolerated;
            }
        }

        if ($blocking) {
            $this->components->error(__('This server does not meet the requirements above.'));
        }

        return ! $blocking;
    }

    /**
     * Collects, validates, tests and saves one required step.
     */
    private function configure(InstallStep $step): bool
    {
        $this->command->newLine();
        $this->components->info($step->title());

        $previous = [];

        while (true) {
            $input = $this->gather($step, $previous);
            $values = $this->settings->resolve($step, $input);
            $previous = $values;

            if (! $this->validates($step, $values)) {
                if (! $this->interactive) {
                    return false;
                }

                continue;
            }

            $result = $this->save($step, $values);

            foreach ($result->warnings as $warning) {
                $this->components->warn($warning);
            }

            if ($result->ok) {
                $this->components->info(__(':step: done.', ['step' => $step->title()]));

                return true;
            }

            $this->components->error((string) $result->message);

            if (! $this->interactive || ! confirm(label: __('Try again?'), default: true)) {
                return false;
            }
        }
    }

    /**
     * Optional step: configured when asked for, or when every value it needs is
     * already present (options or configuration), skipped otherwise.
     */
    private function configureOptional(InstallStep $step): bool
    {
        $provided = $this->providedOptions($step);
        $current = $this->settings->current($step);
        $available = true;

        foreach ($step->fields() as $field) {
            if ($field->required && ($provided[$field->name] ?? $current[$field->name]) === '') {
                $available = false;
            }
        }

        if ($this->interactive) {
            $this->command->newLine();
            $this->components->info($step->title());

            if (! confirm(label: $step->configureQuestion(), default: $available)) {
                $this->steps->skip($step);

                return true;
            }

            return $this->configure($step);
        }

        if (! $available) {
            $this->steps->skip($step);
            $this->components->twoColumnDetail($step->title(), '<fg=yellow>'.__('Skipped').'</>');

            return true;
        }

        return $this->configure($step);
    }

    /**
     * Tests and saves a step.
     *
     * @param  array<string, mixed>  $values
     */
    private function save(InstallStep $step, array $values): ProbeResult
    {
        if ($step->sendsEmail() && $this->interactive) {
            return $this->saveMailWithCode($values);
        }

        if ($step->sendsEmail() && $this->command->option('skip-mail-test')) {
            $this->components->warn(__('The mail settings were not tested (--skip-mail-test): check that emails can be sent.'));
            $this->steps->saveMailUntested($values);

            return ProbeResult::success();
        }

        return $this->steps->save($step, $values);
    }

    /**
     * Mail is proven by a code read from a real inbox: a person is here to read it.
     * Non-interactive runs check the SMTP server instead (see {@see InstallerSteps::save()}).
     *
     * @param  array<string, mixed>  $values
     */
    private function saveMailWithCode(array $values): ProbeResult
    {
        [$result, $challenge] = $this->steps->beginMail($values);

        if (! $result->ok || $challenge === null) {
            return $result;
        }

        return $this->confirmMailCode($challenge) ? ProbeResult::success() : ProbeResult::failure(__('The verification code was not confirmed.'));
    }

    /**
     * Asks for the code that was emailed until it is right or no longer usable.
     */
    private function confirmMailCode(MailChallenge $challenge): bool
    {
        $this->components->info(__('A 6-digit code was sent to :email.', ['email' => $challenge->recipient]));

        while (true) {
            $code = text(label: __('Verification code'), hint: __('Leave empty to go back and change the settings.'), required: false);

            if ($code === '') {
                return false;
            }

            [$outcome, $challenge] = $this->mailVerification->confirm($challenge, $code);

            switch ($outcome) {
                case MailCodeOutcome::Accepted:
                    $this->steps->completeMail($challenge);

                    return true;

                case MailCodeOutcome::Wrong:
                    $this->components->error(trans_choice(
                        'Incorrect code. :count attempt left.|Incorrect code. :count attempts left.',
                        $this->mailVerification->attemptsLeft($challenge),
                    ));

                    break;

                case MailCodeOutcome::Expired:
                    $this->components->error(__('This code has expired: request a new one.'));

                    return false;

                case MailCodeOutcome::TooManyAttempts:
                    $this->components->error(__('Too many incorrect codes: request a new one.'));

                    return false;
            }
        }
    }

    /**
     * Validates the values of a step against the same rules as the web form.
     *
     * @param  array<string, mixed>  $values
     */
    private function validates(InstallStep $step, array $values): bool
    {
        $rules = $this->settings->rules($step);

        // The test address only exists to receive a code, which non-interactive runs cannot read.
        if ($step === InstallStep::Mail && ! $this->interactive) {
            unset($rules['test_recipient']);
        }

        $validator = Validator::make($this->nullEmptyStrings($values), $rules, [], $step->attributes());

        if ($validator->passes()) {
            return true;
        }

        foreach ($validator->errors()->all() as $message) {
            $this->components->error($message);
        }

        return false;
    }

    /**
     * What the web layer's middleware does to a submitted form: empty text is null.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function nullEmptyStrings(array $values): array
    {
        return array_map(static fn (mixed $value): mixed => $value === '' ? null : $value, $values);
    }

    /**
     * The submitted values of a step: prompts when interactive, command
     * options otherwise. What is missing is completed by
     * {@see InstallerSettings::resolve()}.
     *
     * @param  array<string, mixed>  $previous  Answers of an earlier attempt, offered again as defaults.
     * @return array<string, mixed>
     */
    private function gather(InstallStep $step, array $previous): array
    {
        $provided = $this->providedOptions($step);

        if (! $this->interactive) {
            return $provided;
        }

        $defaults = $provided + $previous + $this->settings->current($step);
        $values = [];

        foreach ($step->fields() as $field) {
            if ($field->showWhen !== null && ($values[$field->showWhen['field']] ?? $defaults[$field->showWhen['field']] ?? null) !== $field->showWhen['value']) {
                continue;
            }

            // "No password" only matters when a password is already stored.
            if ($field->clears !== null && ! $this->hasStoredSecret($step, $field->clears)) {
                continue;
            }

            if ($this->settings->isLocked($field)) {
                $this->components->twoColumnDetail($field->label, $field->secret || $this->settings->isProvidedByUrl($field) ? '••••••••' : (string) $defaults[$field->name]);
                $this->command->line('    <fg=gray>'.__('Set by the environment.').'</>');

                continue;
            }

            $values[$field->name] = $this->ask($field, $defaults[$field->name] ?? null);
        }

        return $values;
    }

    /**
     * Whether the secret field of a step already has a value.
     */
    private function hasStoredSecret(InstallStep $step, string $name): bool
    {
        foreach ($step->fields() as $field) {
            if ($field->name === $name) {
                return $this->settings->hasValue($field);
            }
        }

        return false;
    }

    /**
     * Prompts for one field.
     */
    private function ask(Field $field, mixed $default): mixed
    {
        $hint = $field->help ?? '';

        return match ($field->type) {
            'select' => $this->askChoice($field, (string) $default),
            'checkbox' => confirm(label: $field->label, default: (bool) $default, hint: $hint),
            'password' => password(
                label: $field->label,
                required: false,
                hint: $this->settings->hasValue($field) ? __('Leave empty to keep the current value.') : $hint,
            ),
            default => text(label: $field->label, default: (string) $default, required: $field->required, hint: $hint),
        };
    }

    private function askChoice(Field $field, string $default): string
    {
        $options = [];

        foreach ($field->options as $option) {
            $options[$option['value'] === '' ? self::EMPTY_CHOICE : $option['value']] = $option['label'];
        }

        $choice = select(label: $field->label, options: $options, default: $default === '' ? self::EMPTY_CHOICE : $default, hint: $field->help ?? '');

        return $choice === self::EMPTY_CHOICE ? '' : (string) $choice;
    }

    /**
     * The command options given for a step, keyed by field name.
     *
     * @return array<string, mixed>
     */
    private function providedOptions(InstallStep $step): array
    {
        $provided = [];

        foreach (self::OPTIONS[$step->value] as $field => $option) {
            $value = $this->command->option($option);

            // A flag is only meaningful when it was passed; an absent option keeps the configured value.
            if ($value !== null && $value !== false) {
                $provided[$field] = $value;
            }
        }

        return $provided;
    }

    /**
     * Whether to switch the instance to production mode (APP_ENV=production, APP_DEBUG=false).
     *
     * `--from-env` is the developer path and never touches them. Interactively
     * the person is asked (defaulting to yes, except on a local instance);
     * otherwise it happens unless `--keep-environment` says not to. Nothing is
     * asked when the instance already runs in production.
     */
    private function wantsProductionDefaults(): bool
    {
        if ($this->fromEnv || ! $this->finalizer->needsProductionDefaults()) {
            return false;
        }

        if ($this->interactive) {
            return confirm(
                label: __('Switch this instance to production mode (APP_ENV=production, APP_DEBUG=false)?'),
                default: ! app()->environment('local'),
                hint: __('Debug mode shows stack traces and settings to anybody who triggers an error: keep it for development only.'),
            );
        }

        return ! $this->command->option('keep-environment');
    }

    /**
     * Creates the tables and switches the instance on.
     */
    private function finalize(): int
    {
        $this->command->newLine();
        $this->finalizer->finalize($this->wantsProductionDefaults());

        $url = $this->settings->current(InstallStep::Application)['url'];

        $this->components->info(__('Installation complete.'));
        $this->command->line(__('Open :url to start using the instance.', ['url' => is_string($url) ? $url : '']));

        return Command::SUCCESS;
    }
}
