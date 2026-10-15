<?php

// Child process of the concurrency tests: runs one action of the application, as one of several requests made at the
// same moment against the same data.
// Usage: php concurrent-action.php <go file> delete-account <user id>
//        php concurrent-action.php <go file> delete-workspace <user id> <workspace id>
//        php concurrent-action.php <go file> admin-delete <administrator id> <user id>
//        php concurrent-action.php <go file> admin-demote <user id>
//
// It follows the protocol of Tests\Support\ConcurrentChild: boots, says "ready", waits for the go file and only then
// acts. It prints what happened as one JSON line: {"result": "deleted"}, {"result": "refused", "reason":
// "last_administrator"} (or any other reason or exception name); {"result": "demoted"} for admin-demote.

declare(strict_types=1);

use App\Admin\Exceptions\UserAdministrationRefused;
use App\Admin\UserAdministration;
use App\Auth\Exceptions\AccountDeletionRefused;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Exceptions\LastWorkspaceException;
use App\Workspaces\Workspaces;
use Tests\Support\ConcurrentChild;

$base = dirname(__DIR__, 2);

require $base.'/vendor/autoload.php';

[, $go, $action] = $argv;
$arguments = array_slice($argv, 3);

$app = ConcurrentChild::boot($base);

ConcurrentChild::awaitGo($go);

try {
    match ($action) {
        'delete-account' => User::query()->findOrFail((int) $arguments[0])->delete(),
        'delete-workspace' => $app->make(Workspaces::class)->delete(User::query()->findOrFail((int) $arguments[0]), Workspace::query()->findOrFail((int) $arguments[1])),
        'admin-delete' => $app->make(UserAdministration::class)->delete(User::query()->findOrFail((int) $arguments[0]), User::query()->findOrFail((int) $arguments[1])),
        'admin-demote' => $app->make(UserAdministration::class)->setAdministrator(User::query()->findOrFail((int) $arguments[0]), false),
        default => throw new InvalidArgumentException("Unknown action {$action}."),
    };

    $outcome = ['result' => $action === 'admin-demote' ? 'demoted' : 'deleted'];
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
