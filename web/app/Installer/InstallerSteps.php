<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Mail\MailChallenge;
use App\Installer\Mail\MailVerification;
use App\Installer\Probes\DatabaseProbe;
use App\Installer\Probes\DocumensoProbe;
use App\Installer\Probes\MailProbe;
use App\Installer\Probes\ProbeResult;
use App\Installer\Probes\StorageProbe;
use App\Installer\Settings\DatabaseSettings;
use App\Installer\Settings\DocumensoSettings;
use App\Installer\Settings\MailSettings;
use App\Installer\Settings\StorageSettings;

/**
 * Validates and saves the wizard steps.
 *
 * Both the web controllers and the console installer drive the installation
 * through this class, so a step means the same thing everywhere: probe the real
 * service, and only when it works write the values to .env and record the step
 * as done. It receives values already completed by
 * {@see InstallerSettings::resolve()} and validated by {@see InstallStep::rules()}.
 */
final class InstallerSteps
{
    /** Whether a validated step is written to .env (false when the values already live in the environment). */
    private bool $persist = true;

    public function __construct(
        private readonly InstallationState $state,
        private readonly InstallerSettings $settings,
        private readonly DatabaseProbe $database,
        private readonly MailProbe $mail,
        private readonly MailVerification $mailVerification,
        private readonly StorageProbe $storage,
        private readonly DocumensoProbe $documenso,
    ) {}

    /**
     * A copy that checks the steps but writes nothing to .env.
     *
     * For `inventaire:install --from-env`, where the configuration already
     * exists (often as Docker variables, possibly with no writable .env) and
     * the installer only has to verify it and record the steps as done.
     */
    public function validatingOnly(): self
    {
        $copy = clone $this;
        $copy->persist = false;

        return $copy;
    }

    /**
     * Tests the database connection and, if it works, saves it.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws Exceptions\InstallationException When .env cannot be written.
     */
    public function saveDatabase(array $values): ProbeResult
    {
        return $this->saveIfWorking(InstallStep::Database, $values, $this->database->check(DatabaseSettings::fromArray($values)));
    }

    /**
     * Saves the application settings (nothing to test: they are validated by
     * their rules alone).
     *
     * @param  array<string, mixed>  $values
     *
     * @throws Exceptions\InstallationException When .env cannot be written.
     */
    public function saveApplication(array $values): void
    {
        $this->complete(InstallStep::Application, $values);
    }

    /**
     * Sends the verification code of the mail step. The step is saved later,
     * by {@see self::completeMail()}, once the code is confirmed.
     *
     * @param  array<string, mixed>  $values  Mail step values, including `test_recipient`.
     * @return array{0: ProbeResult, 1: MailChallenge|null}
     */
    public function beginMail(array $values): array
    {
        $recipient = is_scalar($values['test_recipient'] ?? null) ? (string) $values['test_recipient'] : '';
        unset($values['test_recipient']);

        return $this->mailVerification->begin(MailSettings::fromArray($values), $recipient, $values);
    }

    /**
     * Saves the mail settings of a confirmed challenge.
     *
     * @throws Exceptions\InstallationException When .env cannot be written.
     */
    public function completeMail(MailChallenge $challenge): void
    {
        $this->complete(InstallStep::Mail, $challenge->values);
    }

    /**
     * Validates the mail settings by opening an SMTP session, for the
     * non-interactive installs where nobody can read a code, and saves them.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws Exceptions\InstallationException When .env cannot be written.
     */
    public function saveMailAfterConnection(array $values): ProbeResult
    {
        return $this->saveIfWorking(InstallStep::Mail, $values, $this->mail->checkConnection(MailSettings::fromArray($values)));
    }

    /**
     * Saves the mail settings without testing them (`--skip-mail-test`).
     *
     * @param  array<string, mixed>  $values
     *
     * @throws Exceptions\InstallationException When .env cannot be written.
     */
    public function saveMailUntested(array $values): void
    {
        $this->complete(InstallStep::Mail, $values);
    }

    /**
     * Tests the storage by writing a file and, if it works, saves it.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws Exceptions\InstallationException When .env cannot be written.
     */
    public function saveStorage(array $values): ProbeResult
    {
        return $this->saveIfWorking(InstallStep::Storage, $values, $this->storage->check(StorageSettings::fromArray($values)));
    }

    /**
     * Tests the Documenso address and token and, if they work, saves them.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws Exceptions\InstallationException When .env cannot be written.
     */
    public function saveDocumenso(array $values): ProbeResult
    {
        return $this->saveIfWorking(InstallStep::Documenso, $values, $this->documenso->check(DocumensoSettings::fromArray($values)));
    }

    /**
     * Skips the optional Documenso step.
     */
    public function skipDocumenso(): void
    {
        $this->state->record(InstallStep::Documenso, InstallationState::STATUS_SKIPPED);
    }

    /**
     * Persists a step once its probe succeeded.
     *
     * @param  array<string, mixed>  $values
     */
    private function saveIfWorking(InstallStep $step, array $values, ProbeResult $result): ProbeResult
    {
        if ($result->ok) {
            $this->complete($step, $values);
        }

        return $result;
    }

    /**
     * Writes the values to .env and records the step as done.
     *
     * @param  array<string, mixed>  $values
     */
    private function complete(InstallStep $step, array $values): void
    {
        if ($this->persist) {
            $this->settings->persist($step, $values);
        }

        $this->state->record($step);
    }
}
