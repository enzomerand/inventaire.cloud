<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use RuntimeException;
use Tests\TestCase;

/**
 * What the scripts run by {@see ConcurrentChildren} have in common: they boot the application as the test suite does,
 * say "ready", wait for the go file, act, and print their answer as one JSON line.
 */
final class ConcurrentChild
{
    /**
     * How long, in seconds, a child that is ready waits for the go file. The test creates it once every child has
     * booted, which it allows {@see ConcurrentChildren::BOOT_TIMEOUT} for: a child must not give up before that, or
     * the slow boot of another would make it leave the others without it. Longer than that only so that the children
     * left over by a test that was killed do not wait for ever.
     */
    public const GO_TIMEOUT = ConcurrentChildren::BOOT_TIMEOUT + 30;

    /**
     * Boots the application of a child, with the checks of {@see TestCase::createApplication()}.
     *
     * They are made as soon as the configuration is loaded and before any service provider boots: the real `.env`
     * holds credentials and must not be read, and nothing may touch a database that is not clearly a test one. The
     * logs are turned off.
     *
     * @param  string  $base  The root of the application.
     *
     * @throws RuntimeException When the child would not run against the test environment.
     */
    public static function boot(string $base): Application
    {
        $app = require $base.'/bootstrap/app.php';
        $kernel = $app->make(Kernel::class);

        $checked = false;
        $check = function (Application $app) use (&$checked): void {
            if ($app->environmentFile() !== '.env.testing') {
                throw new RuntimeException('The test processes must load .env.testing, not the real .env: APP_ENV=testing must reach them.');
            }

            TestDatabaseGuard::assertSafe($app->make('config'));

            $checked = true;
        };

        $app->beforeBootstrapping(RegisterProviders::class, $check);
        $kernel->bootstrap();

        if (! $checked) {
            // The hook did not fire (the framework's bootstrapping changed): at least check before the first query.
            $check($app);
        }

        $app->make('config')->set('logging.default', 'null');

        return $app;
    }

    /**
     * Says "ready", then waits until the go file exists, so that all the children act together. Gives up (exit code 4)
     * when it does not appear within {@see self::GO_TIMEOUT} seconds.
     */
    public static function awaitGo(string $go): void
    {
        fwrite(STDOUT, "ready\n");
        fflush(STDOUT);

        $deadline = microtime(true) + self::GO_TIMEOUT;

        while (! file_exists($go)) {
            if (microtime(true) > $deadline) {
                fwrite(STDERR, 'No go signal within '.self::GO_TIMEOUT." seconds: the test that started this process is gone.\n");
                exit(4);
            }

            usleep(200);
        }
    }
}
