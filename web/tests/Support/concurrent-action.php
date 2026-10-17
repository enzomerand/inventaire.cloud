<?php

// Child process of the concurrency tests: runs one action of the application, as one of several requests made at the
// same moment against the same data.
// Usage: php concurrent-action.php <go file> delete-account <user id>
//        php concurrent-action.php <go file> delete-workspace <user id> <workspace id>
//        php concurrent-action.php <go file> admin-delete <administrator id> <user id>
//        php concurrent-action.php <go file> admin-demote <administrator id> <user id>
//        php concurrent-action.php <go file> hold-admin-lock <user id>
//        php concurrent-action.php <go file> insert-user
//        php concurrent-action.php <go file> create-inventory <user id> <workspace id> <name>
//        php concurrent-action.php <go file> post-inventory <user id> <workspace id> <name> [<template slug>]
//        php concurrent-action.php <go file> create-field <inventory id> <name>
//        php concurrent-action.php <go file> delete-field <inventory id> <field id>
//        php concurrent-action.php <go file> create-item <user id> <inventory id> [<field ULID> <value>]
//        php concurrent-action.php <go file> update-item <user id> <inventory id> <item id> <field ULID> <value>
//        php concurrent-action.php <go file> delete-item <inventory id> <item id>
//        php concurrent-action.php <go file> upload-attachment <user id> <inventory id> <item id> <path of the file to upload> [<ULID of the file or photo field>]
//        php concurrent-action.php <go file> remove-attachment <user id> <inventory id> <item id> <attachment id>
//        php concurrent-action.php <go file> run-import <import id>
//        php concurrent-action.php <go file> create-token <user id> <name>
//
// The files of the attachments are written to the local disk, whose folder the test gives with TEST_STORAGE_ROOT (the
// folder its own process writes to), so that a child never writes to the real storage of the application.
//
// It follows the protocol of Tests\Support\ConcurrentChild: boots, says "ready", waits for the go file and only then
// acts. It prints what happened as one JSON line: {"result": "deleted"}, {"result": "refused", "reason":
// "last_administrator"} (or any other reason or exception name); {"result": "demoted"} for admin-demote. The actions on
// inventories answer {"result": "created"}, "deleted" or "updated" ("uploaded" and "removed" for a file, "completed" for run-import, which does the work of the job
// for a pending import and answers {"result": "refused", "reason": <the code of its failure>} when it failed), or {"result": "refused", "reason": "item_limit_reached"}
// (the code of a quota) / {"result": "refused", "reason": "invalid:<key>"} (a 422: the first key of its errors) /
// {"result": "refused", "reason": "gone"} (what it works on was deleted by the other process first).
// post-inventory goes through the whole HTTP kernel, as a call of the API does (`POST /api/v1/workspaces/{workspace}/inventories`,
// the person signed in, in English): {"result": "created"} for a 201, {"result": "refused", "reason": <the "code" of a 409>}, and
// {"result": "error", "reason": "HTTP <status>: <body>"} for anything else.
//
// hold-admin-lock and insert-user go together (see AdminConcurrencyTest): the first locks the administrators and the
// given account the way the administration does, says so (<go file>.locked) and keeps the lock until the second is done
// (<go file>.released); the second waits for the lock, inserts an account, and says how long that took:
// {"result": "inserted", "seconds": 0.01}, or {"result": "error", ...} when the insert had to wait for the lock.

declare(strict_types=1);

use App\Admin\Exceptions\UserAdministrationRefused;
use App\Admin\UserAdministration;
use App\Auth\ApiTokens;
use App\Auth\Exceptions\AccountDeletionRefused;
use App\Auth\Exceptions\ApiTokenLimitReachedException;
use App\Inventories\Attachments\Attachments;
use App\Inventories\Exceptions\AttachmentLimitReachedException;
use App\Inventories\Exceptions\FieldFileLimitReachedException;
use App\Inventories\Exceptions\FieldLimitReachedException;
use App\Inventories\Exceptions\InventoryLimitReachedException;
use App\Inventories\Exceptions\ItemLimitReachedException;
use App\Inventories\Exceptions\StorageLimitReachedException;
use App\Inventories\Imports\ImportRunner;
use App\Inventories\Imports\ImportStatus;
use App\Inventories\Inventories;
use App\Inventories\InventoryFields;
use App\Inventories\Items;
use App\Models\Attachment;
use App\Models\Import;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Exceptions\LastWorkspaceException;
use App\Workspaces\Locks;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ConcurrentChild;

$base = dirname(__DIR__, 2);

require $base.'/vendor/autoload.php';

[, $go, $action] = $argv;
$arguments = array_slice($argv, 3);

$app = ConcurrentChild::boot($base);

// The local disk of the child is the folder of the test, not the real storage of the application.
if (is_string(getenv('TEST_STORAGE_ROOT')) && getenv('TEST_STORAGE_ROOT') !== '') {
    $app->make('config')->set('filesystems.disks.local.root', getenv('TEST_STORAGE_ROOT'));
}

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
 * Makes an inventory with a call of the API, through the HTTP kernel: the same middleware, request validation, controller and
 * answers as a request of the web front, for a person who is signed in (a stateless call: no session, no CSRF token).
 *
 * @param  list<string>  $arguments  The user id, the workspace id, the name and, optionally, the slug of a template.
 * @return array{result: string, reason?: string}
 */
function postInventory(Application $app, array $arguments): array
{
    $user = User::query()->findOrFail((int) $arguments[0]);
    $workspace = Workspace::query()->findOrFail((int) $arguments[1]);
    $body = ['name' => $arguments[2]] + (isset($arguments[3]) ? ['template' => $arguments[3]] : []);

    $request = Request::create(
        "/api/v1/workspaces/{$workspace->ulid}/inventories",
        'POST',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => 'en'],
        content: json_encode($body, JSON_THROW_ON_ERROR),
    );

    // The guard of a session needs the request it will serve; the kernel binds the same one again.
    $app->instance('request', $request);
    $app['auth']->guard('web')->setUser($user);
    $app['auth']->shouldUse('web');

    $response = $app->make(Kernel::class)->handle($request);
    $answer = json_decode((string) $response->getContent(), true);

    return match ($response->getStatusCode()) {
        201 => ['result' => 'created'],
        409 => ['result' => 'refused', 'reason' => is_array($answer) && is_string($answer['code'] ?? null) ? $answer['code'] : 'conflict'],
        default => ['result' => 'error', 'reason' => 'HTTP '.$response->getStatusCode().': '.$response->getContent()],
    };
}

/**
 * Makes an API token for a person the way the application does (`{"result": "created"}`; the quota answers like the others).
 *
 * @param  list<string>  $arguments  The id of the person, the name of the token.
 * @return array{result: string}
 */
function createToken(Application $app, array $arguments): array
{
    $app->make(ApiTokens::class)->create(User::query()->findOrFail((int) $arguments[0]), $arguments[1], ['read'], 30);

    return ['result' => 'created'];
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

/**
 * Adds, changes or deletes an inventory, a field or an item the way the application does.
 *
 * @param  list<string>  $arguments
 * @return array{result: string}
 */
function changeInventories(Application $app, string $action, array $arguments): array
{
    switch ($action) {
        case 'create-inventory':
            $app->make(Inventories::class)->create(Workspace::query()->findOrFail((int) $arguments[1]), $arguments[2], null);

            return ['result' => 'created'];
        case 'create-field':
            $app->make(InventoryFields::class)->create(Inventory::query()->findOrFail((int) $arguments[0]), 'text', $arguments[1]);

            return ['result' => 'created'];
        case 'delete-field':
            $app->make(InventoryFields::class)->delete(Inventory::query()->findOrFail((int) $arguments[0]), InventoryField::query()->findOrFail((int) $arguments[1]));

            return ['result' => 'deleted'];
        case 'create-item':
            $app->make(Items::class)->create(Inventory::query()->findOrFail((int) $arguments[1]), isset($arguments[2]) ? [$arguments[2] => $arguments[3]] : [], User::query()->findOrFail((int) $arguments[0]));

            return ['result' => 'created'];
        case 'delete-item':
            $app->make(Items::class)->delete(Inventory::query()->findOrFail((int) $arguments[0]), Item::query()->findOrFail((int) $arguments[1]));

            return ['result' => 'deleted'];
        case 'upload-attachment':
            $app->make(Attachments::class)->upload(
                Inventory::query()->findOrFail((int) $arguments[1]),
                Item::query()->findOrFail((int) $arguments[2]),
                new UploadedFile($arguments[3], basename($arguments[3]), null, null, true),
                User::query()->findOrFail((int) $arguments[0]),
                $arguments[4] ?? null,
            );

            return ['result' => 'uploaded'];
        case 'remove-attachment':
            $app->make(Attachments::class)->delete(
                Inventory::query()->findOrFail((int) $arguments[1]),
                Item::query()->findOrFail((int) $arguments[2]),
                Attachment::query()->findOrFail((int) $arguments[3]),
                User::query()->findOrFail((int) $arguments[0]),
            );

            return ['result' => 'removed'];
        case 'run-import':
            $import = Import::query()->findOrFail((int) $arguments[0]);
            $app->make(ImportRunner::class)->run($import);

            return $import->refresh()->status === ImportStatus::Completed ? ['result' => 'completed'] : ['result' => 'refused', 'reason' => (string) $import->error];
        case 'update-item':
            $app->make(Items::class)->update(Inventory::query()->findOrFail((int) $arguments[1]), Item::query()->findOrFail((int) $arguments[2]), [$arguments[3] => $arguments[4]], User::query()->findOrFail((int) $arguments[0]));

            return ['result' => 'updated'];
        default:
            throw new InvalidArgumentException("Unknown action {$action}.");
    }
}

try {
    $outcome = match ($action) {
        'hold-admin-lock' => holdAdministratorLock($app->make(Locks::class), $go, (int) $arguments[0]),
        'insert-user' => insertUserWhileLocked($go),
        'post-inventory' => postInventory($app, $arguments),
        'create-token' => createToken($app, $arguments),
        'create-inventory', 'create-field', 'delete-field', 'create-item', 'update-item', 'delete-item', 'upload-attachment', 'remove-attachment', 'run-import' => changeInventories($app, $action, $arguments),
        default => changeAccounts($app, $action, $arguments),
    };
} catch (AccountDeletionRefused $refusal) {
    $outcome = ['result' => 'refused', 'reason' => $refusal->reason];
} catch (UserAdministrationRefused $refusal) {
    $outcome = ['result' => 'refused', 'reason' => $refusal->reason];
} catch (LastWorkspaceException) {
    $outcome = ['result' => 'refused', 'reason' => LastWorkspaceException::CODE];
} catch (InventoryLimitReachedException|FieldLimitReachedException|ItemLimitReachedException|AttachmentLimitReachedException|FieldFileLimitReachedException|StorageLimitReachedException|ApiTokenLimitReachedException $limit) {
    $outcome = ['result' => 'refused', 'reason' => $limit::CODE];
} catch (ModelNotFoundException) {
    // Something the action needed was deleted by the other process first (the account that writes, the inventory...).
    $outcome = ['result' => 'refused', 'reason' => 'gone'];
} catch (ValidationException $invalid) {
    $outcome = ['result' => 'refused', 'reason' => 'invalid:'.(string) array_key_first($invalid->errors())];
} catch (Throwable $exception) {
    $outcome = ['result' => 'error', 'reason' => $exception::class.': '.$exception->getMessage()];
}

fwrite(STDOUT, json_encode($outcome, JSON_THROW_ON_ERROR)."\n");
