<?php

declare(strict_types=1);

namespace App\Installer\Probes;

use App\Installer\Mail\SetupCodeMail;
use App\Installer\Settings\MailSettings;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/**
 * Checks SMTP settings against the real mail server, through a temporary mailer
 * that never replaces the application's own.
 */
final class MailProbe
{
    /** Name of the throw-away mailer. */
    private const MAILER = 'installer_probe';

    public function __construct(private readonly Repository $config) {}

    /**
     * Sends the verification code to the test recipient.
     *
     * Success only means the server accepted the message; that the person
     * received it is proven when they type the code back.
     */
    public function sendCode(MailSettings $settings, string $recipient, string $code, int $lifetimeMinutes): ProbeResult
    {
        return $this->withMailer($settings, function () use ($settings, $recipient, $code, $lifetimeMinutes): ProbeResult {
            Mail::mailer(self::MAILER)->to($recipient)->send(
                new SetupCodeMail($code, $lifetimeMinutes, $settings->fromAddress, $settings->fromName),
            );

            return ProbeResult::success();
        });
    }

    /**
     * Opens and authenticates an SMTP session without sending anything.
     *
     * This is the check available when nobody can read a code (non-interactive
     * installs, `--from-env`).
     */
    public function checkConnection(MailSettings $settings): ProbeResult
    {
        return $this->withMailer($settings, function (): ProbeResult {
            $mailer = Mail::mailer(self::MAILER);

            if ($mailer instanceof Mailer && ($transport = $mailer->getSymfonyTransport()) instanceof SmtpTransport) {
                $transport->start();
                $transport->stop();
            }

            return ProbeResult::success();
        });
    }

    /**
     * Registers the temporary mailer, runs the check and always removes it.
     *
     * @param  Closure(): ProbeResult  $check
     */
    private function withMailer(MailSettings $settings, Closure $check): ProbeResult
    {
        $this->config->set('mail.mailers.'.self::MAILER, $settings->mailerConfig((array) $this->config->get('mail.mailers.smtp', [])));
        Mail::purge(self::MAILER);

        try {
            return $check();
        } catch (Throwable $exception) {
            return ProbeResult::failure(__('Cannot reach the mail server: :reason', ['reason' => $this->reason($exception, $settings)]), 'host');
        } finally {
            Mail::purge(self::MAILER);
            $this->config->offsetUnset('mail.mailers.'.self::MAILER);
        }
    }

    /**
     * A short, single-line version of an error that never contains the password.
     */
    private function reason(Throwable $exception, MailSettings $settings): string
    {
        $message = (string) preg_replace('/\s+/', ' ', $exception->getMessage());

        foreach ([$settings->password, $settings->url, parse_url((string) $settings->url, PHP_URL_PASS) ?: null] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        return Str::limit(trim($message), 240);
    }
}
