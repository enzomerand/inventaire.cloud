<?php

declare(strict_types=1);

use App\Installer\CreateAdministrator;
use App\Installer\EnvironmentFile;
use App\Installer\EnvironmentLocks;
use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallationFinalizer;
use App\Installer\InstallationState;
use App\Installer\InstallerSettings;
use App\Installer\InstallStep;
use App\Installer\Mail\MailAttemptStore;
use App\Installer\Mail\MailVerification;
use App\Installer\Mail\SetupCodeMail;
use App\Installer\Probes\MailProbe;
use App\Installer\SetupToken;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * Persists the settings of every required step except the administrator against the test database, and records them as
 * done, like a wizard that went all the way to the last screen.
 */
function adminReadyInstance(object $test, bool $withAdministrator = true): void
{
    $test->bootInstaller(installed: false);

    $settings = app(InstallerSettings::class);
    $db = testDatabaseSettings();

    $settings->persist(InstallStep::Database, ['driver' => $db->driver, 'host' => $db->host, 'port' => $db->port, 'database' => $db->database, 'username' => $db->username, 'password' => $db->password]);
    $settings->persist(InstallStep::Application, ['url' => 'https://inventory.example.test', 'locale' => 'fr', 'timezone' => 'Europe/Paris']);
    $settings->persist(InstallStep::Mail, ['host' => 'smtp.example.test', 'port' => '587', 'scheme' => '', 'username' => '', 'password' => '', 'from_address' => 'hello@example.test', 'from_name' => 'My Shop']);
    $settings->persist(InstallStep::Storage, ['disk' => 'local']);

    if ($withAdministrator) {
        $settings->persist(InstallStep::Administrator, ['name' => 'Jane Doe', 'email' => 'Jane@Example.test']);
    }

    foreach (InstallStep::required() as $step) {
        app(InstallationState::class)->record($step);
    }

    app(SetupToken::class)->ensure();
}

/**
 * Leaves the application's default connection pointing at a database that does not exist, as it is in a web request
 * (or an interactive console run) whose configuration was loaded before the wizard wrote the database settings.
 *
 * @return string The name of the real connection, the one to read the result from.
 */
function adminStaleDefaultConnection(): string
{
    $real = config()->string('database.default');

    config(['database.connections.stale' => array_merge(config()->array("database.connections.{$real}"), ['database' => 'inventaire_does_not_exist'])]);
    app('db')->setDefaultConnection('stale');

    return $real;
}

/**
 * The administrator the installation created, read from the database being installed.
 *
 * @return list<object>
 */
function adminRows(string $connection): array
{
    return DB::connection($connection)->table('users')->get()->all();
}

/**
 * Whether the connection the installer borrows is gone and the default one is back where it was.
 */
function adminConnectionsRestored(): bool
{
    return config('database.connections.installer_migrate') === null
        && ! array_key_exists('installer_migrate', app('db')->getConnections())
        && config('database.default') === 'stale';
}

/**
 * Runs the mail step the way the page does: the form, then the code typed back. Nothing reloads in between.
 *
 * @return TestResponse The answer to the code, the last request the page sends before showing the administrator step.
 */
function adminVerifyMail(object $test, string $recipient = 'Admin@Example.test'): TestResponse
{
    Mail::fake();

    // Sending a code proves nothing yet: the page learns nothing about a suggestion.
    $test->postJson('/install/mail', ['host' => 'smtp.example.test', 'port' => 587, 'scheme' => '', 'username' => '', 'password' => '', 'from_address' => 'hello@example.test', 'from_name' => 'My Shop', 'test_recipient' => $recipient])
        ->assertOk()
        ->assertJsonMissingPath('suggestions');

    return $test->postJson('/install/mail/verify', ['code' => Mail::sent(SetupCodeMail::class)->last()->code]);
}

describe('the administrator step', function (): void {
    beforeEach(function (): void {
        $this->bootInstaller(installed: false);
        $this->get('/'); // the first access creates the setup token
        $this->postJson('/install/token', ['token' => app(SetupToken::class)->current()])->assertOk();
    });

    it('saves the name and the address to .env and is recorded as done', function (): void {
        $this->postJson('/install/administrator', ['name' => 'Jane Doe', 'email' => 'jane@example.test'])
            ->assertOk()
            ->assertJsonPath('statuses.administrator', 'done');

        expect((new EnvironmentFile($this->sandbox->envPath(), $this->sandbox->envPath()))->all())->toMatchArray(['ADMIN_NAME' => 'Jane Doe', 'ADMIN_EMAIL' => 'jane@example.test'])
            ->and(app(InstallationState::class)->status(InstallStep::Administrator))->toBe('done');
    });

    it('refuses a missing name or a malformed address', function (): void {
        $this->postJson('/install/administrator', ['name' => '', 'email' => 'not-an-address'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);

        expect(app(InstallationState::class)->status(InstallStep::Administrator))->toBe('pending');
    });

    it('stores the address in canonical form: lowercase, its domain in ASCII', function (): void {
        $this->postJson('/install/administrator', ['name' => 'Jane Doe', 'email' => ' Jane@Gmäil.COM '])
            ->assertOk()
            ->assertJsonPath('statuses.administrator', 'done');

        expect((new EnvironmentFile($this->sandbox->envPath(), $this->sandbox->envPath()))->all())->toMatchArray(['ADMIN_EMAIL' => 'jane@xn--gmil-moa.com']);
    });

    it('refuses an accent in the local part of the address', function (): void {
        $this->postJson('/install/administrator', ['name' => 'Jane Doe', 'email' => 'jöhn@example.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        expect(app(InstallationState::class)->status(InstallStep::Administrator))->toBe('pending');
    });

    it('suggests the address that read the verification code back at the mail step', function (): void {
        Mail::fake();
        $this->postJson('/install/mail', ['host' => 'smtp.example.test', 'port' => 587, 'scheme' => '', 'username' => '', 'password' => '', 'from_address' => 'hello@example.test', 'from_name' => 'My Shop', 'test_recipient' => 'Admin@Example.test'])->assertOk();

        $administratorEmail = function (): string {
            preg_match('#id="installer-state">(.*?)</script>#s', $this->get('/install')->getContent(), $match);
            $step = collect(json_decode($match[1], true)['steps'])->firstWhere('id', 'administrator');

            return collect($step['fields'])->firstWhere('name', 'email')['value'];
        };

        // Not before the code was typed back: sending a code proves nothing.
        expect($administratorEmail())->toBe('');

        $this->postJson('/install/mail/verify', ['code' => Mail::sent(SetupCodeMail::class)->last()->code])->assertOk();

        expect($administratorEmail())->toBe('admin@example.test');

        // It is only a suggestion: what the administrator saves wins.
        $this->postJson('/install/administrator', ['name' => 'Jane Doe', 'email' => 'jane@example.test'])->assertOk();

        expect($administratorEmail())->toBe('jane@example.test');
    });

    it('hands the verified address over in the answer to the code, so the page shows it without a reload', function (): void {
        adminVerifyMail($this)->assertOk()
            ->assertJsonPath('statuses.mail', 'done')
            ->assertJsonPath('suggestions', ['administrator' => ['email' => 'admin@example.test']]);
    });

    it('says nothing about an address while the code is wrong', function (): void {
        Mail::fake();
        $this->postJson('/install/mail', ['host' => 'smtp.example.test', 'port' => 587, 'scheme' => '', 'username' => '', 'password' => '', 'from_address' => 'hello@example.test', 'from_name' => 'My Shop', 'test_recipient' => 'admin@example.test'])->assertOk();
        $wrong = Mail::sent(SetupCodeMail::class)->last()->code === '000000' ? '111111' : '000000';

        $this->postJson('/install/mail/verify', ['code' => $wrong])->assertUnprocessable()->assertJsonMissingPath('suggestions');
    });

    it('suggests nothing where an address is already configured', function (): void {
        $this->postJson('/install/administrator', ['name' => 'Jane Doe', 'email' => 'jane@example.test'])->assertOk();

        adminVerifyMail($this)->assertOk()->assertJsonPath('suggestions', []);
    });

    it('suggests nothing where the environment imposes the address, even an empty one', function (?string $imposed): void {
        $this->processEnvironment = ['ADMIN_EMAIL' => $imposed ?? 'imposed@example.test'];
        $this->app->forgetInstance(EnvironmentLocks::class);
        $this->app->forgetInstance(InstallerSettings::class);

        adminVerifyMail($this)->assertOk()->assertJsonPath('suggestions', []);
    })->with([
        'empty' => [''],
        'filled' => [null],
    ]);

    it('is described to the page like every step, and cannot be skipped', function (): void {
        preg_match('#id="installer-state">(.*?)</script>#s', $this->get('/install')->getContent(), $match);
        $step = collect(json_decode($match[1], true)['steps'])->firstWhere('id', 'administrator');

        expect($step['optional'])->toBeFalse()
            ->and(array_column($step['fields'], 'name'))->toBe(['name', 'email'])
            ->and(array_column($step['fields'], 'type'))->toBe(['text', 'email']);

        $this->postJson('/install/administrator/skip')->assertNotFound();
    });

    it('is locked by values the environment imposes', function (): void {
        $this->processEnvironment = ['ADMIN_NAME' => 'Imposed Name', 'ADMIN_EMAIL' => 'imposed@example.test'];
        $this->app->forgetInstance(EnvironmentLocks::class);
        $this->app->forgetInstance(InstallerSettings::class);

        $this->postJson('/install/administrator', ['name' => 'Someone Else', 'email' => 'other@example.test'])->assertOk();

        expect(app(InstallerSettings::class)->current(InstallStep::Administrator))->toBe(['name' => 'Imposed Name', 'email' => 'imposed@example.test']);
    });
});

describe('the environment-only installation', function (): void {
    it('needs the administrator among the variables', function (): void {
        $this->bootInstaller(installed: false);
        $settings = fn (array $environment) => tap(app(), function ($app) use ($environment): void {
            $this->processEnvironment = $environment;
            $app->forgetInstance(EnvironmentLocks::class);
            $app->forgetInstance(InstallerSettings::class);
        })->make(InstallerSettings::class);

        expect($settings(containerEnvironment())->isEnvironmentOnly())->toBeTrue()
            ->and($settings(array_diff_key(containerEnvironment(), ['ADMIN_NAME' => '']))->isEnvironmentOnly())->toBeFalse()
            ->and($settings(array_diff_key(containerEnvironment(), ['ADMIN_EMAIL' => '']))->isEnvironmentOnly())->toBeFalse();
    });
});

describe('the hook', function (): void {
    beforeEach(function (): void {
        $this->bootInstaller(installed: false);
        Artisan::call('db:wipe', ['--force' => true]);
        Artisan::call('migrate', ['--force' => true]);
    });

    it('creates the administrator from the step, with the address in lowercase', function (): void {
        app(InstallerSettings::class)->persist(InstallStep::Administrator, ['name' => ' Jane Doe ', 'email' => 'Jane@Example.test']);

        app(CreateAdministrator::class)->run();

        $user = User::query()->sole();

        expect($user->name)->toBe('Jane Doe')
            ->and($user->email)->toBe('jane@example.test')
            ->and($user->is_admin)->toBeTrue()
            ->and($user->email_verified_at)->toBeNull()
            // Like every account, the administrator starts with a personal workspace that they own.
            ->and($user->workspaces)->toHaveCount(1)
            ->and($user->workspaces->sole()->pivot->role)->toBe(WorkspaceRole::Owner)
            ->and($user->current_workspace_id)->toBe($user->workspaces->sole()->id);
    });

    it('is idempotent: running it again changes nothing', function (): void {
        app(InstallerSettings::class)->persist(InstallStep::Administrator, ['name' => 'Jane Doe', 'email' => 'jane@example.test']);

        app(CreateAdministrator::class)->run();
        $first = User::query()->sole();
        app(CreateAdministrator::class)->run();

        expect(User::query()->count())->toBe(1)
            ->and(User::query()->sole()->updated_at->equalTo($first->updated_at))->toBeTrue()
            ->and(Workspace::query()->count())->toBe(1);
    });

    it('promotes the account that already has the address, and keeps its name', function (): void {
        $existing = User::factory()->create(['name' => 'Already Here', 'email' => 'jane@example.test']);
        app(InstallerSettings::class)->persist(InstallStep::Administrator, ['name' => 'Jane Doe', 'email' => 'jane@example.test']);

        app(CreateAdministrator::class)->run();

        expect(User::query()->count())->toBe(1)
            ->and($existing->fresh()->is_admin)->toBeTrue()
            ->and($existing->fresh()->name)->toBe('Already Here')
            // An account that predates workspaces is given its personal one on the way.
            ->and($existing->fresh()->workspaces)->toHaveCount(1);
    });

    it('aborts the installation when the step has no usable values', function (): void {
        expect(fn () => app(CreateAdministrator::class)->run())->toThrow(InstallationException::class, 'administrator cannot be created');

        app(InstallerSettings::class)->persist(InstallStep::Administrator, ['name' => 'Jane', 'email' => 'not-an-address']);

        expect(fn () => app(CreateAdministrator::class)->run())->toThrow(InstallationException::class, 'administrator cannot be created');
        expect(User::query()->count())->toBe(0);
    });
});

describe('the installation, on the database being installed', function (): void {
    it('creates the administrator when the web wizard finalizes, whatever the default connection', function (): void {
        adminReadyInstance($this);
        Artisan::call('db:wipe', ['--force' => true]);
        $real = adminStaleDefaultConnection();

        $this->get('/'); // the web path: a request, and its own session
        $this->postJson('/install/token', ['token' => app(SetupToken::class)->current()])->assertOk();
        $this->postJson('/install/finalize')->assertOk();

        $rows = adminRows($real);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->email)->toBe('jane@example.test')
            ->and($rows[0]->name)->toBe('Jane Doe')
            ->and((bool) $rows[0]->is_admin)->toBeTrue()
            ->and(app(InstallationState::class)->isInstalled())->toBeTrue()
            ->and(adminConnectionsRestored())->toBeTrue();
    });

    it('creates it when the finalizer is used directly, and puts the connections back', function (): void {
        adminReadyInstance($this);
        Artisan::call('db:wipe', ['--force' => true]);
        $real = adminStaleDefaultConnection();

        app(InstallationFinalizer::class)->finalize();

        expect(adminRows($real))->toHaveCount(1)->and(adminConnectionsRestored())->toBeTrue();
    });

    it('puts the connections back when the hook aborts, and can be run again', function (): void {
        adminReadyInstance($this, withAdministrator: false);
        Artisan::call('db:wipe', ['--force' => true]);
        $real = adminStaleDefaultConnection();

        expect(fn () => app(InstallationFinalizer::class)->finalize())->toThrow(InstallationException::class, 'administrator cannot be created');
        expect(app(InstallationState::class)->isInstalled())->toBeFalse()->and(adminConnectionsRestored())->toBeTrue();

        app(InstallerSettings::class)->persist(InstallStep::Administrator, ['name' => 'Jane Doe', 'email' => 'jane@example.test']);
        app(InstallationFinalizer::class)->finalize();

        expect(adminRows($real))->toHaveCount(1)->and(app(InstallationState::class)->isInstalled())->toBeTrue();
    });

    it('creates it on a non-interactive console installation', function (): void {
        $this->bootInstaller(installed: false);
        $db = testDatabaseSettings();
        Artisan::call('db:wipe', ['--force' => true]);
        $real = adminStaleDefaultConnection();

        $this->artisan('inventaire:install', [
            '--no-interaction' => true, '--skip-mail-test' => true,
            '--db-driver' => $db->driver, '--db-host' => $db->host, '--db-port' => (string) $db->port,
            '--db-name' => $db->database, '--db-user' => $db->username, '--db-password' => $db->password,
            '--app-url' => 'https://inventory.example.test', '--locale' => 'en', '--timezone' => 'Europe/Paris',
            '--mail-host' => 'smtp.example.test', '--mail-port' => '587',
            '--mail-from-address' => 'hello@example.test', '--mail-from-name' => 'My Shop',
            '--storage' => 'local', '--admin-name' => 'Jane Doe', '--admin-email' => 'JANE@example.test',
        ])->assertSuccessful();

        $rows = adminRows($real);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->email)->toBe('jane@example.test')
            ->and((bool) $rows[0]->is_admin)->toBeTrue()
            ->and(consoleAdminEnv($this))->toMatchArray(['ADMIN_NAME' => 'Jane Doe', 'ADMIN_EMAIL' => 'jane@example.test']);
    });

    it('stops a non-interactive console installation that has no administrator', function (): void {
        $this->bootInstaller(installed: false);
        $db = testDatabaseSettings();

        $this->artisan('inventaire:install', [
            '--no-interaction' => true, '--skip-mail-test' => true,
            '--db-driver' => $db->driver, '--db-host' => $db->host, '--db-port' => (string) $db->port,
            '--db-name' => $db->database, '--db-user' => $db->username, '--db-password' => $db->password,
            '--app-url' => 'https://inventory.example.test', '--locale' => 'en', '--timezone' => 'Europe/Paris',
            '--mail-host' => 'smtp.example.test', '--mail-port' => '587',
            '--mail-from-address' => 'hello@example.test', '--mail-from-name' => 'My Shop',
            '--storage' => 'local',
        ])->expectsOutputToContain('name')->assertFailed();

        expect(app(InstallationState::class)->isInstalled())->toBeFalse()
            ->and(app(InstallationState::class)->status(InstallStep::Administrator))->toBe('pending');
    });

    it('creates it on an interactive console installation', function (): void {
        $this->bootInstaller(installed: false);
        $db = testDatabaseSettings();
        Mail::fake();
        Artisan::call('db:wipe', ['--force' => true]);
        $real = adminStaleDefaultConnection();
        $this->app->singleton(MailVerification::class, fn ($app): MailVerification => new MailVerification($app->make(MailProbe::class), $app->make('config'), $app->make(MailAttemptStore::class), fn (): string => '246810'));

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
            ->expectsQuestion('Name', 'Jane Doe')
            ->expectsQuestion('Email address', 'jane@example.test')
            ->expectsConfirmation('Switch this instance to production mode (APP_ENV=production, APP_DEBUG=false)?', 'no')
            ->expectsOutputToContain('Installation complete')
            ->assertSuccessful();

        $rows = adminRows($real);

        expect($rows)->toHaveCount(1)->and($rows[0]->email)->toBe('jane@example.test')->and((bool) $rows[0]->is_admin)->toBeTrue();
    });

    it('creates it with --from-env, from configuration that already exists', function (): void {
        adminReadyInstance($this);
        Mail::fake();
        Artisan::call('db:wipe', ['--force' => true]);
        $real = adminStaleDefaultConnection();
        $before = file_get_contents($this->sandbox->envPath());

        $this->artisan('inventaire:install', ['--from-env' => true])->assertSuccessful();

        $rows = adminRows($real);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->email)->toBe('jane@example.test')
            ->and(file_get_contents($this->sandbox->envPath()))->toBe($before);
    });

    it('creates it from variables alone, as a container does', function (): void {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Directory permissions do not restrict root.');
        }

        $this->bootInstaller(installed: false);
        $this->processEnvironment = containerEnvironment();
        $this->app->forgetInstance(EnvironmentLocks::class);
        Mail::fake();
        Artisan::call('db:wipe', ['--force' => true]);
        $real = adminStaleDefaultConnection();
        chmod($this->sandbox->root, 0555);

        $this->artisan('inventaire:install', ['--from-env' => true])->assertSuccessful();

        $rows = adminRows($real);

        expect($rows)->toHaveCount(1)->and($rows[0]->email)->toBe('jane@example.test')->and($rows[0]->name)->toBe('Jane Doe');
    });

    it('fails --from-env when the administrator is missing, before anything is created', function (): void {
        adminReadyInstance($this, withAdministrator: false);
        app(InstallationState::class)->forgetProgress();

        $this->artisan('inventaire:install', ['--from-env' => true])->assertFailed();

        expect(app(InstallationState::class)->isInstalled())->toBeFalse();
    });
});

/**
 * The sandbox .env, parsed.
 *
 * @return array<string, string>
 */
function consoleAdminEnv(object $test): array
{
    return (new EnvironmentFile($test->sandbox->envPath(), $test->sandbox->envPath()))->all();
}
