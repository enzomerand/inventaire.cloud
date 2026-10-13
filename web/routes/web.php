<?php

declare(strict_types=1);

use App\Http\Controllers\Install\InstallController;
use App\Http\Controllers\Install\TokenController;
use App\Http\Controllers\RefreshCsrfTokenController;
use App\Http\Controllers\UpdateLocaleController;
use App\Http\Middleware\AbortIfInstalled;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::get('/csrf-token', RefreshCsrfTokenController::class)->name('csrf.refresh');

Route::put('/locale', UpdateLocaleController::class)->name('locale.update');

/*
| Install wizard. The whole group answers 404 once the instance is installed.
| The page and the token form are open to anyone who can reach the server; every
| step endpoint additionally requires the setup token (see AuthorizeSetupToken).
*/
Route::prefix('install')->name('install.')->middleware(AbortIfInstalled::class)->group(function (): void {
    Route::get('/', [InstallController::class, 'show'])->name('show');

    Route::post('/token', TokenController::class)->middleware('throttle:installer-token')->name('token');
});
