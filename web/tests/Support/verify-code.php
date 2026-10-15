<?php

// Child process of the concurrency tests: types one code through the real HTTP stack (middleware, request,
// controller, transaction), as one of several visitors hitting the same address at the same moment.
// Usage: php verify-code.php <go file> <email> <code> <ip address> [<name>]
//
// The name is what a visitor types to create an account; without it, the address must already have one.
//
// It follows the protocol of Tests\Support\ConcurrentChild: boots, says "ready", waits for the go file and only then
// sends its request. It prints the status and the machine-readable `code` of the answer as one JSON line. It reads the
// cache store from its environment (the test sets CACHE_STORE=database for it) and allows more wrong guesses per code
// than the application does, so that the cap on the address is the only thing in the way.

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\Support\ConcurrentChild;

$base = dirname(__DIR__, 2);

require $base.'/vendor/autoload.php';

[, $go, $email, $code, $address] = $argv;
$name = $argv[5] ?? null;

$app = ConcurrentChild::boot($base);
$kernel = $app->make(Kernel::class);

$app->make('config')->set('auth.login_codes.max_attempts', 30);

if ($app->make('config')->get('cache.default') !== 'database') {
    fwrite(STDERR, "This test is about the database cache store: CACHE_STORE must be database.\n");
    exit(3);
}

ConcurrentChild::awaitGo($go);

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
