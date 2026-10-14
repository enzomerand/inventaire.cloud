<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

/*
 * The JSON API, served under /api (the prefix of the route file) and versioned in the path.
 *
 * The web front calls it with its session cookie (Sanctum "stateful" requests, see config/sanctum.php); other
 * clients will use API tokens. Every answer is JSON, even for a client that does not send an Accept header.
 */
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', MeController::class)->name('me');
    });
});
