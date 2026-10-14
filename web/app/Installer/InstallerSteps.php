<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Exceptions\InstallationException;
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
     * Tests a step and, if it works, saves it: the one entry point both
     * installers loop over.
     *
     * The mail step is saved here by opening an SMTP session, which is what a
     * non-interactive run can do; interactive runs prove it with a code instead
     * ({@see self::beginMail()}).
     *
     * @param  array<string, mixed>  $values
     *
     * @throws InstallationException When .env cannot be written.
     */
    public function save(InstallStep $step, array $values): ProbeResult
    {
        $result = match ($step) {
            InstallStep::Database => $this->database->check(DatabaseSettings::fromArray($values, $this->settings->providedUrl($step))),
            InstallStep::Application => ProbeResult::success(), // Nothing to test: the rules alone validate it.
            InstallStep::Mail => $this->mail->checkConnection(MailSettings::fromArray($values, $this->settings->providedUrl($step))),
            InstallStep::Storage => $this->storage->check(StorageSettings::fromArray($values)),
            InstallStep::Documenso => $this->documenso->check(DocumensoSettings::fromArray($values)),
        };

        if ($result->ok) {
            $this->complete($step, $values);
        }

        return $result;
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

        return $this->mailVerification->begin(MailSettings::fromArray($values, $this->settings->providedUrl(InstallStep::Mail)), $recipient, $values);
    }

    /**
     * Saves the mail settings of a confirmed challenge.
     *
     * @throws InstallationException When .env cannot be written.
     */
    public function completeMail(MailChallenge $challenge): void
    {
        $this->complete(InstallStep::Mail, $challenge->values);
    }

    /**
     * Saves the mail settings without testing them (`--skip-mail-test`).
     *
     * @param  array<string, mixed>  $values
     *
     * @throws InstallationException When .env cannot be written.
     */
    public function saveMailUntested(array $values): void
    {
        $this->complete(InstallStep::Mail, $values);
    }

    /**
     * Skips an optional step.
     *
     * @throws InstallationException When the step is required.
     */
    public function skip(InstallStep $step): void
    {
        if (! $step->isOptional()) {
            throw new InstallationException('This step cannot be skipped.');
        }

        $this->state->record($step, InstallationState::STATUS_SKIPPED);
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
