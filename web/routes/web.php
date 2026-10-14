<?php

declare(strict_types=1);

use App\Http\Controllers\Install\FinalizeController;
use App\Http\Controllers\Install\InstallController;
use App\Http\Controllers\Install\InstallStepController;
use App\Http\Controllers\Install\TokenController;
use App\Http\Controllers\RefreshCsrfTokenController;
use App\Http\Controllers\UpdateLocaleController;
use App\Http\Middleware\AbortIfInstalled;
use App\Http\Middleware\AuthorizeSetupToken;
use App\Installer\InstallStep;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

// Passwordless sign-in: the page drives the /api/v1/auth endpoints. Signed-in visitors are sent to their space.
Route::view('/login', 'auth.login')->middleware('guest')->name('login');
Route::view('/app', 'app.dashboard')->middleware('auth')->name('app');

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

    Route::middleware(AuthorizeSetupToken::class)->group(function (): void {
        Route::get('/requirements', [InstallController::class, 'requirements'])->name('requirements');

        // One endpoint per step of the registry (App\Installer\InstallStep), plus a skip endpoint for optional steps.
        foreach (InstallStep::cases() as $step) {
            $route = Route::post('/'.$step->value, [InstallStepController::class, 'save'])->defaults('step', $step->value)->name($step->value);

            if ($step->sendsEmail()) {
                // Each call sends an email to an address typed in the form.
                $route->middleware('throttle:installer-mail');
                Route::post('/'.$step->value.'/verify', [InstallStepController::class, 'verifyMail'])->name($step->value.'.verify');
            }

            if ($step->isOptional()) {
                Route::post('/'.$step->value.'/skip', [InstallStepController::class, 'skip'])->defaults('step', $step->value)->name($step->value.'.skip');
            }
        }

        Route::post('/finalize', FinalizeController::class)->name('finalize');
    });
});
