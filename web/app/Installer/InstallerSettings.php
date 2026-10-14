<?php

declare(strict_types=1);

namespace App\Installer;

use App\Auth\EmailAddress;
use App\Installer\Exceptions\InstallationException;

/**
 * Reads and stores the values of the wizard steps.
 *
 * The configuration of an instance is, in order of precedence: variables
 * imposed by the process environment (Docker), then the .env file, then the
 * defaults declared by each {@see Field}. The same precedence is used to
 * pre-fill forms, to complete what a form leaves out, and to decide what may
 * be written back: imposed values are never written, because Laravel would
 * keep preferring the environment and the change would silently do nothing.
 */
final class InstallerSettings
{
    public function __construct(
        private readonly EnvironmentFile $file,
        private readonly EnvironmentLocks $locks,
    ) {}

    /**
     * Whether the field's value is out of the wizard's hands: imposed by the
     * process environment, or replaced by a connection URL.
     */
    public function isLocked(Field $field): bool
    {
        $key = $field->envKey ?? $field->lockKey;

        return ($key !== null && $this->locks->isLocked($key)) || $this->isProvidedByUrl($field);
    }

    /**
     * The connection URL (DB_URL, MAIL_URL) provided for a step, from the
     * environment or from .env, or null when there is none.
     */
    public function providedUrl(InstallStep $step): ?string
    {
        $key = $step->urlKey();
        $url = $key === null ? null : $this->raw($key);

        return $url === null || $url === '' ? null : $url;
    }

    /**
     * Whether a connection URL stands in for this field. Such fields are shown
     * masked (a URL often contains a password) and never written.
     */
    public function isProvidedByUrl(Field $field): bool
    {
        if ($field->urlKey === null) {
            return false;
        }

        $url = $this->raw($field->urlKey);

        return $url !== null && $url !== '';
    }

    /**
     * Validation rules of a step: the step's own, minus the fields a connection
     * URL replaces (their values are not the ones in use).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(InstallStep $step): array
    {
        $rules = $step->rules();

        foreach ($step->fields() as $field) {
            if ($this->isProvidedByUrl($field)) {
                unset($rules[$field->name]);
            }
        }

        return $rules;
    }

    /**
     * Whether every setting the installation needs is provided by the
     * environment, so that nothing has to be written to .env (the typical
     * container: variables only, read-only application folder).
     */
    public function isEnvironmentOnly(): bool
    {
        foreach (InstallStep::required() as $step) {
            $values = $this->current($step);

            foreach ($step->fields() as $field) {
                $visible = $field->showWhen === null || ($values[$field->showWhen['field']] ?? null) === $field->showWhen['value'];

                if ($field->envKey !== null && $field->required && $visible && ! $this->isLocked($field)) {
                    return false;
                }
            }
        }

        return $this->locks->isLocked('MAIL_MAILER');
    }

    /**
     * The configured value of a variable: the imposed one, else the one in
     * .env, else null. The word "null" counts as unset, as it does for Laravel.
     */
    public function raw(string $envKey): ?string
    {
        $value = $this->locks->value($envKey) ?? $this->file->get($envKey);

        return $value === null || in_array(strtolower($value), ['null', '(null)'], true) ? null : $value;
    }

    /**
     * Whether a secret field has a value, without revealing it.
     */
    public function hasValue(Field $field): bool
    {
        return $field->envKey !== null && ($this->raw($field->envKey) ?? '') !== '';
    }

    /**
     * Every field of a step with its configured value (or its default),
     * secrets included: for server-side use only, never send it to a browser.
     *
     * @return array<string, mixed> Field name => value; checkboxes are booleans, everything else text.
     */
    public function current(InstallStep $step): array
    {
        $values = [];

        foreach ($step->fields() as $field) {
            $raw = $field->envKey === null ? null : $this->raw($field->envKey);
            $text = $raw ?? $field->default ?? '';

            $values[$field->name] = $field->type === 'checkbox'
                ? filter_var($text, FILTER_VALIDATE_BOOLEAN)
                : $text;
        }

        return $values;
    }

    /**
     * Fills in what the installers can suggest where nothing is configured yet.
     *
     * The administrator is most likely the person who just proved their
     * address at the mail step: that address is suggested for the
     * administrator's (it stays editable, and a configured or imposed address
     * wins, an imposed empty one included).
     *
     * @param  array<string, mixed>  $values  Field name => value, as returned by {@see self::current()}.
     * @param  string|null  $verifiedAddress  The address that read a verification code back at the mail step, if any.
     * @return array<string, mixed>
     */
    public function withSuggestions(InstallStep $step, array $values, ?string $verifiedAddress): array
    {
        if ($step !== InstallStep::Administrator || ($values['email'] ?? '') !== '' || $verifiedAddress === null || $verifiedAddress === '') {
            return $values;
        }

        $email = array_find($step->fields(), static fn (Field $field): bool => $field->name === 'email');

        if ($email !== null && ! $this->isLocked($email)) {
            $values['email'] = EmailAddress::normalize($verifiedAddress);
        }

        return $values;
    }

    /**
     * Completes a submitted form with what is already configured.
     *
     * The administrator's address comes out in canonical form ({@see EmailAddress::normalize()}).
     *
     * Imposed values replace whatever was submitted, a missing field keeps its
     * configured value, and an empty secret keeps the stored secret (secrets
     * are never sent to browsers, so an empty box means "unchanged"). To store
     * an empty secret on purpose, the secret's "no password" checkbox is ticked:
     * it wins over whatever is typed in the box.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> One entry per field of the step.
     */
    public function resolve(InstallStep $step, array $input): array
    {
        $current = $this->current($step);
        $resolved = [];
        $cleared = [];

        foreach ($step->fields() as $field) {
            if ($field->clears !== null) {
                $cleared[$field->clears] = ! $this->isLocked($field) && filter_var($input[$field->name] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
        }

        foreach ($step->fields() as $field) {
            $name = $field->name;

            if ($field->secret && ($cleared[$name] ?? false)) {
                $resolved[$name] = '';

                continue;
            }

            $keep = $this->isLocked($field)
                || ! array_key_exists($name, $input)
                || ($field->secret && ($input[$name] === null || $input[$name] === ''));

            $resolved[$name] = $keep ? $current[$name] : $input[$name];
        }

        // The address of the administrator is stored, validated and compared in its canonical form, in both installers.
        if ($step === InstallStep::Administrator && is_string($resolved['email'] ?? null)) {
            $resolved['email'] = EmailAddress::normalize($resolved['email']);
        }

        return $resolved;
    }

    /**
     * Writes the values of a step to .env, except the ones imposed by the
     * environment.
     *
     * @param  array<string, mixed>  $values  Field name => value, as returned by {@see self::resolve()}.
     *
     * @throws InstallationException When .env cannot be written.
     */
    public function persist(InstallStep $step, array $values): void
    {
        $updates = [];

        foreach ($step->fields() as $field) {
            if ($field->envKey === null || $this->isLocked($field) || ! array_key_exists($field->name, $values)) {
                continue;
            }

            // A setting that does not apply (S3 details for local storage) is not stored.
            if ($field->showWhen !== null && (string) ($values[$field->showWhen['field']] ?? '') !== $field->showWhen['value']) {
                continue;
            }

            $updates[$field->envKey] = $this->toEnvValue($field, $values[$field->name]);
        }

        if ($step === InstallStep::Mail && ! $this->locks->isLocked('MAIL_MAILER')) {
            $updates['MAIL_MAILER'] = 'smtp';
        }

        // Only what changes is written: a configuration that already lives in the environment needs no .env at all.
        $updates = array_filter($updates, fn (string $text, string $key): bool => ($this->raw($key) ?? '') !== ($text === 'null' ? '' : $text), ARRAY_FILTER_USE_BOTH);

        if ($updates === []) {
            return;
        }

        if (! $this->file->isWritable()) {
            throw new InstallationException('The .env file is not writable. Make it writable by the web server user, or provide the settings as environment variables and run "php artisan inventaire:install --from-env".');
        }

        $this->file->set($updates, EnvironmentFile::NEW_FILE_DEFAULTS);
    }

    /**
     * The text stored in .env for a value: booleans as true/false, and "null"
     * for an optional value left empty (what .env.example uses, and what
     * Laravel reads back as null).
     */
    private function toEnvValue(Field $field, mixed $value): string
    {
        if ($field->type === 'checkbox') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }

        $text = is_scalar($value) ? (string) $value : '';

        if (! $field->secret) {
            $text = trim($text);
        }

        if ($text === '') {
            return $field->secret ? '' : 'null';
        }

        return $text;
    }
}
