<?php

declare(strict_types=1);

namespace App\Installer;

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
     * Whether the field's value is imposed by the process environment.
     */
    public function isLocked(Field $field): bool
    {
        return $field->envKey !== null && $this->locks->isLocked($field->envKey);
    }

    /**
     * Whether a value can be written to .env (the file exists and is writable,
     * or can be created).
     */
    public function canWrite(): bool
    {
        return $this->file->isWritable();
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
     * Completes a submitted form with what is already configured.
     *
     * Imposed values replace whatever was submitted, a missing field keeps its
     * configured value, and an empty secret keeps the stored secret (secrets
     * are never sent to browsers, so an empty box means "unchanged").
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> One entry per field of the step.
     */
    public function resolve(InstallStep $step, array $input): array
    {
        $current = $this->current($step);
        $resolved = [];

        foreach ($step->fields() as $field) {
            $name = $field->name;
            $keep = $this->isLocked($field)
                || ! array_key_exists($name, $input)
                || ($field->secret && ($input[$name] === null || $input[$name] === ''));

            $resolved[$name] = $keep ? $current[$name] : $input[$name];
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

            $updates[$field->envKey] = $this->toEnvValue($field, $values[$field->name]);
        }

        if ($step === InstallStep::Mail && ! $this->locks->isLocked('MAIL_MAILER')) {
            $updates['MAIL_MAILER'] = 'smtp';
        }

        if (! $this->file->isWritable()) {
            throw new InstallationException('The .env file is not writable. Make it writable by the web server user, or provide the settings as environment variables and run "php artisan inventaire:install --from-env".');
        }

        $this->file->set($updates);
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
