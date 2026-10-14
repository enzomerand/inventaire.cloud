<?php

declare(strict_types=1);

use App\Installer\EnvironmentFile;
use App\Installer\InstallationState;
use App\Installer\InstallStep;
use App\Installer\Mail\MailChallenge;
use App\Installer\Mail\MailChallengeStore;
use App\Installer\Mail\SetupCodeMail;
use App\Installer\Probes\ProbeResult;
use App\Installer\Probes\StorageProbe;
use App\Installer\Settings\StorageSettings;
use App\Installer\SetupToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Boots a fresh instance and unlocks the wizard for the current session.
 */
function authorizedWizard(object $test): void
{
    $test->bootInstaller(installed: false);
    $test->get('/'); // the first access creates the setup token

    $test->postJson('/install/token', ['token' => app(SetupToken::class)->current()])->assertOk();
}

/**
 * The sandbox .env, parsed.
 *
 * @return array<string, string>
 */
function sandboxEnv(object $test): array
{
    return (new EnvironmentFile($test->sandbox->envPath(), $test->sandbox->envPath()))->all();
}

/**
 * The values of the database step pointing at the test database.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function databaseForm(array $override = []): array
{
    $db = testDatabaseSettings();

    return $override + ['driver' => $db->driver, 'host' => $db->host, 'port' => $db->port, 'database' => $db->database, 'username' => $db->username, 'password' => $db->password];
}

/**
 * Valid values of the mail step.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function mailForm(array $override = []): array
{
    return $override + ['host' => 'smtp.example.test', 'port' => 587, 'scheme' => '', 'username' => 'smtp-user', 'password' => 'smtp-secret', 'from_address' => 'hello@example.test', 'from_name' => 'My Shop', 'test_recipient' => 'admin@example.test'];
}

/**
 * Replaces the storage probe with one that always answers the same.
 */
function fakeStorageProbe(object $test, ProbeResult $result): void
{
    app()->instance(StorageProbe::class, new class($result) implements StorageProbe
    {
        public function __construct(private readonly ProbeResult $result) {}

        public function check(StorageSettings $settings): ProbeResult
        {
            return $this->result;
        }
    });
}

describe('the wizard page', function (): void {
    it('shows the token form, and nothing of the configuration, to a visitor without the token', function (): void {
        $this->bootInstaller(installed: false);

        $this->get('/install')->assertOk()
            ->assertSee('x-data="installerToken"', false)
            ->assertDontSee('installer-state', false)
            ->assertDontSee('x-data="installer"', false);
    });

    it('embeds the described wizard once the token was entered', function (): void {
        authorizedWizard($this);

        $page = $this->get('/install')->assertOk()->assertSee('x-data="installer"', false)->assertSee('data-base-url="/install"', false);
        preg_match('#<script type="application/json" id="installer-state">(.*?)</script>#s', $page->getContent(), $match);
        $state = json_decode($match[1], true);

        expect(array_column($state['steps'], 'id'))->toBe(['requirements', 'database', 'application', 'mail', 'storage', 'documenso', 'administrator', 'finalize'])
            ->and($state['current'])->toBe('database')
            ->and($state['requirements']['passed'])->toBeTrue()
            ->and($state['canFinalize'])->toBeFalse()
            ->and($state['mailPending'])->toBeFalse();
    });

    it('suggests the address the administrator is using for the public URL', function (): void {
        authorizedWizard($this);

        $page = $this->get('http://inventory.example.test:8080/install')->assertOk();
        preg_match('#id="installer-state">(.*?)</script>#s', $page->getContent(), $match);
        $application = collect(json_decode($match[1], true)['steps'])->firstWhere('id', 'application');

        expect(collect($application['fields'])->firstWhere('name', 'url')['value'])->toBe('http://inventory.example.test:8080');
    });

    it('serves translated labels', function (): void {
        authorizedWizard($this);

        $page = $this->get('/install', ['Accept-Language' => 'fr'])->assertOk();

        $page->assertSee('Base de données', false)->assertSee('Mot de passe', false);
    });
});

describe('the step endpoints', function (): void {
    it('refuse a browser that has not entered the setup token', function (string $method, string $uri): void {
        $this->bootInstaller(installed: false);
        $this->get('/');

        $this->json($method, $uri, ['host' => 'x'])->assertForbidden()->assertJsonPath('code', 'setup_token_required');
    })->with([
        ['GET', '/install/requirements'],
        ['POST', '/install/database'],
        ['POST', '/install/application'],
        ['POST', '/install/mail'],
        ['POST', '/install/mail/verify'],
        ['POST', '/install/storage'],
        ['POST', '/install/documenso'],
        ['POST', '/install/documenso/skip'],
        ['POST', '/install/finalize'],
    ]);

    it('answer 404 once the instance is installed', function (string $method, string $uri): void {
        $this->json($method, $uri)->assertNotFound();
    })->with([
        ['GET', '/install/requirements'],
        ['POST', '/install/database'],
        ['POST', '/install/finalize'],
    ]);

    it('report the requirements', function (): void {
        authorizedWizard($this);

        $this->getJson('/install/requirements')->assertOk()->assertJsonPath('requirements.passed', true)
            ->assertJsonStructure(['requirements' => ['items' => [['id', 'label', 'ok', 'detail']]]]);
    });
});

describe('the database step', function (): void {
    beforeEach(function (): void {
        authorizedWizard($this);
    });

    it('tests the connection, writes .env and records the step', function (): void {
        $this->postJson('/install/database', databaseForm())->assertOk()
            ->assertJsonPath('statuses.database', 'done')
            ->assertJsonPath('canFinalize', false);

        $db = testDatabaseSettings();

        expect(sandboxEnv($this))->toMatchArray(['DB_CONNECTION' => $db->driver, 'DB_HOST' => $db->host, 'DB_DATABASE' => $db->database, 'DB_USERNAME' => $db->username])
            ->and(app(InstallationState::class)->status(InstallStep::Database))->toBe('done');
    });

    it('points at the field when the connection fails and writes nothing', function (): void {
        $this->postJson('/install/database', databaseForm(['password' => 'wrong-password']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');

        expect(app(InstallationState::class)->status(InstallStep::Database))->toBe('pending')
            ->and(sandboxEnv($this))->not->toHaveKey('DB_DATABASE');
    });

    it('validates the form before connecting', function (): void {
        $this->postJson('/install/database', databaseForm(['port' => 'abc', 'driver' => 'oracle']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['port', 'driver']);
    });

    it('uses what the environment imposes instead of what was posted, and never writes it', function (): void {
        // The sandbox .env starts as a copy of .env.example, whose database is "inventaire"; the test database is another one.
        $this->processEnvironment = ['DB_DATABASE' => testDatabaseSettings()->database];

        $this->postJson('/install/database', databaseForm(['database' => 'tampered']))->assertOk();

        expect(sandboxEnv($this)['DB_DATABASE'])->toBe('inventaire')->and(sandboxEnv($this)['DB_HOST'])->toBe(testDatabaseSettings()->host);
    });

    it('keeps the stored password when the box is left empty and never sends it to the browser', function (): void {
        $this->postJson('/install/database', databaseForm())->assertOk();

        $this->postJson('/install/database', databaseForm(['password' => '']))->assertOk();

        $page = $this->get('/install');
        preg_match('#id="installer-state">(.*?)</script>#s', $page->getContent(), $match);
        $database = collect(json_decode($match[1], true)['steps'])->firstWhere('id', 'database');
        $password = collect($database['fields'])->firstWhere('name', 'password');

        expect(sandboxEnv($this)['DB_PASSWORD'])->toBe(testDatabaseSettings()->password)
            ->and($password)->toMatchArray(['value' => '', 'secretSet' => true])
            ->and($page->getContent())->not->toContain('"value":"'.testDatabaseSettings()->password.'"');
    });

    it('explains an .env that cannot be written', function (): void {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('File permissions do not restrict root.');
        }

        file_put_contents($this->sandbox->envPath(), "A=1\n");
        chmod($this->sandbox->envPath(), 0444);

        $this->postJson('/install/database', databaseForm())->assertUnprocessable()->assertJsonValidationErrors('general');

        expect(app(InstallationState::class)->status(InstallStep::Database))->toBe('pending');
    });
});

describe('the application step', function (): void {
    beforeEach(function (): void {
        authorizedWizard($this);
    });

    it('saves the address, language and timezone', function (): void {
        $this->postJson('/install/application', ['url' => 'https://inventory.example.test', 'locale' => 'en', 'timezone' => 'Europe/Paris'])
            ->assertOk()->assertJsonPath('statuses.application', 'done');

        expect(sandboxEnv($this))->toMatchArray(['APP_URL' => 'https://inventory.example.test', 'APP_LOCALE' => 'en', 'APP_TIMEZONE' => 'Europe/Paris']);
    });

    it('refuses invalid values', function (): void {
        $this->postJson('/install/application', ['url' => 'nope', 'locale' => 'xx', 'timezone' => 'Mars/Base'])
            ->assertUnprocessable()->assertJsonValidationErrors(['url', 'locale', 'timezone']);
    });
});

describe('the mail step', function (): void {
    beforeEach(function (): void {
        authorizedWizard($this);
    });

    /**
     * The code that was just emailed.
     */
    $sentCode = fn () => Mail::sent(SetupCodeMail::class)->last()->code;

    it('sends a code and only saves the settings once it is typed back', function () use ($sentCode): void {
        Mail::fake();
        $this->postJson('/install/mail', mailForm())->assertOk()->assertJsonPath('mailPending', true)->assertJsonPath('statuses.mail', 'pending');

        Mail::assertSent(SetupCodeMail::class, fn (SetupCodeMail $mail): bool => $mail->hasTo('admin@example.test') && $mail->hasFrom('hello@example.test', 'My Shop'));
        expect(sandboxEnv($this))->not->toHaveKey('MAIL_HOST');

        $this->postJson('/install/mail/verify', ['code' => $sentCode()])->assertOk()->assertJsonPath('statuses.mail', 'done')->assertJsonPath('mailPending', false);

        expect(sandboxEnv($this))->toMatchArray(['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'smtp.example.test', 'MAIL_PASSWORD' => 'smtp-secret', 'MAIL_FROM_NAME' => 'My Shop'])
            ->and(file_get_contents($this->sandbox->envPath()))->not->toContain('admin@example.test');
    });

    it('keeps the pending values encrypted in the session', function (): void {
        Mail::fake();
        $this->postJson('/install/mail', mailForm())->assertOk();

        $stored = session('installer.mail');

        expect($stored)->toBeString()->not->toContain('smtp-secret')->not->toContain('smtp.example.test');
    });

    it('resumes on the code box after a reload', function (): void {
        Mail::fake();
        $this->postJson('/install/mail', mailForm())->assertOk();

        $page = $this->get('/install');
        preg_match('#id="installer-state">(.*?)</script>#s', $page->getContent(), $match);

        expect(json_decode($match[1], true)['mailPending'])->toBeTrue();
    });

    it('counts down wrong codes', function () use ($sentCode): void {
        Mail::fake();
        $this->postJson('/install/mail', mailForm())->assertOk();
        $wrong = $sentCode() === '000000' ? '111111' : '000000';

        $this->postJson('/install/mail/verify', ['code' => $wrong])->assertUnprocessable()
            ->assertJsonPath('code', 'mail_code_wrong')
            ->assertJsonPath('errors.code.0', 'Incorrect code. 4 attempts left.');
        $this->postJson('/install/mail/verify', ['code' => $wrong])->assertJsonPath('errors.code.0', 'Incorrect code. 3 attempts left.');

        // The right code still works after a few mistakes.
        $this->postJson('/install/mail/verify', ['code' => $sentCode()])->assertOk();
    });

    it('burns the code after five wrong attempts', function () use ($sentCode): void {
        Mail::fake();
        $this->postJson('/install/mail', mailForm())->assertOk();
        $code = $sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 4) as $attempt) {
            $this->postJson('/install/mail/verify', ['code' => $wrong])->assertJsonPath('code', 'mail_code_wrong');
        }

        $this->postJson('/install/mail/verify', ['code' => $wrong])->assertUnprocessable()->assertJsonPath('code', 'mail_code_exhausted');
        $this->postJson('/install/mail/verify', ['code' => $code])->assertUnprocessable()->assertJsonPath('code', 'mail_code_missing');
        expect(sandboxEnv($this))->not->toHaveKey('MAIL_HOST');
    });

    it('rejects an expired code', function (): void {
        $challenge = MailChallenge::issue(mailForm(), 'admin@example.test', '123456', config('app.key'), 15, time() - 16 * 60);
        app(MailChallengeStore::class)->put(session()->driver(), $challenge);

        $this->postJson('/install/mail/verify', ['code' => '123456'])->assertUnprocessable()->assertJsonPath('code', 'mail_code_expired');
        expect(sandboxEnv($this))->not->toHaveKey('MAIL_HOST');
    });

    it('asks for a new code when none is pending', function (): void {
        $this->postJson('/install/mail/verify', ['code' => '123456'])->assertUnprocessable()->assertJsonPath('code', 'mail_code_missing');
    });

    it('requires six digits', function (): void {
        $this->postJson('/install/mail/verify', ['code' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('code');
    });

    it('validates the form and requires a test address', function (): void {
        Mail::fake();
        $this->postJson('/install/mail', mailForm(['test_recipient' => '', 'from_address' => 'nope']))
            ->assertUnprocessable()->assertJsonValidationErrors(['test_recipient', 'from_address']);
        Mail::assertNothingSent();
    });

    it('reports a mail server that cannot be reached', function (): void {
        $this->postJson('/install/mail', mailForm(['host' => '127.0.0.1', 'port' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('host');
    });

    it('throttles the sending of codes', function (): void {
        Mail::fake();
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/install/mail', mailForm())->assertOk();
        }

        $this->postJson('/install/mail', mailForm())->assertStatus(429);
    });
});

describe('the storage step', function (): void {
    beforeEach(function (): void {
        authorizedWizard($this);
    });

    it('tests the disk and saves local storage', function (): void {
        $this->postJson('/install/storage', ['disk' => 'local'])->assertOk()->assertJsonPath('statuses.storage', 'done');

        expect(sandboxEnv($this)['FILESYSTEM_DISK'])->toBe('local');
    });

    it('saves S3-compatible storage when the probe succeeds', function (): void {
        fakeStorageProbe($this, ProbeResult::success());

        $this->postJson('/install/storage', ['disk' => 's3', 'key' => 'AKIA', 'secret' => 'shh', 'region' => 'eu-west-3', 'bucket' => 'photos', 'endpoint' => 'https://s3.example.test', 'path_style' => true])
            ->assertOk();

        expect(sandboxEnv($this))->toMatchArray([
            'FILESYSTEM_DISK' => 's3', 'AWS_ACCESS_KEY_ID' => 'AKIA', 'AWS_SECRET_ACCESS_KEY' => 'shh', 'AWS_DEFAULT_REGION' => 'eu-west-3',
            'AWS_BUCKET' => 'photos', 'AWS_ENDPOINT' => 'https://s3.example.test', 'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        ]);
    });

    it('shows the probe failure on the bucket and saves nothing', function (): void {
        fakeStorageProbe($this, ProbeResult::failure('Access denied.', 'bucket'));

        $this->postJson('/install/storage', ['disk' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'r', 'bucket' => 'b'])
            ->assertUnprocessable()->assertJsonValidationErrors('bucket');

        expect(sandboxEnv($this))->not->toHaveKey('AWS_BUCKET');
    });

    it('requires the S3 details only for S3', function (): void {
        $this->postJson('/install/storage', ['disk' => 's3'])->assertUnprocessable()->assertJsonValidationErrors(['key', 'secret', 'bucket']);
    });
});

describe('the Documenso step', function (): void {
    beforeEach(function (): void {
        authorizedWizard($this);
    });

    it('tests the token and saves the address and token', function (): void {
        Http::fake(['sign.example.test/*' => Http::response(['data' => []])]);

        $this->postJson('/install/documenso', ['url' => 'https://sign.example.test', 'api_key' => 'api_secret'])->assertOk()->assertJsonPath('statuses.documenso', 'done');

        expect(sandboxEnv($this))->toMatchArray(['DOCUMENSO_URL' => 'https://sign.example.test', 'DOCUMENSO_API_KEY' => 'api_secret']);
    });

    it('shows a refused token on the API token field', function (): void {
        Http::fake(['*' => Http::response('', 401)]);

        $this->postJson('/install/documenso', ['url' => 'https://sign.example.test', 'api_key' => 'api_bad'])
            ->assertUnprocessable()->assertJsonValidationErrors('api_key');

        expect(sandboxEnv($this))->not->toHaveKey('DOCUMENSO_API_KEY');
    });

    it('can be skipped', function (): void {
        $this->postJson('/install/documenso/skip')->assertOk()->assertJsonPath('statuses.documenso', 'skipped');

        expect(sandboxEnv($this))->not->toHaveKey('DOCUMENSO_URL');
    });
});

describe('resuming', function (): void {
    it('picks up after the last validated step, with the saved values', function (): void {
        authorizedWizard($this);
        $this->postJson('/install/database', databaseForm())->assertOk();
        $this->postJson('/install/application', ['url' => 'https://inventory.example.test', 'locale' => 'en', 'timezone' => 'UTC'])->assertOk();

        // An interruption: new process, new session, same files.
        $this->bootInstaller(installed: false);
        $this->postJson('/install/token', ['token' => app(SetupToken::class)->current()])->assertOk();

        $page = $this->get('/install');
        preg_match('#id="installer-state">(.*?)</script>#s', $page->getContent(), $match);
        $state = json_decode($match[1], true);
        $application = collect($state['steps'])->firstWhere('id', 'application');

        expect($state['current'])->toBe('mail')
            ->and(collect($state['steps'])->pluck('status', 'id')->only(['database', 'application', 'mail'])->all())->toBe(['database' => 'done', 'application' => 'done', 'mail' => 'pending'])
            ->and(collect($application['fields'])->firstWhere('name', 'url')['value'])->toBe('https://inventory.example.test');
    });

    it('flags the fields the environment imposes and masks secrets', function (): void {
        $this->processEnvironment = ['DB_HOST' => 'db.docker', 'DB_PASSWORD' => 'docker-secret'];
        authorizedWizard($this);

        $page = $this->get('/install');
        preg_match('#id="installer-state">(.*?)</script>#s', $page->getContent(), $match);
        $database = collect(json_decode($match[1], true)['steps'])->firstWhere('id', 'database');
        $fields = collect($database['fields'])->keyBy('name');

        expect($fields['host'])->toMatchArray(['locked' => true, 'value' => 'db.docker'])
            ->and($fields['password'])->toMatchArray(['locked' => true, 'secret' => true, 'secretSet' => true, 'value' => ''])
            ->and($fields['port']['locked'])->toBeFalse()
            ->and($page->getContent())->not->toContain('docker-secret');
    });
});

/**
 * Completes every required step against the test database.
 */
function completeRequiredSteps(object $test): void
{
    $test->postJson('/install/database', databaseForm())->assertOk();
    $test->postJson('/install/application', ['url' => 'https://inventory.example.test', 'locale' => 'fr', 'timezone' => 'Europe/Paris'])->assertOk();
    $test->postJson('/install/storage', ['disk' => 'local'])->assertOk();
    Mail::fake();
    $test->postJson('/install/mail', mailForm())->assertOk();
    $test->postJson('/install/mail/verify', ['code' => Mail::sent(SetupCodeMail::class)->last()->code])->assertOk();
    $test->postJson('/install/administrator', ['name' => 'Jane Doe', 'email' => 'Jane@Example.test'])->assertOk();
}

describe('a container configured only through variables', function (): void {
    it('goes through every step and installs without writing .env', function (): void {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Directory permissions do not restrict root.');
        }

        $this->processEnvironment = containerEnvironment();
        authorizedWizard($this);
        Mail::fake();
        Artisan::call('db:wipe', ['--force' => true]);
        @mkdir(config('installer.state_path'), 0750, true);
        // Nothing but the installer's own state folder is writable: no .env, nothing next to it.
        chmod($this->sandbox->root, 0555);

        $requirements = $this->getJson('/install/requirements')->assertOk()->json('requirements');
        expect($requirements['passed'])->toBeTrue();

        $this->postJson('/install/database', [])->assertOk()->assertJsonPath('statuses.database', 'done');
        $this->postJson('/install/application', [])->assertOk();
        $this->postJson('/install/storage', [])->assertOk();
        $this->postJson('/install/mail', ['test_recipient' => 'admin@example.test'])->assertOk();
        $this->postJson('/install/mail/verify', ['code' => Mail::sent(SetupCodeMail::class)->last()->code])->assertOk();
        $this->postJson('/install/administrator', [])->assertOk();
        $this->postJson('/install/finalize')->assertOk();

        expect(app(InstallationState::class)->isInstalled())->toBeTrue()->and(is_file($this->sandbox->envPath()))->toBeFalse();
    });

    it('finalizes without writing a read-only .env that still holds development defaults', function (): void {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('File permissions do not restrict root.');
        }

        // An image built from .env.example (APP_ENV=local, debug on) whose .env is mounted read-only, with the
        // settings that matter imposed by the container: the production defaults cannot, and need not, be written.
        $this->processEnvironment = array_diff_key(containerEnvironment(), ['APP_ENV' => true, 'APP_DEBUG' => true]);
        authorizedWizard($this);
        Mail::fake();
        Artisan::call('db:wipe', ['--force' => true]);
        file_put_contents($this->sandbox->envPath(), "APP_ENV=local\nAPP_DEBUG=true\n");
        chmod($this->sandbox->envPath(), 0444);

        try {
            expect($this->getJson('/install/requirements')->assertOk()->json('requirements.passed'))->toBeTrue();

            $this->postJson('/install/database', [])->assertOk();
            $this->postJson('/install/application', [])->assertOk();
            $this->postJson('/install/storage', [])->assertOk();
            $this->postJson('/install/mail', ['test_recipient' => 'admin@example.test'])->assertOk();
            $this->postJson('/install/mail/verify', ['code' => Mail::sent(SetupCodeMail::class)->last()->code])->assertOk();
            $this->postJson('/install/administrator', [])->assertOk();
            $this->postJson('/install/finalize')->assertOk();

            expect(app(InstallationState::class)->isInstalled())->toBeTrue()
                ->and(file_get_contents($this->sandbox->envPath()))->toBe("APP_ENV=local\nAPP_DEBUG=true\n");
        } finally {
            chmod($this->sandbox->envPath(), 0644);
        }
    });
});

describe('finishing', function (): void {
    it('refuses while a required step is missing', function (): void {
        authorizedWizard($this);

        $this->postJson('/install/finalize')->assertUnprocessable()->assertJsonValidationErrors('general');
        expect(app(InstallationState::class)->isInstalled())->toBeFalse();
    });

    it('installs, then turns the wizard off for good', function (): void {
        authorizedWizard($this);
        completeRequiredSteps($this);
        Artisan::call('db:wipe', ['--force' => true]);

        $this->postJson('/install/finalize')->assertOk()->assertJson(['redirect' => '/login'])->assertJsonPath('redirect', '/login');

        expect(Schema::hasTable('users'))->toBeTrue()
            ->and(app(InstallationState::class)->isInstalled())->toBeTrue()
            ->and(is_file(app(SetupToken::class)->path()))->toBeFalse();

        // Every install route is gone, and the application serves its pages.
        $this->get('/install')->assertNotFound();
        $this->postJson('/install/finalize')->assertNotFound();
        $this->postJson('/install/database', databaseForm())->assertNotFound();
        $this->get('/')->assertOk();
    });

    it('shows the reason when the migrations fail and can be retried', function (): void {
        authorizedWizard($this);
        completeRequiredSteps($this);
        app(EnvironmentFile::class)->set(['DB_PASSWORD' => 'now-wrong']);

        $this->postJson('/install/finalize')->assertUnprocessable()->assertJsonValidationErrors('general');

        expect(app(InstallationState::class)->isInstalled())->toBeFalse();

        app(EnvironmentFile::class)->set(['DB_PASSWORD' => testDatabaseSettings()->password]);
        $this->postJson('/install/finalize')->assertOk();
    });
});

describe('the "no password" box', function (): void {
    it('is offered next to optional secrets only', function (): void {
        authorizedWizard($this);

        preg_match('#id="installer-state">(.*?)</script>#s', $this->get('/install')->getContent(), $match);
        $steps = collect(json_decode($match[1], true)['steps'])->keyBy('id');

        expect(collect($steps['database']['fields'])->pluck('name')->all())->toContain('password_clear')
            ->and(collect($steps['mail']['fields'])->pluck('name')->all())->toContain('password_clear')
            ->and(collect($steps['documenso']['fields'])->pluck('name')->all())->not->toContain('api_key_clear')
            ->and(collect($steps['storage']['fields'])->pluck('name')->all())->not->toContain('secret_clear');
    });

    it('stores an empty password on purpose, which an empty box never does', function (): void {
        authorizedWizard($this);
        app(EnvironmentFile::class)->set(['MAIL_PASSWORD' => 'old-secret']);
        Mail::fake();

        $this->postJson('/install/mail', mailForm(['password' => '']))->assertOk();
        $this->postJson('/install/mail/verify', ['code' => Mail::sent(SetupCodeMail::class)->last()->code])->assertOk();
        expect(sandboxEnv($this)['MAIL_PASSWORD'])->toBe('old-secret');

        $this->postJson('/install/mail', mailForm(['password' => '', 'password_clear' => true]))->assertOk();
        $this->postJson('/install/mail/verify', ['code' => Mail::sent(SetupCodeMail::class)->last()->code])->assertOk();
        expect(sandboxEnv($this)['MAIL_PASSWORD'])->toBe('');
    });
});
