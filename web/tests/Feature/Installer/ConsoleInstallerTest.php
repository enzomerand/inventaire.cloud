<?php

declare(strict_types=1);

use App\Installer\EnvironmentFile;
use App\Installer\EnvironmentLocks;
use App\Installer\InstallationState;
use App\Installer\InstallerSettings;
use App\Installer\InstallStep;
use App\Installer\Mail\MailVerification;
use App\Installer\Probes\MailProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Options of a complete non-interactive installation against the test database.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function installOptions(array $override = []): array
{
    $db = testDatabaseSettings();

    return $override + [
        '--no-interaction' => true,
        '--db-driver' => $db->driver, '--db-host' => $db->host, '--db-port' => (string) $db->port,
        '--db-name' => $db->database, '--db-user' => $db->username, '--db-password' => $db->password,
        '--app-url' => 'https://inventory.example.test', '--locale' => 'en', '--timezone' => 'Europe/Paris',
        '--mail-host' => 'smtp.example.test', '--mail-port' => '587',
        '--mail-from-address' => 'hello@example.test', '--mail-from-name' => 'My Shop',
        '--storage' => 'local',
    ];
}

/**
 * Writes the whole configuration to the sandbox .env, as an operator would have.
 */
function configuredEnvironment(object $test): void
{
    $db = testDatabaseSettings();
    $settings = app(InstallerSettings::class);

    $settings->persist(InstallStep::Database, ['driver' => $db->driver, 'host' => $db->host, 'port' => $db->port, 'database' => $db->database, 'username' => $db->username, 'password' => $db->password]);
    $settings->persist(InstallStep::Application, ['url' => 'https://inventory.example.test', 'locale' => 'fr', 'timezone' => 'Europe/Paris']);
    $settings->persist(InstallStep::Mail, ['host' => 'smtp.example.test', 'port' => '587', 'scheme' => '', 'username' => '', 'password' => '', 'from_address' => 'hello@example.test', 'from_name' => 'My Shop']);
    $settings->persist(InstallStep::Storage, ['disk' => 'local']);
}

/**
 * The sandbox .env, parsed.
 *
 * @return array<string, string>
 */
function consoleEnv(object $test): array
{
    return (new EnvironmentFile($test->sandbox->envPath(), $test->sandbox->envPath()))->all();
}

beforeEach(function (): void {
    $this->bootInstaller(installed: false);
});

describe('non-interactive installation', function (): void {
    it('installs from the command options', function (): void {
        Artisan::call('db:wipe', ['--force' => true]);

        $this->artisan('inventaire:install', installOptions(['--skip-mail-test' => true]))
            ->expectsOutputToContain('not tested')
            ->expectsOutputToContain('Installation complete')
            ->assertSuccessful();

        expect(app(InstallationState::class)->isInstalled())->toBeTrue()
            ->and(Schema::hasTable('users'))->toBeTrue()
            ->and(consoleEnv($this))->toMatchArray([
                'APP_URL' => 'https://inventory.example.test', 'APP_LOCALE' => 'en', 'APP_TIMEZONE' => 'Europe/Paris',
                'MAIL_HOST' => 'smtp.example.test', 'MAIL_FROM_NAME' => 'My Shop', 'FILESYSTEM_DISK' => 'local',
                'DB_DATABASE' => testDatabaseSettings()->database,
            ]);
        // The test address is only used to receive a code: nothing is stored.
        expect(file_get_contents($this->sandbox->envPath()))->not->toContain('--mail-test-recipient');
    });

    it('checks the SMTP server instead of a code when mail is not skipped', function (): void {
        Mail::fake();
        Artisan::call('db:wipe', ['--force' => true]);

        $this->artisan('inventaire:install', installOptions())->assertSuccessful();

        expect(app(InstallationState::class)->isInstalled())->toBeTrue();
    });

    it('stops at an SMTP server that cannot be reached', function (): void {
        $this->artisan('inventaire:install', installOptions(['--mail-host' => '127.0.0.1', '--mail-port' => '1']))
            ->expectsOutputToContain('Cannot reach the mail server')
            ->assertFailed();

        expect(app(InstallationState::class)->isInstalled())->toBeFalse()
            ->and(app(InstallationState::class)->status(InstallStep::Mail))->toBe('pending')
            ->and(consoleEnv($this)['MAIL_HOST'] ?? '')->not->toBe('smtp.example.test');
    });

    it('stops at the first step that fails and keeps the ones that worked', function (): void {
        $this->artisan('inventaire:install', installOptions(['--db-password' => 'wrong-password']))
            ->expectsOutputToContain('Cannot connect to the database')
            ->assertFailed();

        expect(app(InstallationState::class)->isInstalled())->toBeFalse()
            ->and(app(InstallationState::class)->status(InstallStep::Database))->toBe('pending');
    });

    it('reports invalid values with the same rules as the web form', function (): void {
        $this->artisan('inventaire:install', installOptions(['--app-url' => 'not-a-url', '--locale' => 'xx']))
            ->expectsOutputToContain('public url')
            ->assertFailed();

        expect(app(InstallationState::class)->status(InstallStep::Database))->toBe('done')
            ->and(app(InstallationState::class)->status(InstallStep::Application))->toBe('pending');
    });

    it('does not need a test address, which only exists to receive a code', function (): void {
        Artisan::call('db:wipe', ['--force' => true]);

        $this->artisan('inventaire:install', installOptions(['--skip-mail-test' => true]))->assertSuccessful();
    });

    it('configures Documenso only when both values are given', function (): void {
        Artisan::call('db:wipe', ['--force' => true]);

        $this->artisan('inventaire:install', installOptions(['--skip-mail-test' => true]))->assertSuccessful();

        expect(consoleEnv($this)['DOCUMENSO_URL'] ?? '')->toBe('')->and(app(InstallationState::class)->isInstalled())->toBeTrue();
    });

    it('validates Documenso when its values are given', function (): void {
        Http::fake(['*' => Http::response('', 401)]);

        $this->artisan('inventaire:install', installOptions(['--skip-mail-test' => true, '--documenso-url' => 'https://sign.example.test', '--documenso-api-key' => 'api_bad']))
            ->expectsOutputToContain('Documenso refused this API token')
            ->assertFailed();

        expect(app(InstallationState::class)->isInstalled())->toBeFalse();
    });

    it('refuses --skip-mail-test when a person could answer prompts', function (): void {
        $this->artisan('inventaire:install', ['--skip-mail-test' => true])
            ->expectsOutputToContain('only allowed in non-interactive mode')
            ->assertExitCode(2);
    });

    it('refuses to start when the requirements are not met', function (): void {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Directory permissions do not restrict root.');
        }

        chmod($this->sandbox->root, 0555);

        $this->artisan('inventaire:install', installOptions())
            ->expectsOutputToContain('does not meet the requirements')
            ->assertFailed();
    });
});

describe('--from-env', function (): void {
    it('validates the configuration with the live checks, then installs without rewriting .env', function (): void {
        configuredEnvironment($this);
        Mail::fake();
        Artisan::call('db:wipe', ['--force' => true]);
        app()['env'] = 'local';
        $before = file_get_contents($this->sandbox->envPath());

        $this->artisan('inventaire:install', ['--from-env' => true])->assertSuccessful();

        expect(app(InstallationState::class)->isInstalled())->toBeTrue()
            ->and(Schema::hasTable('users'))->toBeTrue()
            ->and(file_get_contents($this->sandbox->envPath()))->toBe($before);
    });

    it('fails when the configuration does not work', function (): void {
        configuredEnvironment($this);
        app(EnvironmentFile::class)->set(['DB_PASSWORD' => 'wrong-password']);

        $this->artisan('inventaire:install', ['--from-env' => true])
            ->expectsOutputToContain('Cannot connect to the database')
            ->assertFailed();

        expect(app(InstallationState::class)->isInstalled())->toBeFalse();
    });

    it('fails on missing settings instead of asking', function (): void {
        $this->artisan('inventaire:install', ['--from-env' => true])->assertFailed();

        expect(app(InstallationState::class)->isInstalled())->toBeFalse();
    });

    it('works with configuration that only exists in the environment and a read-only .env', function (): void {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Directory permissions do not restrict root.');
        }

        $db = testDatabaseSettings();
        // What a Docker container looks like: everything imposed, no .env at all, nothing writable.
        $this->processEnvironment = [
            'DB_CONNECTION' => $db->driver, 'DB_HOST' => $db->host, 'DB_PORT' => (string) $db->port, 'DB_DATABASE' => $db->database,
            'DB_USERNAME' => $db->username, 'DB_PASSWORD' => $db->password, 'APP_URL' => 'https://inventory.example.test',
            'APP_LOCALE' => 'fr', 'APP_TIMEZONE' => 'UTC', 'MAIL_HOST' => 'smtp.example.test', 'MAIL_PORT' => '587',
            'MAIL_FROM_ADDRESS' => 'hello@example.test', 'MAIL_FROM_NAME' => 'My Shop', 'FILESYSTEM_DISK' => 'local',
        ];
        $this->app->forgetInstance(EnvironmentLocks::class);
        Mail::fake();
        Artisan::call('db:wipe', ['--force' => true]);
        app()['env'] = 'production';
        config(['app.debug' => false]);
        chmod($this->sandbox->root, 0555);

        $this->artisan('inventaire:install', ['--from-env' => true])->assertSuccessful();

        expect(app(InstallationState::class)->isInstalled())->toBeTrue()->and(is_file($this->sandbox->envPath()))->toBeFalse();
    });
});

it('does nothing on an installed instance', function (): void {
    $this->bootInstaller(installed: true);

    $this->artisan('inventaire:install', installOptions())->expectsOutputToContain('already installed')->assertSuccessful();

    expect(is_file($this->sandbox->envPath()))->toBeFalse();
});

describe('interactive installation', function (): void {
    it('asks for every step, proves the mail settings with a code, and installs', function (): void {
        $db = testDatabaseSettings();
        Mail::fake();
        Artisan::call('db:wipe', ['--force' => true]);
        $this->app->singleton(MailVerification::class, fn ($app): MailVerification => new MailVerification($app->make(MailProbe::class), $app->make('config'), fn (): string => '246810'));

        $this->artisan('inventaire:install')
            ->expectsChoice('Database engine', $db->driver, ['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'])
            ->expectsQuestion('Host', $db->host)
            ->expectsQuestion('Port', (string) $db->port)
            ->expectsQuestion('Database name', $db->database)
            ->expectsQuestion('Username', $db->username)
            ->expectsQuestion('Password', $db->password)
            ->expectsQuestion('Public URL', 'https://inventory.example.test')
            ->expectsChoice('Default language', 'en', ['fr' => 'Français', 'en' => 'English'])
            ->expectsChoice('Timezone', 'Europe/Paris', array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
            ->expectsQuestion('SMTP host', 'smtp.example.test')
            ->expectsQuestion('SMTP port', '587')
            ->expectsChoice('Connection security', '__none__', [
                '__none__' => 'Automatic (implicit TLS on port 465, STARTTLS otherwise)',
                'smtp' => 'STARTTLS when the server offers it',
                'smtps' => 'Implicit TLS (SMTPS)',
            ])
            ->expectsQuestion('SMTP username', '')
            ->expectsQuestion('SMTP password', '')
            ->expectsQuestion('Sender address', 'hello@example.test')
            ->expectsQuestion('Sender name', 'My Shop')
            ->expectsQuestion('Test address', 'admin@example.test')
            ->expectsQuestion('Verification code', '246810')
            ->expectsChoice('Storage', 'local', ['local' => 'This server (local disk)', 's3' => 'S3-compatible storage'])
            ->expectsConfirmation('Configure the electronic signature (Documenso) now?', 'no')
            ->expectsOutputToContain('Installation complete')
            ->assertSuccessful();

        expect(app(InstallationState::class)->isInstalled())->toBeTrue()
            ->and(consoleEnv($this))->toMatchArray(['MAIL_HOST' => 'smtp.example.test', 'APP_LOCALE' => 'en', 'FILESYSTEM_DISK' => 'local']);
    });

    it('lets the person correct a step whose test failed', function (): void {
        $db = testDatabaseSettings();

        $this->artisan('inventaire:install')
            ->expectsChoice('Database engine', $db->driver, ['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'])
            ->expectsQuestion('Host', $db->host)
            ->expectsQuestion('Port', '1')
            ->expectsQuestion('Database name', $db->database)
            ->expectsQuestion('Username', $db->username)
            ->expectsQuestion('Password', $db->password)
            ->expectsOutputToContain('Cannot connect to the database')
            ->expectsConfirmation('Try again?', 'no')
            ->assertFailed();

        expect(app(InstallationState::class)->status(InstallStep::Database))->toBe('pending');
    });
});
