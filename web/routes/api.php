<?php

declare(strict_types=1);

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
        Route::get('/me', MeController::class)->name('me');
        // A session action (it signs this device in again): the web front only.
        Route::post('/me/sign-out-everywhere', SignOutEverywhereController::class)->middleware(RequireStatefulRequest::class)->name('me.sign-out-everywhere');
        Route::put('/me/current-workspace', CurrentWorkspaceController::class)->name('me.current-workspace');

        Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
        Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
        Route::delete('/workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');
    });
});
