<?php

declare(strict_types=1);

namespace Tests;

use App\Installer\EnvironmentLocks;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Env;
use Tests\Support\InstallerSandbox;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Where the installer writes during this test (see {@see InstallerSandbox}).
     */
    public ?InstallerSandbox $sandbox = null;

    /**
     * Whether the application boots in the "installed" state. True by default so
     * the whole existing suite behaves as before the installer existed; installer
     * tests that need a fresh instance call {@see self::bootInstaller()}.
     */
    protected bool $installed = true;

    /**
     * Whether the configured application key is kept (false boots without one).
     */
    protected bool $withAppKey = true;

    /**
     * Interface language forced for this test, or null for the configured default (French).
     * Installer tests assert on English source messages and switch to it with
     * {@see self::useLanguage()}.
     */
    protected ?string $language = null;

    /**
     * What the installer sees as the process environment (Docker variables).
     * Empty by default: the variables phpunit.xml defines are not "imposed".
     *
     * @var array<string, string>
     */
    protected array $processEnvironment = [];

    /**
     * Boots the application against the installer sandbox, then checks the
     * database before any trait (such as RefreshDatabase) gets the chance to
     * migrate it.
     *
     * The sandbox needs no special bootstrapping: phpunit.xml points the
     * installer's state, its .env and the config cache at it with environment
     * variables, so only the installation state (a marker file) and, when a
     * test wants it, the absence of an application key are arranged first.
     *
     * @return Application
     */
    public function createApplication()
    {
        $this->sandbox ??= InstallerSandbox::create();
        $this->sandbox->prepare($this->installed);

        $app = $this->withAppKey ? parent::createApplication() : $this->withoutAppKey(fn () => parent::createApplication());

        $this->sandbox->configure($app);

        if ($this->language !== null) {
            $app->make('config')->set('app.locale', $this->language);
        }

        TestDatabaseGuard::assertSafe($app->make('config'));

        $app->singleton(EnvironmentLocks::class, fn (): EnvironmentLocks => new EnvironmentLocks(
            fn (): array => $this->processEnvironment,
        ));

        return $app;
    }

    /**
     * Reboots the application in another installation state.
     *
     * @param  bool  $installed  False simulates a fresh instance.
     * @param  bool  $appKey  False also removes the application key.
     */
    public function bootInstaller(bool $installed = true, bool $appKey = true): void
    {
        $this->installed = $installed;
        $this->withAppKey = $appKey;

        $this->refreshApplication();
    }

    /**
     * Answers in another language for the rest of the test, surviving reboots.
     */
    public function useLanguage(string $locale): void
    {
        $this->language = $locale;

        config(['app.locale' => $locale]);
        app()->setLocale($locale);
    }

    /**
     * Empties the sandbox once the test is over.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->sandbox?->destroy();
        $this->sandbox = null;
    }

    /**
     * Runs a callback with APP_KEY defined as empty in the process environment,
     * so the configuration is loaded without a key (dotenv never overrides a
     * variable that is already defined), then puts the environment back.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withoutAppKey(callable $callback): mixed
    {
        $previous = ['env' => $_ENV['APP_KEY'] ?? null, 'server' => $_SERVER['APP_KEY'] ?? null, 'getenv' => getenv('APP_KEY')];

        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = '';
        putenv('APP_KEY=');

        // The framework remembers which variables a previous application loaded from .env and overwrites
        // them on the next load: a fresh repository treats the empty APP_KEY as defined by the environment.
        Env::enablePutenv();

        try {
            return $callback();
        } finally {
            Env::enablePutenv();

            foreach (['env' => &$_ENV, 'server' => &$_SERVER] as $name => &$target) {
                if ($previous[$name] === null) {
                    unset($target['APP_KEY']);
                } else {
                    $target['APP_KEY'] = $previous[$name];
                }
            }

            putenv($previous['getenv'] === false ? 'APP_KEY' : 'APP_KEY='.$previous['getenv']);
        }
    }
}
