<?php

// Child process of the concurrency tests: runs one action of the application, as one of several requests made at the
// same moment against the same data.
// Usage: php concurrent-action.php <go file> delete-account <user id>
//        php concurrent-action.php <go file> delete-workspace <user id> <workspace id>
//
// The process boots, says "ready", waits for the go file to exist and only then acts, so that all the processes start
// together whatever the time each one needed to boot. It prints what happened as one JSON line:
// {"result": "deleted"}, {"result": "refused", "reason": "last_administrator"} (or any other reason or exception
// name). It gives up (exit code 4) when the go file does not appear within 30 seconds, so that the processes left
// over by a test that was killed do not wait for ever.

declare(strict_types=1);

use App\Auth\AccountDeletion;
use App\Auth\Exceptions\AccountDeletionRefused;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Exceptions\LastWorkspaceException;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Tests\Support\TestDatabaseGuard;

$base = dirname(__DIR__, 2);

require $base.'/vendor/autoload.php';

[, $go, $action] = $argv;
$arguments = array_slice($argv, 3);

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

try {
    match ($action) {
        'delete-account' => $app->make(AccountDeletion::class)->delete(User::query()->findOrFail((int) $arguments[0])),
        'delete-workspace' => $app->make(Workspaces::class)->delete(User::query()->findOrFail((int) $arguments[0]), Workspace::query()->findOrFail((int) $arguments[1])),
        default => throw new InvalidArgumentException("Unknown action {$action}."),
    };

    $outcome = ['result' => 'deleted'];
} catch (AccountDeletionRefused $refusal) {
    $outcome = ['result' => 'refused', 'reason' => $refusal->reason];
} catch (LastWorkspaceException) {
    $outcome = ['result' => 'refused', 'reason' => LastWorkspaceException::CODE];
} catch (Throwable $exception) {
    $outcome = ['result' => 'error', 'reason' => $exception::class.': '.$exception->getMessage()];
}

fwrite(STDOUT, json_encode($outcome, JSON_THROW_ON_ERROR)."\n");
