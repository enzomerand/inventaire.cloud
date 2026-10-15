<?php

// Child process of the concurrency tests: runs one action of the application, as one of several requests made at the
// same moment against the same data.
// Usage: php concurrent-action.php <go file> delete-account <user id>
//        php concurrent-action.php <go file> delete-workspace <user id> <workspace id>
//        php concurrent-action.php <go file> admin-delete <administrator id> <user id>
//        php concurrent-action.php <go file> admin-demote <administrator id> <user id>
//        php concurrent-action.php <go file> hold-admin-lock <user id>
//        php concurrent-action.php <go file> insert-user
//
// It follows the protocol of Tests\Support\ConcurrentChild: boots, says "ready", waits for the go file and only then
// acts. It prints what happened as one JSON line: {"result": "deleted"}, {"result": "refused", "reason":
// "last_administrator"} (or any other reason or exception name); {"result": "demoted"} for admin-demote.
//
// hold-admin-lock and insert-user go together (see AdminConcurrencyTest): the first locks the administrators and the
// given account the way the administration does, says so (<go file>.locked) and keeps the lock until the second is done
// (<go file>.released); the second waits for the lock, inserts an account, and says how long that took:
// {"result": "inserted", "seconds": 0.01}, or {"result": "error", ...} when the insert had to wait for the lock.

declare(strict_types=1);

use App\Admin\Exceptions\UserAdministrationRefused;
use App\Admin\UserAdministration;
use App\Auth\Exceptions\AccountDeletionRefused;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Exceptions\LastWorkspaceException;
use App\Workspaces\Locks;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentChild;

$base = dirname(__DIR__, 2);

require $base.'/vendor/autoload.php';

[, $go, $action] = $argv;
$arguments = array_slice($argv, 3);

$app = ConcurrentChild::boot($base);

ConcurrentChild::awaitGo($go);

/**
 * Waits until a file exists (the other child's signal), up to the seconds given.
 */
function waitForFile(string $path, int $seconds): void
{
    $deadline = microtime(true) + $seconds;

    while (! file_exists($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("No signal {$path} within {$seconds} seconds.");
        }

        usleep(1_000);
    }
}

/**
 * Locks the administrators and an account in a transaction, tells the other child, and keeps the locks until it says
 * it is done.
 *
 * @return array{result: string}
 */
function holdAdministratorLock(Locks $locks, string $go, int $userId): array
{
    DB::transaction(function () use ($locks, $go, $userId): void {
        $locks->administratorsAnd([$userId]);

        touch($go.'.locked');
        waitForFile($go.'.released', 30);
    });

    return ['result' => 'held'];
}

/**
 * Waits for the locks of the other child, then inserts an account and reports how long that took. A short lock wait
 * timeout turns "the insert waits for the locks" into an error instead of a test that hangs.
 *
 * @return array{result: string, seconds: float}
 */
function insertUserWhileLocked(string $go): array
{
    try {
        waitForFile($go.'.locked', 30);

        DB::statement(DB::getDriverName() === 'pgsql' ? "SET lock_timeout = '5s'" : 'SET SESSION innodb_lock_wait_timeout = 5');

        $started = microtime(true);

        DB::table('users')->insert(['name' => 'Newcomer', 'email' => 'newcomer@example.com', 'created_at' => now(), 'updated_at' => now()]);

        return ['result' => 'inserted', 'seconds' => round(microtime(true) - $started, 2)];
    } finally {
        touch($go.'.released');
    }
}

/**
 * Deletes or demotes accounts the way the application does.
 *
 * @param  list<string>  $arguments
 * @return array{result: string}
 */
function changeAccounts(Application $app, string $action, array $arguments): array
{
    match ($action) {
        'delete-account' => User::query()->findOrFail((int) $arguments[0])->delete(),
        'delete-workspace' => $app->make(Workspaces::class)->delete(User::query()->findOrFail((int) $arguments[0]), Workspace::query()->findOrFail((int) $arguments[1])),
        'admin-delete' => $app->make(UserAdministration::class)->delete(User::query()->findOrFail((int) $arguments[0]), User::query()->findOrFail((int) $arguments[1])),
        'admin-demote' => $app->make(UserAdministration::class)->setAdministrator(User::query()->findOrFail((int) $arguments[0]), User::query()->findOrFail((int) $arguments[1]), false),
        default => throw new InvalidArgumentException("Unknown action {$action}."),
    };

    return ['result' => $action === 'admin-demote' ? 'demoted' : 'deleted'];
}

try {
    $outcome = match ($action) {
        'hold-admin-lock' => holdAdministratorLock($app->make(Locks::class), $go, (int) $arguments[0]),
        'insert-user' => insertUserWhileLocked($go),
        default => changeAccounts($app, $action, $arguments),
    };
} catch (AccountDeletionRefused $refusal) {
    $outcome = ['result' => 'refused', 'reason' => $refusal->reason];
} catch (UserAdministrationRefused $refusal) {
    $outcome = ['result' => 'refused', 'reason' => $refusal->reason];
} catch (LastWorkspaceException) {
    $outcome = ['result' => 'refused', 'reason' => LastWorkspaceException::CODE];
} catch (Throwable $exception) {
    $outcome = ['result' => 'error', 'reason' => $exception::class.': '.$exception->getMessage()];
}

fwrite(STDOUT, json_encode($outcome, JSON_THROW_ON_ERROR)."\n");
