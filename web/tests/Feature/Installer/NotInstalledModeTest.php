<?php

declare(strict_types=1);

use App\Http\Middleware\AuthorizeSetupToken;
use App\Installer\SetupToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

describe('on an installed instance', function (): void {
    it('answers 404 on every install route', function (string $method, string $uri): void {
        $this->call($method, $uri)->assertNotFound();
        $this->json($method, $uri)->assertNotFound();
    })->with([
        ['GET', '/install'],
        ['POST', '/install/token'],
        ['GET', '/install/anything'],
        ['POST', '/install/anything'],
    ]);

    it('serves the home page and does not touch the runtime configuration', function (): void {
        $this->get('/')->assertOk();

        expect(config('session.driver'))->toBe('array')->and(config('queue.default'))->toBe('sync');
    });
});

describe('on a fresh instance', function (): void {
    beforeEach(function (): void {
        $this->bootInstaller(installed: false);
    });

    it('redirects every web URL to the wizard', function (string $uri): void {
        $this->get($uri)->assertRedirect(route('install.show'));
    })->with(['/', '/some/page', '/no-such-page', '/storage/x']);

    it('redirects other methods too', function (): void {
        $this->post('/anything')->assertRedirect(route('install.show'));
        $this->put('/anything')->assertRedirect(route('install.show'));
    });

    it('answers JSON clients with 503 and a machine-readable code', function (): void {
        $this->getJson('/')
            ->assertStatus(503)
            ->assertJsonPath('code', 'not_installed')
            ->assertJsonPath('install_url', route('install.show'));

        $this->postJson('/api/whatever')->assertStatus(503)->assertJsonPath('code', 'not_installed');
    });

    it('keeps serving the wizard, the health check and what the wizard page calls', function (string $uri): void {
        expect($this->get($uri)->status())->not->toBe(302)->not->toBe(503);
    })->with(['/install', '/up', '/csrf-token']);

    it('lets the wizard page switch the language', function (): void {
        $this->putJson('/locale', ['locale' => 'en'])->assertSuccessful();
    });

    it('runs on files and inline jobs instead of the database', function (): void {
        expect(config('session.driver'))->toBe('file')
            ->and(config('cache.default'))->toBe('file')
            ->and(config('queue.default'))->toBe('sync');
    });

    it('renders the token form', function (): void {
        $this->get('/install')->assertOk()->assertSee('x-data="installerToken"', false)->assertSee('inventaire:install-token');
    });

    it('writes the setup token to the log on the first web access only', function (): void {
        Log::spy();

        $this->get('/');
        $token = app(SetupToken::class)->current();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, $token));

        $this->get('/');

        Log::shouldHaveReceived('warning')->once();
        expect(fileperms(app(SetupToken::class)->path()) & 0777)->toBe(0640);
    });

    it('does not create the token for the health check', function (): void {
        $this->get('/up');

        expect(is_file(app(SetupToken::class)->path()))->toBeFalse();
    });
});

describe('the setup token form', function (): void {
    beforeEach(function (): void {
        $this->bootInstaller(installed: false);
        $this->get('/'); // first access creates the token
        $this->token = app(SetupToken::class)->current();

        Route::middleware(['web', AuthorizeSetupToken::class])->get('/install/guarded', fn (): string => 'guarded');
    });

    it('refuses a wrong token with a field error', function (): void {
        $this->postJson('/install/token', ['token' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->getJson('/install/guarded')->assertForbidden()->assertJsonPath('code', 'setup_token_required');
    });

    it('requires a token', function (): void {
        $this->postJson('/install/token', [])->assertUnprocessable()->assertJsonValidationErrors('token');
    });

    it('authorizes the session with the right token and regenerates it', function (): void {
        $this->getJson('/install/guarded')->assertForbidden();
        $before = session()->getId();

        $this->postJson('/install/token', ['token' => $this->token])->assertOk()->assertJson(['authorized' => true]);

        expect(session()->getId())->not->toBe($before);
        $this->getJson('/install/guarded')->assertOk();
        $this->get('/install')->assertOk()->assertDontSee('x-data="installerToken"', false);
    });

    it('throttles to five attempts per minute and per address', function (): void {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/install/token', ['token' => 'wrong'.$attempt])->assertUnprocessable();
        }

        // Even the right token is refused once the limit is reached.
        $this->postJson('/install/token', ['token' => $this->token])->assertStatus(429);
        $this->getJson('/install/guarded')->assertForbidden();
    });

    it('counts attempts per address', function (): void {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/install/token', ['token' => 'wrong'.$attempt])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/install/token', ['token' => 'wrong'])
            ->assertUnprocessable();
    });

    it('stops working once the token is deleted', function (): void {
        $this->postJson('/install/token', ['token' => $this->token])->assertOk();
        app(SetupToken::class)->forget();

        $this->getJson('/install/guarded')->assertForbidden();
    });
});

describe('the application key', function (): void {
    it('is generated on the first web access and written to a new .env', function (): void {
        $this->bootInstaller(installed: false, appKey: false);

        $key = config('app.key');
        $env = file_get_contents($this->sandbox->envPath());

        expect($key)->toStartWith('base64:')->and(strlen(base64_decode(substr($key, 7))))->toBe(32)
            ->and($env)->toContain('APP_KEY='.$key)
            // A file created on a server must not inherit the example's development defaults.
            ->and($env)->toContain('APP_ENV=production')->toContain('APP_DEBUG=false');
        expect(app('encrypter')->decryptString(app('encrypter')->encryptString('works')))->toBe('works');
    });

    it('reuses the key already stored in .env instead of generating another', function (): void {
        file_put_contents(sandbox_env($this), "APP_NAME=x\nAPP_KEY=base64:c3RvcmVkLWtleS1zdG9yZWQta2V5LXN0b3JlZC1rZXk=\n");

        $this->bootInstaller(installed: false, appKey: false);

        expect(config('app.key'))->toBe('base64:c3RvcmVkLWtleS1zdG9yZWQta2V5LXN0b3JlZC1rZXk=');
    });

    it('keeps the environment of an .env that already exists', function (): void {
        file_put_contents(sandbox_env($this), "APP_ENV=local\nAPP_DEBUG=true\nAPP_KEY=\n");

        $this->bootInstaller(installed: false, appKey: false);

        $env = file_get_contents($this->sandbox->envPath());
        expect($env)->toContain('APP_ENV=local')->toContain('APP_DEBUG=true')->and(config('app.key'))->toStartWith('base64:');
    });

    it('is left alone when one is configured', function (): void {
        $this->bootInstaller(installed: false);

        expect(is_file($this->sandbox->envPath()))->toBeFalse();
    });

    it('is not generated once installed', function (): void {
        $this->bootInstaller(installed: true, appKey: false);

        expect(config('app.key'))->toBe('')->and(is_file($this->sandbox->envPath()))->toBeFalse();
    });

    it('shows a generic static page when the key cannot be stored, and logs the details', function (): void {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Directory permissions do not restrict root.');
        }

        chmod($this->sandbox->root, 0555);
        $this->bootInstaller(installed: false, appKey: false);

        // The sandbox log lives in the directory made read-only: use a writable one for this test.
        $log = sys_get_temp_dir().'/inventaire-installer-log-'.bin2hex(random_bytes(4));
        config(['logging.channels.installer.path' => $log]);

        $page = $this->get('/anything');

        $page->assertStatus(500)->assertSee('<!doctype html>', false)->assertDontSee('<script', false)
            ->assertSee('storage/', false)
            // Visitors learn nothing about the server: no path, no exception message.
            ->assertDontSee($this->sandbox->root, false)
            ->assertDontSee('Cannot create', false)
            ->assertDontSee('Cannot write', false);
        $this->getJson('/')->assertStatus(500);

        expect(file_get_contents($log))->toContain('installer cannot start')->toContain('application key is missing');
        @unlink($log);
    });
});

/**
 * Path of the sandbox .env, for tests that prepare it before booting.
 */
function sandbox_env(object $test): string
{
    return $test->sandbox->envPath();
}
