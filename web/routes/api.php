<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountDeletionController;
use App\Http\Controllers\Api\V1\AccountExportController;
use App\Http\Controllers\Api\V1\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RequestCodeController;
use App\Http\Controllers\Api\V1\Auth\VerifyCodeController;
use App\Http\Controllers\Api\V1\CurrentWorkspaceController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\FieldTypeController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InventoryFieldController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemRevisionController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\SignOutEverywhereController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Middleware\RefuseApiTokens;
use App\Http\Middleware\RequireStatefulRequest;
use Illuminate\Support\Facades\Route;

/*
 * The JSON API, served under /api (the prefix of the route file) and versioned in the path.
 *
 * The web front calls it with its session cookie (Sanctum "stateful" requests, see config/sanctum.php); other
 * clients will use API tokens. Every answer is JSON, even for a client that does not send an Accept header.
 */
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // The catalogue of inventory templates is public (the gallery pages show the same files): no account needed, and a pace per
    // user when signed in, else per network (apart from the one of the public pages).
    Route::middleware('throttle:templates')->group(function (): void {
        Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::get('/templates/{slug}', [TemplateController::class, 'show'])->name('templates.show');
    });

    // Passwordless sign-in: a code is emailed, then typed back. Session actions, so the web front only.
    Route::prefix('auth')->name('auth.')->middleware(RequireStatefulRequest::class)->group(function (): void {
        Route::post('/code', RequestCodeController::class)->middleware('throttle:login-code')->name('code');
        Route::post('/verify', VerifyCodeController::class)->middleware('throttle:login-verify')->name('verify');
        Route::post('/logout', LogoutController::class)->middleware('auth:sanctum')->name('logout');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [MeController::class, 'show'])->name('me');
        Route::patch('/me', [MeController::class, 'update'])->name('me.update');
        Route::get('/me/export', AccountExportController::class)->middleware('throttle:account-export')->name('me.export');
        // Session actions (this device is signed in again, or out): the web front only.
        Route::middleware(RequireStatefulRequest::class)->group(function (): void {
            Route::post('/me/sign-out-everywhere', SignOutEverywhereController::class)->name('me.sign-out-everywhere');
            Route::post('/me/deletion-code', [AccountDeletionController::class, 'code'])->middleware('throttle:account-deletion-code')->name('me.deletion-code');
            Route::delete('/me', [AccountDeletionController::class, 'destroy'])->middleware('throttle:account-deletion')->name('me.destroy');
        });
        Route::put('/me/current-workspace', CurrentWorkspaceController::class)->name('me.current-workspace');

        Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::post('/workspaces', [WorkspaceController::class, 'store'])->middleware('throttle:workspace-creation')->name('workspaces.store');
        Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
        Route::delete('/workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');

        // Inventories: those of a workspace are listed and created through it; everything else is reached from the
        // inventory, whose ULID alone says where it belongs. Bindings are scoped, so that a field or an item of another
        // inventory is a 404 (and so is an inventory of a workspace the user does not belong to: see InventoryPolicy).
        Route::get('/field-types', FieldTypeController::class)->name('field-types.index');
        Route::get('/workspaces/{workspace}/inventories', [InventoryController::class, 'index'])->name('workspaces.inventories.index');
        Route::post('/workspaces/{workspace}/inventories', [InventoryController::class, 'store'])->name('workspaces.inventories.store');

        Route::scopeBindings()->group(function (): void {
            Route::get('/inventories/{inventory}', [InventoryController::class, 'show'])->name('inventories.show');
            Route::patch('/inventories/{inventory}', [InventoryController::class, 'update'])->name('inventories.update');
            Route::delete('/inventories/{inventory}', [InventoryController::class, 'destroy'])->name('inventories.destroy');

            Route::post('/inventories/{inventory}/fields', [InventoryFieldController::class, 'store'])->name('inventories.fields.store');
            Route::put('/inventories/{inventory}/fields/order', [InventoryFieldController::class, 'reorder'])->name('inventories.fields.order');
            Route::patch('/inventories/{inventory}/fields/{field}', [InventoryFieldController::class, 'update'])->name('inventories.fields.update');
            Route::delete('/inventories/{inventory}/fields/{field}', [InventoryFieldController::class, 'destroy'])->name('inventories.fields.destroy');

            Route::get('/inventories/{inventory}/items', [ItemController::class, 'index'])->name('inventories.items.index');
            Route::post('/inventories/{inventory}/items', [ItemController::class, 'store'])->name('inventories.items.store');
            Route::get('/inventories/{inventory}/items/{item}', [ItemController::class, 'show'])->name('inventories.items.show');
            Route::patch('/inventories/{inventory}/items/{item}', [ItemController::class, 'update'])->name('inventories.items.update');
            Route::delete('/inventories/{inventory}/items/{item}', [ItemController::class, 'destroy'])->name('inventories.items.destroy');
            Route::get('/inventories/{inventory}/items/{item}/revisions', [ItemRevisionController::class, 'index'])->name('inventories.items.revisions.index');

            Route::post('/inventories/{inventory}/items/{item}/attachments', [AttachmentController::class, 'store'])->middleware('throttle:attachment-upload')->name('inventories.items.attachments.store');
            Route::get('/inventories/{inventory}/items/{item}/attachments/{attachment}', [AttachmentController::class, 'show'])->name('inventories.items.attachments.show');
            Route::get('/inventories/{inventory}/items/{item}/attachments/{attachment}/thumbnail', [AttachmentController::class, 'thumbnail'])->name('inventories.items.attachments.thumbnail');
            Route::delete('/inventories/{inventory}/items/{item}/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('inventories.items.attachments.destroy');

            // Exports: a file of the items (a spreadsheet, a CSV, a JSON, a PDF, a zip of the photos), written by a queued job for the
            // person who asked and nobody else: an export of another person is a 404, whatever their role.
            Route::get('/inventories/{inventory}/exports', [ExportController::class, 'index'])->name('inventories.exports.index');
            Route::post('/inventories/{inventory}/exports', [ExportController::class, 'store'])->middleware('throttle:exports')->name('inventories.exports.store');
            Route::get('/inventories/{inventory}/exports/{export}', [ExportController::class, 'show'])->name('inventories.exports.show');
            Route::get('/inventories/{inventory}/exports/{export}/download', [ExportController::class, 'download'])->name('inventories.exports.download');
            Route::delete('/inventories/{inventory}/exports/{export}', [ExportController::class, 'destroy'])->name('inventories.exports.destroy');
        });

        // The administration of the instance: its administrators only (the "admin" gate), and the web front only (a
        // session, never an API token). The id of an account is digits only, and few enough for any database to
        // read; the controller looks it up itself: see UserController::find().
        Route::prefix('admin')->name('admin.')->middleware([RequireStatefulRequest::class, RefuseApiTokens::class, 'can:admin'])->group(function (): void {
            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
            Route::post('/users', [AdminUserController::class, 'store'])->middleware('throttle:admin-user-creation')->name('users.store');
            Route::patch('/users/{user}', [AdminUserController::class, 'update'])->where('user', '[0-9]{1,18}')->name('users.update');
            Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->where('user', '[0-9]{1,18}')->name('users.destroy');

            Route::get('/settings', [AdminSettingsController::class, 'show'])->name('settings.show');
            Route::put('/settings', [AdminSettingsController::class, 'update'])->name('settings.update');
        });
    });
});
