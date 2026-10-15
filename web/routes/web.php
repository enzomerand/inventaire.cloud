<?php

declare(strict_types=1);

use App\Http\Controllers\Install\FinalizeController;
use App\Http\Controllers\Install\InstallController;
use App\Http\Controllers\Install\InstallStepController;
use App\Http\Controllers\Install\TokenController;
use App\Http\Controllers\RefreshCsrfTokenController;
use App\Http\Controllers\UpdateLocaleController;
use App\Http\Controllers\Web\CurrentWorkspaceRedirectController;
use App\Http\Controllers\Web\InventoryFieldsPageController;
use App\Http\Controllers\Web\InventoryPageController;
use App\Http\Controllers\Web\WorkspacePageController;
use App\Http\Middleware\AbortIfInstalled;
use App\Http\Middleware\AuthenticateSession;
use App\Http\Middleware\AuthorizeSetupToken;
use App\Installer\InstallStep;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

// Passwordless sign-in: the page drives the /api/v1/auth endpoints. Signed-in visitors are sent to their space (the
// workspace they work in), where the pages drive the /api/v1 endpoints of their workspaces, their inventories, their
// account and (for administrators) the instance. Only these pages check that the session was not revoked ("sign out
// everywhere"): the public ones never look the visitor up for that.
Route::view('/login', 'auth.login')->middleware('guest')->name('login');
Route::middleware(['auth', AuthenticateSession::class])->group(function (): void {
    Route::get('/app', CurrentWorkspaceRedirectController::class)->name('app');
    Route::view('/app/workspaces', 'app.workspaces')->name('app.workspaces');
    Route::view('/app/account', 'app.account')->name('app.account');

    // The pages of a workspace: its inventories, the items of one of them and its fields. The ULID of the workspace is in
    // the path (not the "current" workspace of the account), so that a link always shows the same thing; the inventory
    // is looked up inside that workspace (scoped binding) and every page refuses, as a 404, whoever does not belong to it.
    Route::prefix('app/w/{workspace}')->name('app.')->group(function (): void {
        Route::get('/', WorkspacePageController::class)->name('workspace');

        Route::scopeBindings()->group(function (): void {
            Route::get('/inventories/{inventory}', InventoryPageController::class)->name('inventory');
            Route::get('/inventories/{inventory}/fields', InventoryFieldsPageController::class)->name('inventory.fields');
        });
    });

    // The administration of the instance: its administrators only (the "admin" gate), a 403 for everybody else.
    Route::middleware('can:admin')->prefix('app/admin')->name('app.admin.')->group(function (): void {
        Route::view('/users', 'app.admin.users')->name('users');
        Route::view('/settings', 'app.admin.settings')->name('settings');
    });
});

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
