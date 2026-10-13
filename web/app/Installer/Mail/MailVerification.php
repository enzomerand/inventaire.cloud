<?php

declare(strict_types=1);

namespace App\Installer\Mail;

use App\Installer\Probes\MailProbe;
use App\Installer\Probes\ProbeResult;
use App\Installer\Settings\MailSettings;
use Illuminate\Config\Repository;

/**
 * Proves that the instance can send email, by having a person read back a code.
 *
 * Shared by the web wizard and the console installer: this class sends the code
 * and judges the answer, and leaves it to the caller to keep the
 * {@see MailChallenge} between the two.
 */
final class MailVerification
{
    public function __construct(
        private readonly MailProbe $probe,
        private readonly Repository $config,
    ) {}

    /**
     * Sends a fresh 6-digit code to the test recipient.
     *
     * @param  array<string, mixed>  $values  The mail step's values, kept in the challenge for after the confirmation.
     * @return array{0: ProbeResult, 1: MailChallenge|null} The sending verdict and, when it succeeded, the pending challenge.
     */
    public function begin(MailSettings $settings, string $recipient, array $values): array
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $lifetime = $this->config->integer('installer.mail_code.lifetime_minutes');

        $result = $this->probe->sendCode($settings, $recipient, $code, $lifetime);

        if (! $result->ok) {
            return [$result, null];
        }

        return [$result, MailChallenge::issue($values, $recipient, $code, $this->config->string('app.key'), $lifetime)];
    }

    /**
     * Judges a submitted code.
     *
     * @return array{0: MailCodeOutcome, 1: MailChallenge} The verdict and the challenge to keep.
     */
    public function confirm(MailChallenge $challenge, string $code): array
    {
        return $challenge->verify(
            $code,
            $this->config->string('app.key'),
            $this->config->integer('installer.mail_code.max_attempts'),
        );
    }

    /**
     * Wrong codes the challenge still tolerates.
     */
    public function attemptsLeft(MailChallenge $challenge): int
    {
        return $challenge->attemptsLeft($this->config->integer('installer.mail_code.max_attempts'));
    }
}
