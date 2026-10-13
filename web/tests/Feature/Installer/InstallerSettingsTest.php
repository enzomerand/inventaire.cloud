<?php

declare(strict_types=1);

use App\Installer\EnvironmentFile;
use App\Installer\EnvironmentLocks;
use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallerSettings;
use App\Installer\InstallStep;

/**
 * Settings over the sandbox .env with the given contents and imposed environment.
 *
 * @param  array<string, string>  $process
 */
function settingsFor(object $test, string $contents = '', array $process = []): InstallerSettings
{
    $file = new EnvironmentFile($test->sandbox->envPath(), base_path('.env.example'));

    if ($contents !== '') {
        file_put_contents($test->sandbox->envPath(), $contents);
    }

    return new InstallerSettings($file, new EnvironmentLocks($file, fn (): array => $process));
}

/**
 * The sandbox .env, parsed.
 *
 * @return array<string, string>
 */
function consoleEnvFile(object $test): array
{
    return (new EnvironmentFile($test->sandbox->envPath(), $test->sandbox->envPath()))->all();
}

it('falls back to the defaults of the fields', function (): void {
    $values = settingsFor($this)->current(InstallStep::Database);

    expect($values)->toMatchArray(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5432', 'database' => 'inventaire', 'username' => 'inventaire', 'password' => '']);
});

it('reads configured values, typing checkboxes as booleans', function (): void {
    $settings = settingsFor($this, "DB_CONNECTION=mysql\nDB_PORT=3306\nAWS_USE_PATH_STYLE_ENDPOINT=true\nMAIL_USERNAME=null\nMAIL_PASSWORD=\n");

    expect($settings->current(InstallStep::Database))->toMatchArray(['driver' => 'mysql', 'port' => '3306'])
        ->and($settings->current(InstallStep::Storage)['path_style'])->toBeTrue()
        ->and($settings->current(InstallStep::Mail))->toMatchArray(['username' => '', 'password' => '']);
});

it('prefers what the environment imposes over .env', function (): void {
    $settings = settingsFor($this, "DB_HOST=from-file\nDB_PORT=1111\n", ['DB_HOST' => 'from-docker']);

    expect($settings->current(InstallStep::Database)['host'])->toBe('from-docker')
        ->and($settings->current(InstallStep::Database)['port'])->toBe('1111');
});

it('tells which fields are imposed', function (): void {
    $settings = settingsFor($this, '', ['DB_HOST' => 'db']);
    $fields = collect(InstallStep::Database->fields())->keyBy('name');

    expect($settings->isLocked($fields['host']))->toBeTrue()->and($settings->isLocked($fields['port']))->toBeFalse();
});

it('knows whether a secret is set without exposing it', function (): void {
    $settings = settingsFor($this, "DB_PASSWORD=hunter2\n");
    $fields = collect(InstallStep::Database->fields())->keyBy('name');

    expect($settings->hasValue($fields['password']))->toBeTrue();

    unlink($this->sandbox->envPath());

    expect(settingsFor($this)->hasValue($fields['password']))->toBeFalse();
});

describe('completing a submitted form', function (): void {
    it('keeps what is configured for fields the form leaves out', function (): void {
        $settings = settingsFor($this, "DB_HOST=db.internal\n");

        expect($settings->resolve(InstallStep::Database, ['port' => '3306']))->toMatchArray(['host' => 'db.internal', 'port' => '3306']);
    });

    it('lets an imposed value win over the submitted one', function (): void {
        $settings = settingsFor($this, '', ['DB_HOST' => 'from-docker']);

        expect($settings->resolve(InstallStep::Database, ['host' => 'tampered'])['host'])->toBe('from-docker');
    });

    it('keeps the stored secret when the box is left empty', function (?string $submitted): void {
        $settings = settingsFor($this, "DB_PASSWORD=hunter2\n");

        expect($settings->resolve(InstallStep::Database, ['password' => $submitted])['password'])->toBe('hunter2');
    })->with(['null' => null, 'empty string' => '']);

    it('takes a new secret', function (): void {
        $settings = settingsFor($this, "DB_PASSWORD=hunter2\n");

        expect($settings->resolve(InstallStep::Database, ['password' => 'new-one'])['password'])->toBe('new-one');
    });

    it('lets an optional plain value be cleared', function (): void {
        $settings = settingsFor($this, "MAIL_USERNAME=someone\n");

        expect($settings->resolve(InstallStep::Mail, ['username' => null])['username'])->toBeNull();
    });
});

describe('storing the values of a step', function (): void {
    it('writes each field to its variable and marks the mailer as smtp', function (): void {
        $settings = settingsFor($this);

        $settings->persist(InstallStep::Mail, [
            'host' => 'smtp.example.com', 'port' => '587', 'scheme' => 'smtp', 'username' => 'user', 'password' => 'p#ss word',
            'from_address' => 'hello@example.com', 'from_name' => 'My Shop', 'test_recipient' => 'me@example.com',
        ]);

        $file = new EnvironmentFile($this->sandbox->envPath(), $this->sandbox->envPath());

        expect($file->all())->toMatchArray([
            'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'smtp.example.com', 'MAIL_PORT' => '587', 'MAIL_SCHEME' => 'smtp',
            'MAIL_USERNAME' => 'user', 'MAIL_PASSWORD' => 'p#ss word', 'MAIL_FROM_ADDRESS' => 'hello@example.com', 'MAIL_FROM_NAME' => 'My Shop',
        ])->and($file->all())->not->toHaveKey('test_recipient');
        // The test address is used once and never stored.
        expect(file_get_contents($this->sandbox->envPath()))->not->toContain('me@example.com');
    });

    it('writes "null" for an optional value left empty and keeps a secret empty', function (): void {
        $settings = settingsFor($this);

        $settings->persist(InstallStep::Mail, ['host' => 'h', 'port' => '25', 'scheme' => '', 'username' => '', 'password' => '', 'from_address' => 'a@b.c', 'from_name' => 'n']);

        $contents = file_get_contents($this->sandbox->envPath());

        expect($contents)->toContain("MAIL_SCHEME=null\n")->toContain("MAIL_USERNAME=null\n")->toContain("MAIL_PASSWORD=\n");
    });

    it('writes checkboxes as true or false', function (): void {
        settingsFor($this)->persist(InstallStep::Storage, ['disk' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'r', 'bucket' => 'b', 'endpoint' => '', 'path_style' => true]);

        expect(file_get_contents($this->sandbox->envPath()))->toContain("AWS_USE_PATH_STYLE_ENDPOINT=true\n")->toContain("AWS_ENDPOINT=null\n");
    });

    it('never writes what the environment imposes', function (): void {
        $settings = settingsFor($this, "DB_HOST=from-file\n", ['DB_HOST' => 'from-docker', 'MAIL_MAILER' => 'ses']);

        $settings->persist(InstallStep::Database, ['driver' => 'mysql', 'host' => 'tampered', 'port' => '3306', 'database' => 'd', 'username' => 'u', 'password' => '']);
        $settings->persist(InstallStep::Mail, ['host' => 'h', 'port' => '25', 'scheme' => '', 'username' => '', 'password' => '', 'from_address' => 'a@b.c', 'from_name' => 'n']);

        $env = (new EnvironmentFile($this->sandbox->envPath(), $this->sandbox->envPath()))->all();

        expect($env['DB_HOST'])->toBe('from-file')->and($env['DB_CONNECTION'])->toBe('mysql')->and($env)->not->toHaveKey('MAIL_MAILER');
    });

    it('creates .env from the example when it does not exist', function (): void {
        settingsFor($this)->persist(InstallStep::Application, ['url' => 'https://inventory.example.com', 'locale' => 'en', 'timezone' => 'Europe/Paris']);

        $contents = file_get_contents($this->sandbox->envPath());

        expect($contents)->toContain('APP_NAME="inventaire.cloud"')->toContain('APP_URL=https://inventory.example.com')
            ->toContain('APP_LOCALE=en')->toContain('APP_TIMEZONE=Europe/Paris')
            // The example's development defaults must not leak into a file the installer creates.
            ->toContain('APP_ENV=production')->toContain('APP_DEBUG=false');
    });

    it('leaves the environment of an existing .env alone', function (): void {
        file_put_contents($this->sandbox->envPath(), "APP_ENV=local\nAPP_DEBUG=true\n");

        settingsFor($this)->persist(InstallStep::Application, ['url' => 'https://inventory.example.com', 'locale' => 'en', 'timezone' => 'UTC']);

        expect(consoleEnvFile($this))->toMatchArray(['APP_ENV' => 'local', 'APP_DEBUG' => 'true']);
    });

    it('refuses with a clear message when .env cannot be written', function (): void {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('File permissions do not restrict root.');
        }

        file_put_contents($this->sandbox->envPath(), "A=1\n");
        chmod($this->sandbox->envPath(), 0444);

        try {
            settingsFor($this)->persist(InstallStep::Application, ['url' => 'https://a.test', 'locale' => 'fr', 'timezone' => 'UTC']);
        } catch (InstallationException $exception) {
            expect($exception->userMessage())->toContain('.env')->toContain('inventaire:install --from-env');

            return;
        }

        $this->fail('Expected an InstallationException.');
    });
});
