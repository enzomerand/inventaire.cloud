<?php

declare(strict_types=1);

use App\Installer\EnvironmentLocks;
use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallationFinalizer;
use App\Installer\InstallationState;
use App\Installer\InstallerSettings;
use App\Installer\InstallStep;
use App\Installer\Probes\DatabaseProbe;
use App\Installer\Probes\MailProbe;
use App\Installer\Settings\DatabaseSettings;
use App\Installer\Settings\MailSettings;
use App\Installer\SetupToken;
use Illuminate\Support\Facades\Artisan;

/**
 * A DB_URL pointing at the test database.
 *
 * @param  array<string, string>  $override  Parts to replace: scheme, user, password, host, port, database.
 */
function databaseUrl(array $override = []): string
{
    $db = testDatabaseSettings();
    $parts = $override + [
        'scheme' => ['pgsql' => 'postgres', 'mysql' => 'mysql', 'mariadb' => 'mariadb'][$db->driver],
        'user' => $db->username, 'password' => $db->password, 'host' => $db->host, 'port' => (string) $db->port, 'database' => $db->database,
    ];

    return sprintf('%s://%s:%s@%s:%s/%s', $parts['scheme'], rawurlencode($parts['user']), rawurlencode($parts['password']), $parts['host'], $parts['port'], $parts['database']);
}

/**
 * The wizard's database step as the browser receives it.
 *
 * @return array<string, mixed>
 */
function databaseStepState(object $test): array
{
    preg_match('#id="installer-state">(.*?)</script>#s', $test->get('/install')->getContent(), $match);

    return collect(json_decode($match[1], true)['steps'])->firstWhere('id', 'database');
}

describe('DB_URL', function (): void {
    it('is read from the environment or from .env', function (): void {
        $settings = app(InstallerSettings::class);
        expect($settings->providedUrl(InstallStep::Database))->toBeNull();

        file_put_contents($this->sandbox->envPath(), "DB_URL=postgres://u:p@h/d\n");

        expect($settings->providedUrl(InstallStep::Database))->toBe('postgres://u:p@h/d');
    });

    it('is checked as is by the probe, with the engine it names', function (): void {
        $probe = app(DatabaseProbe::class);
        $db = testDatabaseSettings();

        $result = $probe->check(DatabaseSettings::fromArray(['driver' => $db->driver], databaseUrl()));

        expect($result->ok)->toBeTrue()->and($result->message)->toBeNull();
    });

    it('fails with the URL it was given, not with the form fields, and never reveals its password', function (): void {
        $db = testDatabaseSettings();
        // The fields below are valid: only the URL is wrong, so a probe that ignored the URL would succeed.
        $result = app(DatabaseProbe::class)->check(DatabaseSettings::fromArray(
            ['driver' => $db->driver, 'host' => $db->host, 'port' => $db->port, 'database' => $db->database, 'username' => $db->username, 'password' => $db->password],
            databaseUrl(['password' => 'Wrong-Passw0rd']),
        ));

        expect($result->ok)->toBeFalse()->and($result->field)->toBe('username')->and($result->message)->not->toContain('Wrong-Passw0rd');
    });

    it('rejects a URL naming the wrong MySQL family', function (): void {
        $driver = config('database.default');

        if ($driver === 'pgsql') {
            $this->markTestSkipped('Needs a MySQL or MariaDB server.');
        }

        $result = app(DatabaseProbe::class)->check(DatabaseSettings::fromArray(['driver' => $driver], databaseUrl(['scheme' => $driver === 'mysql' ? 'mariadb' : 'mysql'])));

        expect($result->ok)->toBeFalse()->and($result->field)->toBe('driver');
    });

    it('turns the fields read-only and masked, and tells why', function (): void {
        $this->bootInstaller(installed: false);
        $this->processEnvironment = ['DB_URL' => databaseUrl()];
        $this->app->forgetInstance(EnvironmentLocks::class);
        $this->get('/');
        $this->postJson('/install/token', ['token' => app(SetupToken::class)->current()])->assertOk();

        $step = databaseStepState($this);

        expect($step['notice'])->toContain('DB_URL')
            ->and(collect($step['fields'])->every(fn (array $field): bool => $field['locked'] && $field['masked'] && $field['value'] === ''))->toBeTrue()
            ->and(json_encode($step))->not->toContain(databaseUrl())->not->toContain('"value":"'.testDatabaseSettings()->password.'"');
    });

    it('is saved without asking for the replaced fields, and writes none of them', function (): void {
        $this->bootInstaller(installed: false);
        $this->processEnvironment = ['DB_URL' => databaseUrl()];
        $this->app->forgetInstance(EnvironmentLocks::class);
        $this->get('/');
        $this->postJson('/install/token', ['token' => app(SetupToken::class)->current()])->assertOk();

        $this->postJson('/install/database', [])->assertOk()->assertJsonPath('statuses.database', 'done');

        expect(is_file($this->sandbox->envPath()))->toBeFalse();
    });

    it('is used by the migrations too', function (): void {
        $this->bootInstaller(installed: false);
        $state = app(InstallationState::class);

        foreach (InstallStep::required() as $step) {
            $state->record($step);
        }

        // Valid fields in .env, wrong password in the URL: the migrations must use the URL and fail.
        $db = testDatabaseSettings();
        app(InstallerSettings::class)->persist(InstallStep::Database, ['driver' => $db->driver, 'host' => $db->host, 'port' => $db->port, 'database' => $db->database, 'username' => $db->username, 'password' => $db->password]);
        $this->processEnvironment = ['DB_URL' => databaseUrl(['password' => 'Wrong-Passw0rd'])];
        $this->app->forgetInstance(EnvironmentLocks::class);
        $this->app->forgetInstance(InstallerSettings::class);
        $this->app->forgetInstance(InstallationFinalizer::class);

        expect(fn () => app(InstallationFinalizer::class)->finalize())->toThrow(InstallationException::class, 'migration failed');

        // And with the right URL they succeed.
        $this->processEnvironment = ['DB_URL' => databaseUrl()];
        $this->app->forgetInstance(EnvironmentLocks::class);
        $this->app->forgetInstance(InstallerSettings::class);
        $this->app->forgetInstance(InstallationFinalizer::class);
        Artisan::call('db:wipe', ['--force' => true]);

        app(InstallationFinalizer::class)->finalize();

        expect($state->isInstalled())->toBeTrue();
    });
});

describe('MAIL_URL', function (): void {
    it('replaces the connection fields of the mailer under test', function (): void {
        $settings = MailSettings::fromArray(['host' => 'ignored', 'port' => 25, 'from_address' => 'a@b.c', 'from_name' => 'n'], 'smtp://user:pw@smtp.example.test:2525');

        expect($settings->mailerConfig(['scheme' => null, 'host' => 'base-host']))->toMatchArray(['transport' => 'smtp', 'url' => 'smtp://user:pw@smtp.example.test:2525', 'host' => 'base-host', 'timeout' => 10]);
    });

    it('is the connection the probe opens, without leaking its password', function (): void {
        $settings = MailSettings::fromArray(['host' => '127.0.0.1', 'port' => 25, 'from_address' => 'a@b.c', 'from_name' => 'n'], 'smtp://user:Secret-pw1@127.0.0.1:1');

        $result = app(MailProbe::class)->checkConnection($settings);

        expect($result->ok)->toBeFalse()->and($result->message)->not->toContain('Secret-pw1');
    });

    it('opens an SMTP session on a reachable server', function (): void {
        if (! mailpitIsUp()) {
            $this->markTestSkipped('Mailpit (compose.dev.yaml) is not running on 127.0.0.1:1025.');
        }

        $settings = MailSettings::fromArray(['host' => 'unused', 'port' => 25, 'from_address' => 'a@b.c', 'from_name' => 'n'], 'smtp://127.0.0.1:1025');

        expect(app(MailProbe::class)->checkConnection($settings)->ok)->toBeTrue();
    });

    it('turns the connection fields of the mail step read-only', function (): void {
        $this->processEnvironment = ['MAIL_URL' => 'smtp://u:p@smtp.example.test:587'];
        $settings = app(InstallerSettings::class);
        $fields = collect(InstallStep::Mail->fields())->keyBy('name');

        expect($settings->isLocked($fields['host']))->toBeTrue()
            ->and($settings->isLocked($fields['password']))->toBeTrue()
            // The sender identity is not part of the URL.
            ->and($settings->isLocked($fields['from_address']))->toBeFalse()
            ->and(array_keys($settings->rules(InstallStep::Mail)))->toBe(['from_address', 'from_name', 'test_recipient']);
    });
});
