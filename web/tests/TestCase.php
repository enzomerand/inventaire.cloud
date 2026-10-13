<?php

declare(strict_types=1);

namespace Tests;

use App\Installer\EnvironmentLocks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
     * Boots the application inside a sandbox, then checks the database before
     * any trait (such as RefreshDatabase) gets the chance to migrate it.
     *
     * This mirrors the framework's own createApplication() except for one hook:
     * the installer decides at provider-registration time whether the instance is
     * installed, so the sandbox must be applied between the configuration loading
     * and the provider registration, which the parent implementation offers no
     * way to do. (Cached config and route traits are not supported here; the
     * suite does not use them.)
     *
     * @return Application
     */
    public function createApplication()
    {
        $this->sandbox ??= InstallerSandbox::create();

        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        $app->beforeBootstrapping(RegisterProviders::class, function (Application $app): void {
            $this->sandbox?->apply($app, $this->installed, $this->withAppKey);

            if ($this->language !== null) {
                $app->make('config')->set('app.locale', $this->language);
            }
        });

        $app->make(Kernel::class)->bootstrap();

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
     * Removes the sandbox once the test is over.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->sandbox?->destroy();
        $this->sandbox = null;
    }
}
