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

    return new InstallerSettings($file, new EnvironmentLocks(fn (): array => $process));
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

    it('stores an empty secret when the "no password" box is ticked, whatever is typed', function (?string $typed): void {
        $settings = settingsFor($this, "DB_PASSWORD=hunter2\n");

        $resolved = $settings->resolve(InstallStep::Database, ['password' => $typed, 'password_clear' => true]);

        expect($resolved['password'])->toBe('')->and($resolved['password_clear'])->toBeTrue();
    })->with(['nothing typed' => null, 'something typed' => 'typed']);

    it('does not clear a secret that the environment imposes', function (): void {
        $settings = settingsFor($this, '', ['DB_PASSWORD' => 'from-docker']);

        expect($settings->resolve(InstallStep::Database, ['password' => '', 'password_clear' => true])['password'])->toBe('from-docker');
    });

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
        file_put_contents($this->sandbox->envPath(), "MAIL_SCHEME=smtps\nMAIL_USERNAME=someone\nMAIL_PASSWORD=old-secret\n");

        settingsFor($this)->persist(InstallStep::Mail, ['host' => 'h', 'port' => '25', 'scheme' => '', 'username' => '', 'password' => '', 'from_address' => 'a@b.c', 'from_name' => 'n']);

        $contents = file_get_contents($this->sandbox->envPath());

        expect($contents)->toContain("MAIL_SCHEME=null\n")->toContain("MAIL_USERNAME=null\n")->toContain("MAIL_PASSWORD=\n");
    });

    it('writes only what changes, so an unchanged configuration needs no .env at all', function (): void {
        file_put_contents($this->sandbox->envPath(), "APP_URL=https://a.test\nAPP_LOCALE=fr\nAPP_TIMEZONE=UTC\n");
        $before = file_get_contents($this->sandbox->envPath());
        chmod($this->sandbox->envPath(), 0444);

        // Read-only file, nothing to change: no error, no write.
        settingsFor($this)->persist(InstallStep::Application, ['url' => 'https://a.test', 'locale' => 'fr', 'timezone' => 'UTC']);

        expect(file_get_contents($this->sandbox->envPath()))->toBe($before);
    });

    it('does not even create .env when nothing has to be stored', function (): void {
        $settings = settingsFor($this, '', ['APP_URL' => 'https://a.test', 'APP_LOCALE' => 'fr', 'APP_TIMEZONE' => 'UTC']);

        $settings->persist(InstallStep::Application, ['url' => 'tampered', 'locale' => 'en', 'timezone' => 'Asia/Tokyo']);

        expect(is_file($this->sandbox->envPath()))->toBeFalse();
    });

    it('writes checkboxes as true or false', function (): void {
        settingsFor($this)->persist(InstallStep::Storage, ['disk' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'r', 'bucket' => 'b', 'endpoint' => '', 'path_style' => true]);

        expect(file_get_contents($this->sandbox->envPath()))->toContain("AWS_USE_PATH_STYLE_ENDPOINT=true\n")
            ->and(consoleEnvFile($this)['AWS_ENDPOINT'])->toBe('');
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

    it('does not pre-fill the wizard with the development credentials of the example', function (): void {
        $settings = settingsFor($this);
        $settings->persist(InstallStep::Application, ['url' => 'https://inventory.example.com', 'locale' => 'en', 'timezone' => 'UTC']);

        $database = $settings->current(InstallStep::Database);
        $mail = $settings->current(InstallStep::Mail);
        $fields = collect(InstallStep::Database->fields())->keyBy('name');

        expect($database)->toMatchArray(['username' => '', 'password' => '', 'port' => '5432'])
            ->and($settings->hasValue($fields['password']))->toBeFalse()
            ->and($mail)->toMatchArray(['host' => '', 'username' => '', 'password' => '', 'from_address' => '']);
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

describe('a configuration that lives entirely in the environment', function (): void {
    it('is recognised, so that nothing has to be written', function (): void {
        expect(settingsFor($this)->isEnvironmentOnly())->toBeFalse()
            ->and(settingsFor($this, '', containerEnvironment())->isEnvironmentOnly())->toBeTrue();
    });

    it('is not recognised as soon as one required setting is missing', function (string $missing): void {
        $environment = containerEnvironment();
        unset($environment[$missing]);

        expect(settingsFor($this, '', $environment)->isEnvironmentOnly())->toBeFalse();
    })->with(['DB_HOST', 'APP_URL', 'MAIL_HOST', 'MAIL_FROM_ADDRESS', 'FILESYSTEM_DISK', 'MAIL_MAILER']);

    it('does not need the S3 settings for local storage but needs them for S3', function (): void {
        expect(settingsFor($this, '', containerEnvironment())->isEnvironmentOnly())->toBeTrue()
            ->and(settingsFor($this, '', ['FILESYSTEM_DISK' => 's3'] + containerEnvironment())->isEnvironmentOnly())->toBeFalse();
    });

    it('counts a connection URL as providing the database', function (): void {
        $environment = array_diff_key(containerEnvironment(), array_flip(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']));

        expect(settingsFor($this, '', $environment)->isEnvironmentOnly())->toBeFalse()
            ->and(settingsFor($this, '', $environment + ['DB_URL' => 'postgres://u:p@h/d'])->isEnvironmentOnly())->toBeTrue();
    });
});

describe('what the installers suggest', function (): void {
    it('proposes the address verified at the mail step for the administrator', function (): void {
        $settings = settingsFor($this);

        $values = $settings->withSuggestions(InstallStep::Administrator, $settings->current(InstallStep::Administrator), ' Admin@Example.TEST ');

        expect($values)->toBe(['name' => '', 'email' => 'admin@example.test']);
    });

    it('proposes nothing when no address was verified', function (): void {
        $settings = settingsFor($this);

        expect($settings->withSuggestions(InstallStep::Administrator, $settings->current(InstallStep::Administrator), null))->toBe(['name' => '', 'email' => '']);
        expect($settings->withSuggestions(InstallStep::Administrator, $settings->current(InstallStep::Administrator), ''))->toBe(['name' => '', 'email' => '']);
    });

    it('keeps an address that is configured or imposed', function (): void {
        $configured = settingsFor($this, "ADMIN_EMAIL=jane@example.test\n");

        expect($configured->withSuggestions(InstallStep::Administrator, $configured->current(InstallStep::Administrator), 'admin@example.test')['email'])->toBe('jane@example.test');

        $imposed = settingsFor($this, '', ['ADMIN_EMAIL' => 'imposed@example.test']);

        expect($imposed->withSuggestions(InstallStep::Administrator, $imposed->current(InstallStep::Administrator), 'admin@example.test')['email'])->toBe('imposed@example.test');
    });

    it('suggests nothing for the other steps', function (): void {
        $settings = settingsFor($this);
        $values = $settings->current(InstallStep::Mail);

        expect($settings->withSuggestions(InstallStep::Mail, $values, 'admin@example.test'))->toBe($values);
    });
});
