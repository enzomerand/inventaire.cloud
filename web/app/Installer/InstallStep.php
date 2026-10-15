<?php

declare(strict_types=1);

namespace App\Installer;

use App\Auth\EmailAddress;
use App\Installer\Settings\DatabaseSettings;
use App\Rules\PersonName;
use App\Services\LocaleResolver;
use DateTimeZone;
use Illuminate\Validation\Rule;

/**
 * The configuration steps of the install wizard, in the order they are shown.
 *
 * This enum is the step registry. Each case describes its inputs
 * ({@see self::fields()}) and the rules that validate them ({@see self::rules()})
 * once, and everything else iterates over `cases()` instead of listing steps by
 * hand: the web routes, the wizard's state, the console installer, the progress
 * file and {@see InstallerSteps::save()}. Adding a case, with its fields, rules
 * and a branch in `InstallerSteps::save()`, adds a step to both installers.
 *
 * The requirements check and the final "install" action are not steps: they
 * store nothing, so they are not tracked in the progress file. Work that needs
 * the database belongs to a {@see PostMigrationHook}: the administrator step
 * only collects the name and address, {@see CreateAdministrator} creates the
 * account once the tables exist.
 */
enum InstallStep: string
{
    case Database = 'database';
    case Application = 'application';
    case Mail = 'mail';
    case Storage = 'storage';
    case Documenso = 'documenso';
    case Administrator = 'administrator';

    /**
     * Whether the instance can be installed without this step (it may be
     * skipped).
     */
    public function isOptional(): bool
    {
        return $this === self::Documenso;
    }

    /**
     * The question the console installer asks before configuring an optional step.
     */
    public function configureQuestion(): string
    {
        return match ($this) {
            self::Documenso => __('Configure the electronic signature (Documenso) now?'),
            default => __('Configure :step now?', ['step' => $this->title()]),
        };
    }

    /**
     * Whether saving the step sends an email, which makes it worth rate-limiting.
     */
    public function sendsEmail(): bool
    {
        return $this === self::Mail;
    }

    /**
     * Steps that must be completed before the installation can be finalized.
     *
     * @return list<self>
     */
    public static function required(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $step): bool => ! $step->isOptional()));
    }

    /**
     * The variable that can hold the whole connection as a URL (Laravel's
     * `DB_URL` and `MAIL_URL`), or null for steps that have none.
     *
     * When it is set, the URL replaces the host, port, credentials... of the
     * step: they are shown read-only (masked, a URL often carries a password)
     * and the checks run on the URL itself.
     */
    public function urlKey(): ?string
    {
        return match ($this) {
            self::Database => 'DB_URL',
            self::Mail => 'MAIL_URL',
            default => null,
        };
    }

    /**
     * Translated name of the step.
     */
    public function title(): string
    {
        return match ($this) {
            self::Database => __('Database'),
            self::Application => __('Application'),
            self::Mail => __('Email'),
            self::Storage => __('File storage'),
            self::Documenso => __('Electronic signature'),
            self::Administrator => __('Administrator'),
        };
    }

    /**
     * Translated sentence explaining what the step configures.
     */
    public function intro(): string
    {
        return match ($this) {
            self::Database => __('Pick the database engine and enter the connection details. The database must exist and be empty: the tables are created at the end of the installation.'),
            self::Application => __('Public address, default language and timezone of this instance.'),
            self::Mail => __('Sign-in codes and notifications are sent by email through an SMTP server. A 6-digit code is sent to the test address to prove that sending works.'),
            self::Storage => __('Where uploaded files (photos, attachments, exports) are kept.'),
            self::Documenso => __('Optional. Official exports can be signed electronically through Documenso. You can skip this step and configure it later.'),
            self::Administrator => __('The person who administers this instance. There are no passwords: they sign in with a code sent to this address, so it must be one they can read.'),
        };
    }

    /**
     * The inputs of the step.
     *
     * @return list<Field>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->definedFields() as $field) {
            $fields[] = $field;

            // An empty secret box means "unchanged": an optional secret gets a checkbox to store an empty value on purpose.
            if ($field->secret && ! $field->required) {
                $fields[] = new Field($field->name.'_clear', null, __('No password'), 'checkbox', required: false, default: 'false', urlKey: $field->urlKey, clears: $field->name, lockKey: $field->envKey);
            }
        }

        return $fields;
    }

    /**
     * The inputs as declared, before the companions of optional secrets are added.
     *
     * @return list<Field>
     */
    private function definedFields(): array
    {
        return match ($this) {
            self::Database => [
                new Field('driver', 'DB_CONNECTION', __('Database engine'), 'select', default: 'pgsql', options: [
                    ['value' => 'pgsql', 'label' => 'PostgreSQL'],
                    ['value' => 'mysql', 'label' => 'MySQL'],
                    ['value' => 'mariadb', 'label' => 'MariaDB'],
                ], urlKey: 'DB_URL'),
                new Field('host', 'DB_HOST', __('Host'), default: '127.0.0.1', urlKey: 'DB_URL'),
                new Field('port', 'DB_PORT', __('Port'), 'number', default: '5432', help: __('Usually 5432 for PostgreSQL, 3306 for MySQL and MariaDB.'), urlKey: 'DB_URL'),
                new Field('database', 'DB_DATABASE', __('Database name'), default: 'inventaire', urlKey: 'DB_URL'),
                new Field('username', 'DB_USERNAME', __('Username'), default: 'inventaire', urlKey: 'DB_URL'),
                new Field('password', 'DB_PASSWORD', __('Password'), 'password', secret: true, required: false, urlKey: 'DB_URL'),
            ],
            self::Application => [
                new Field('url', 'APP_URL', __('Public URL'), 'url', default: 'http://localhost', help: __('The address people use to reach this instance, without a trailing slash.')),
                new Field('locale', 'APP_LOCALE', __('Default language'), 'select', default: 'fr', options: $this->localeOptions()),
                new Field('timezone', 'APP_TIMEZONE', __('Display timezone'), 'select', default: 'UTC', help: __('Dates are stored in UTC and shown in this timezone.'), options: $this->timezoneOptions()),
            ],
            self::Mail => [
                new Field('host', 'MAIL_HOST', __('SMTP host'), urlKey: 'MAIL_URL'),
                new Field('port', 'MAIL_PORT', __('SMTP port'), 'number', default: '587', urlKey: 'MAIL_URL'),
                new Field('scheme', 'MAIL_SCHEME', __('Connection security'), 'select', required: false, default: '', options: [
                    ['value' => '', 'label' => __('Automatic (implicit TLS on port 465, STARTTLS otherwise)')],
                    ['value' => 'smtp', 'label' => __('STARTTLS when the server offers it')],
                    ['value' => 'smtps', 'label' => __('Implicit TLS (SMTPS)')],
                ], urlKey: 'MAIL_URL'),
                new Field('username', 'MAIL_USERNAME', __('SMTP username'), required: false, urlKey: 'MAIL_URL'),
                new Field('password', 'MAIL_PASSWORD', __('SMTP password'), 'password', secret: true, required: false, urlKey: 'MAIL_URL'),
                new Field('from_address', 'MAIL_FROM_ADDRESS', __('Sender address'), 'email'),
                new Field('from_name', 'MAIL_FROM_NAME', __('Sender name'), default: (string) config('app.name')),
                new Field('test_recipient', null, __('Test address'), 'email', help: __('A 6-digit code is sent to this address. Enter it in the next screen.')),
            ],
            self::Storage => [
                new Field('disk', 'FILESYSTEM_DISK', __('Storage'), 'select', default: 'local', options: [
                    ['value' => 'local', 'label' => __('This server (local disk)')],
                    ['value' => 's3', 'label' => __('S3-compatible storage')],
                ]),
                new Field('key', 'AWS_ACCESS_KEY_ID', __('Access key'), showWhen: ['field' => 'disk', 'value' => 's3']),
                new Field('secret', 'AWS_SECRET_ACCESS_KEY', __('Secret key'), 'password', secret: true, showWhen: ['field' => 'disk', 'value' => 's3']),
                new Field('region', 'AWS_DEFAULT_REGION', __('Region'), default: 'us-east-1', showWhen: ['field' => 'disk', 'value' => 's3']),
                new Field('bucket', 'AWS_BUCKET', __('Bucket'), showWhen: ['field' => 'disk', 'value' => 's3']),
                new Field('endpoint', 'AWS_ENDPOINT', __('Endpoint'), 'url', required: false, help: __('Leave empty for Amazon S3. Fill in the URL of your provider otherwise (MinIO, Scaleway, OVH...).'), showWhen: ['field' => 'disk', 'value' => 's3']),
                new Field('path_style', 'AWS_USE_PATH_STYLE_ENDPOINT', __('Use path-style URLs'), 'checkbox', required: false, default: 'false', help: __('Needed by some providers and by MinIO.'), showWhen: ['field' => 'disk', 'value' => 's3']),
            ],
            self::Documenso => [
                new Field('url', 'DOCUMENSO_URL', __('Documenso URL'), 'url', placeholder: 'https://app.documenso.com', help: __('The address of your Documenso instance, or https://app.documenso.com.')),
                new Field('api_key', 'DOCUMENSO_API_KEY', __('API token'), 'password', secret: true, help: __('Created in Documenso, under the team settings, "API tokens".')),
            ],
            self::Administrator => [
                new Field('name', 'ADMIN_NAME', __('Name')),
                new Field('email', 'ADMIN_EMAIL', __('Email address'), 'email', help: __('The administrator account is created when the installation finishes.')),
            ],
        };
    }

    /**
     * Laravel validation rules for the step's inputs.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->definedRules();

        foreach ($this->fields() as $field) {
            if ($field->clears !== null) {
                $rules[$field->name] = ['nullable', 'boolean'];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function definedRules(): array
    {
        return match ($this) {
            self::Database => [
                'driver' => ['required', Rule::in(DatabaseSettings::DRIVERS)],
                'host' => ['required', 'string', 'max:255'],
                'port' => ['required', 'integer', 'between:1,65535'],
                'database' => ['required', 'string', 'max:255'],
                'username' => ['required', 'string', 'max:255'],
                'password' => ['nullable', 'string', 'max:255'],
            ],
            self::Application => [
                'url' => ['required', 'url:http,https', 'max:255'],
                'locale' => ['required', Rule::in(app(LocaleResolver::class)->supported())],
                'timezone' => ['required', 'timezone:all'],
            ],
            self::Mail => [
                'host' => ['required', 'string', 'max:255'],
                'port' => ['required', 'integer', 'between:1,65535'],
                'scheme' => ['nullable', Rule::in(['smtp', 'smtps'])],
                'username' => ['nullable', 'string', 'max:255'],
                'password' => ['nullable', 'string', 'max:255'],
                'from_address' => ['required', 'email', 'max:255'],
                'from_name' => ['required', 'string', 'max:255'],
                'test_recipient' => ['required', 'email', 'max:255'],
            ],
            self::Storage => [
                'disk' => ['required', Rule::in(['local', 's3'])],
                'key' => ['required_if:disk,s3', 'nullable', 'string', 'max:255'],
                'secret' => ['required_if:disk,s3', 'nullable', 'string', 'max:255'],
                'region' => ['required_if:disk,s3', 'nullable', 'string', 'max:255'],
                'bucket' => ['required_if:disk,s3', 'nullable', 'string', 'max:255'],
                'endpoint' => ['nullable', 'url:http,https', 'max:255'],
                'path_style' => ['nullable', 'boolean'],
            ],
            self::Documenso => [
                'url' => ['required', 'url:http,https', 'max:255'],
                'api_key' => ['required', 'string', 'max:255'],
            ],
            self::Administrator => [
                'name' => PersonName::rules(),
                'email' => EmailAddress::rules(),
            ],
        };
    }

    /**
     * Human names of the inputs, for validation messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach ($this->fields() as $field) {
            $attributes[$field->name] = mb_strtolower($field->label);
        }

        return $attributes;
    }

    /**
     * Languages the application is translated into, each written in itself.
     *
     * @return list<array{value: string, label: string}>
     */
    private function localeOptions(): array
    {
        $options = [];

        foreach (config()->array('app.locale_names') as $locale => $name) {
            $options[] = ['value' => (string) $locale, 'label' => (string) $name];
        }

        return $options;
    }

    /**
     * Every timezone PHP knows.
     *
     * @return list<array{value: string, label: string}>
     */
    private function timezoneOptions(): array
    {
        return array_map(
            static fn (string $zone): array => ['value' => $zone, 'label' => $zone],
            DateTimeZone::listIdentifiers(),
        );
    }
}
