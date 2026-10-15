<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountDeletionController;
use App\Http\Controllers\Api\V1\AccountExportController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RequestCodeController;
use App\Http\Controllers\Api\V1\Auth\VerifyCodeController;
use App\Http\Controllers\Api\V1\CurrentWorkspaceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\SignOutEverywhereController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Middleware\RequireStatefulRequest;
use Illuminate\Support\Facades\Route;

/*
 * The JSON API, served under /api (the prefix of the route file) and versioned in the path.
 *
 * The web front calls it with its session cookie (Sanctum "stateful" requests, see config/sanctum.php); other
 * clients will use API tokens. Every answer is JSON, even for a client that does not send an Accept header.
 */
Route::prefix('v1')->name('api.v1.')->group(function (): void {
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

        // The administration of the instance: its administrators only (the "admin" gate), and the web front only (a
        // session; API tokens do not reach it). Accounts are identified by their numeric id (digits only, and few enough for any database to read).
        Route::prefix('admin')->name('admin.')->middleware([RequireStatefulRequest::class, 'can:admin'])->group(function (): void {
            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
            Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
            Route::patch('/users/{user}', [AdminUserController::class, 'update'])->where('user', '[0-9]{1,18}')->name('users.update');
            Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->where('user', '[0-9]{1,18}')->name('users.destroy');
        });
    });
});
