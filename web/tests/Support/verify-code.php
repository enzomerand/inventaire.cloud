<?php

// Child process of the concurrency tests: types one code through the real HTTP stack (middleware, request,
// controller, transaction), as one of several visitors hitting the same address at the same moment.
// Usage: php verify-code.php <email> <code> <ip address> <go file> [<name>]
//
// The name is what a visitor types to create an account; without it, the address must already have one.
//
// The process boots, says "ready", waits for the go file to exist and only then sends its request, so that
// all the processes start together whatever the time each one needed to boot. It prints the status and the
// machine-readable `code` of the answer as one JSON line. It reads the cache store from its environment (the
// test sets CACHE_STORE=database for it) and allows more wrong guesses per code than the application does, so
// that the cap on the address is the only thing in the way.
//
// It gives up (exit code 4) when the go file does not appear within 30 seconds, so that the processes left over
// by a test that was killed do not wait for ever.

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Request;
use Tests\Support\TestDatabaseGuard;

$base = dirname(__DIR__, 2);

require $base.'/vendor/autoload.php';

[, $email, $code, $address, $go] = $argv;
$name = $argv[5] ?? null;

$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);

// The checks of Tests\TestCase::createApplication(), made as soon as the configuration is loaded and before any
// service provider boots: the real .env holds credentials and must not be read, and nothing may touch a database
// that is not clearly a test one.
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
$app->make('config')->set('auth.login_codes.max_attempts', 30);

if ($app->make('config')->get('cache.default') !== 'database') {
    fwrite(STDERR, "This test is about the database cache store: CACHE_STORE must be database.\n");
    exit(3);
}

fwrite(STDOUT, "ready\n");
fflush(STDOUT);

$deadline = microtime(true) + 30;

while (! file_exists($go)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "No go signal within 30 seconds: the test that started this process is gone.\n");
        exit(4);
    }

    usleep(200);
}

$origin = rtrim((string) $app->make('config')->get('app.url'), '/');
$request = Request::create('/api/v1/auth/verify', 'POST', array_filter(['email' => $email, 'code' => $code, 'name' => $name], fn (?string $value): bool => $value !== null), [], [], [
    'REMOTE_ADDR' => $address,
    'HTTP_ORIGIN' => $origin,
    'HTTP_REFERER' => $origin.'/login',
    'HTTP_ACCEPT' => 'application/json',
]);

$response = $kernel->handle($request);
$body = json_decode((string) $response->getContent(), true);

fwrite(STDOUT, json_encode(['status' => $response->getStatusCode(), 'code' => is_array($body) ? ($body['code'] ?? null) : null], JSON_THROW_ON_ERROR)."\n");

$kernel->terminate($request, $response);
