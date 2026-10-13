<?php

declare(strict_types=1);

use App\Installer\Mail\MailCodeOutcome;
use App\Installer\Mail\MailVerification;
use App\Installer\Mail\SetupCodeMail;
use App\Installer\Probes\MailProbe;
use App\Installer\Settings\MailSettings;
use Illuminate\Support\Facades\Mail;

/**
 * Mail settings for a test.
 *
 * @param  array<string, mixed>  $override
 */
function mailSettings(array $override = []): MailSettings
{
    return MailSettings::fromArray($override + [
        'host' => 'smtp.example.test', 'port' => 587, 'scheme' => '', 'username' => 'user', 'password' => 'smtp-secret',
        'from_address' => 'hello@example.test', 'from_name' => 'inventaire.cloud',
    ]);
}

it('sends the code to the recipient with the tested sender', function (): void {
    Mail::fake();

    $result = app(MailProbe::class)->sendCode(mailSettings(), 'me@example.test', '042517', 15);

    expect($result->ok)->toBeTrue();
    Mail::assertSent(SetupCodeMail::class, function (SetupCodeMail $mail): bool {
        return $mail->hasTo('me@example.test')
            && $mail->hasFrom('hello@example.test', 'inventaire.cloud')
            && $mail->code === '042517'
            && str_contains($mail->render(), '042517')
            && str_contains($mail->render(), '15');
    });
});

it('does not leave its temporary mailer configured', function (): void {
    Mail::fake();

    app(MailProbe::class)->sendCode(mailSettings(), 'me@example.test', '042517', 15);

    expect(config('mail.mailers.installer_probe'))->toBeNull();
});

it('reports an unreachable mail server without leaking the password', function (): void {
    $result = app(MailProbe::class)->sendCode(mailSettings(['host' => '127.0.0.1', 'port' => 1]), 'me@example.test', '042517', 15);

    expect($result->ok)->toBeFalse()
        ->and($result->field)->toBe('host')
        ->and($result->message)->toContain('Cannot reach the mail server')
        ->and($result->message)->not->toContain('smtp-secret')
        ->and(config('mail.mailers.installer_probe'))->toBeNull();
});

it('reports an unreachable server when only checking the connection', function (): void {
    $result = app(MailProbe::class)->checkConnection(mailSettings(['host' => '127.0.0.1', 'port' => 1, 'password' => 'smtp-secret']));

    expect($result->ok)->toBeFalse()->and($result->field)->toBe('host')->and($result->message)->not->toContain('smtp-secret');
});

it('opens an SMTP session on a reachable server', function (): void {
    if (! mailpitIsUp()) {
        $this->markTestSkipped('Mailpit (compose.dev.yaml) is not running on 127.0.0.1:1025.');
    }

    $settings = mailSettings(['host' => '127.0.0.1', 'port' => 1025, 'username' => '', 'password' => '']);

    expect(app(MailProbe::class)->checkConnection($settings)->ok)->toBeTrue();
});

describe('the verification code', function (): void {
    it('is six digits, sent by email and checked against what was typed', function (): void {
        Mail::fake();
        $verification = app(MailVerification::class);

        [$result, $challenge] = $verification->begin(mailSettings(), 'me@example.test', ['host' => 'smtp.example.test']);

        expect($result->ok)->toBeTrue()->and($challenge)->not->toBeNull()->and($challenge->recipient)->toBe('me@example.test');
        Mail::assertSent(SetupCodeMail::class, 1);

        $code = Mail::sent(SetupCodeMail::class)->first()->code;
        expect($code)->toMatch('/^\d{6}$/');

        [$wrong, $afterWrong] = $verification->confirm($challenge, $code === '000000' ? '111111' : '000000');
        expect($wrong)->toBe(MailCodeOutcome::Wrong)->and($verification->attemptsLeft($afterWrong))->toBe(4);

        [$right] = $verification->confirm($afterWrong, $code);
        expect($right)->toBe(MailCodeOutcome::Accepted);
    });

    it('gives no challenge when the email could not be sent', function (): void {
        [$result, $challenge] = app(MailVerification::class)->begin(mailSettings(['host' => '127.0.0.1', 'port' => 1]), 'me@example.test', []);

        expect($result->ok)->toBeFalse()->and($challenge)->toBeNull();
    });

    it('lets five attempts then asks for a new code', function (): void {
        Mail::fake();
        $verification = app(MailVerification::class);
        [, $challenge] = $verification->begin(mailSettings(), 'me@example.test', []);
        $code = Mail::sent(SetupCodeMail::class)->first()->code;
        $wrong = $code === '999999' ? '888888' : '999999';

        foreach (range(1, 4) as $attempt) {
            [$outcome, $challenge] = $verification->confirm($challenge, $wrong);
            expect($outcome)->toBe(MailCodeOutcome::Wrong);
        }

        [$outcome, $challenge] = $verification->confirm($challenge, $wrong);
        expect($outcome)->toBe(MailCodeOutcome::TooManyAttempts)
            ->and($verification->confirm($challenge, $code)[0])->toBe(MailCodeOutcome::TooManyAttempts);
    });
});
