<?php

declare(strict_types=1);

use App\Installer\Settings\DatabaseSettings;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the whole Laravel application through the base TestCase.
| Unit tests stay framework-free on purpose: they must run without a container.
|
*/

pest()->extend(TestCase::class)->in('Feature');

// The installer's messages are asserted on their English source text.
pest()->in('Feature/Installer')->beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * Database settings pointing at the engine the suite currently runs on (the
 * dedicated test database), with optional overrides.
 *
 * @param  array<string, mixed>  $override
 */
function testDatabaseSettings(array $override = []): DatabaseSettings
{
    $driver = config('database.default');
    $connection = config("database.connections.{$driver}");

    return DatabaseSettings::fromArray(array_merge([
        'driver' => $driver,
        'host' => $connection['host'],
        'port' => $connection['port'],
        'database' => $connection['database'],
        'username' => $connection['username'],
        'password' => $connection['password'],
    ], $override));
}

/**
 * Whether something listens on the local Mailpit SMTP port of compose.dev.yaml.
 */
function mailpitIsUp(): bool
{
    $socket = @fsockopen('127.0.0.1', 1025, $errno, $error, 0.5);

    if ($socket === false) {
        return false;
    }

    fclose($socket);

    return true;
}

/**
 * The process environment of a container configured only through variables,
 * pointing at the test database.
 *
 * @return array<string, string>
 */
function containerEnvironment(): array
{
    $db = testDatabaseSettings();

    return [
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
        'DB_CONNECTION' => $db->driver, 'DB_HOST' => $db->host, 'DB_PORT' => (string) $db->port, 'DB_DATABASE' => $db->database,
        'DB_USERNAME' => $db->username, 'DB_PASSWORD' => $db->password,
        'APP_URL' => 'https://inventory.example.test', 'APP_LOCALE' => 'fr', 'APP_TIMEZONE' => 'UTC',
        'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'smtp.example.test', 'MAIL_PORT' => '587',
        'MAIL_FROM_ADDRESS' => 'hello@example.test', 'MAIL_FROM_NAME' => 'My Shop', 'FILESYSTEM_DISK' => 'local',
    ];
}

/**
 * The headers of a call made by the web front: an API request from the application's own host, which Sanctum treats
 * as stateful (session and CSRF protection). Without them an API call is stateless, as a script's would be.
 *
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function frontend(array $extra = []): array
{
    $origin = rtrim(config()->string('app.url'), '/');

    return $extra + ['Origin' => $origin, 'Referer' => $origin.'/login'];
}

/**
 * The emails the application sent so far in this test (the "array" mailer keeps them).
 *
 * @return list<Email>
 */
function sentMails(): array
{
    $transport = app('mailer')->getSymfonyTransport();

    return $transport->messages()->map(fn ($message) => $message->getOriginalMessage())->values()->all();
}

/**
 * The 6-digit code in the last email sent, as the person would read it.
 */
function lastSentCode(): string
{
    $mails = sentMails();

    expect($mails)->not->toBe([], 'No email was sent.');

    preg_match('/\b(\d{6})\b/', (string) end($mails)->getTextBody(), $matches);

    return $matches[1] ?? '';
}
