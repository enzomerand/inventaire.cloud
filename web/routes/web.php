<?php

declare(strict_types=1);

use App\Http\Controllers\Install\FinalizeController;
use App\Http\Controllers\Install\InstallController;
use App\Http\Controllers\Install\InstallStepController;
use App\Http\Controllers\Install\TokenController;
use App\Http\Controllers\RefreshCsrfTokenController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\Share\ShareFileController;
use App\Http\Controllers\Share\SharePageController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\UpdateLocaleController;
use App\Http\Controllers\Web\AdminSettingsPageController;
use App\Http\Controllers\Web\ApiDocsController;
use App\Http\Controllers\Web\CurrentWorkspaceRedirectController;
use App\Http\Controllers\Web\HomePageController;
use App\Http\Controllers\Web\InventoryFieldsPageController;
use App\Http\Controllers\Web\InventoryPageController;
use App\Http\Controllers\Web\InventorySharingPageController;
use App\Http\Controllers\Web\InventoryVersionsPageController;
use App\Http\Controllers\Web\TemplateGalleryController;
use App\Http\Controllers\Web\TemplatePageController;
use App\Http\Controllers\Web\TemplateUseController;
use App\Http\Controllers\Web\WorkspaceMembersPageController;
use App\Http\Controllers\Web\WorkspacePageController;
use App\Http\Controllers\Webhooks\DocumensoWebhookController;
use App\Http\Middleware\AbortIfInstalled;
use App\Http\Middleware\AuthenticateSession;
use App\Http\Middleware\AuthorizeSetupToken;
use App\Http\Middleware\UseUrlLocale;
use App\Installer\InstallStep;
use App\Inventories\Sharing\ShareLinks;
use App\Services\LocaleResolver;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', HomePageController::class)->name('home');

// The public pages of the templates (the gallery and one page for each template), rendered by the server so that search
// engines read them. They exist once per language, each with an address of its own: the language of the instance has no
// prefix, the others have their code (`/en/templates`), and the prefixed address of the language of the instance is a
// permanent redirect to the other one. The language comes from the address alone (see UseUrlLocale). They are public and
// rendered from files, so they are limited in pace (the `public-pages` limiter, apart from the one of the API).
$localeCodes = implode('|', array_map(fn (string $code): string => preg_quote($code, '/'), app(LocaleResolver::class)->supported()));

Route::middleware(['throttle:public-pages', UseUrlLocale::class])->group(function () use ($localeCodes): void {
    Route::get('/templates', TemplateGalleryController::class)->name('templates.index');
    Route::get('/templates/{slug}', TemplatePageController::class)->name('templates.show');

    Route::prefix('{locale}')->where(['locale' => $localeCodes])
        ->name('templates.localized.')->group(function (): void {
            Route::get('/templates', TemplateGalleryController::class)->name('index');
            Route::get('/templates/{slug}', TemplatePageController::class)->name('show');
        });
});

// The documentation of the API: one page at one address, in the language of the request (the text of the document is French or
// English: resources/openapi/v1.fr.yaml translates the texts of v1.yaml, which /api/v1/openapi.json keeps as they are), public and
// rendered from a file, so limited in pace with the other public pages.
Route::get('/docs/api', ApiDocsController::class)->middleware('throttle:public-pages')->name('docs.api');

// What robots read. Routes, not files of public/ (a web server would serve a file before the application, and the answer
// depends on APP_INDEXABLE). Robots keep no session: nothing here starts one or sets a cookie. The sitemap reads the
// whole catalogue, so it has the same limit as the pages it lists.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])->group(function (): void {
    Route::get('/robots.txt', RobotsController::class)->name('robots');
    Route::get('/sitemap.xml', SitemapController::class)->middleware('throttle:public-pages')->name('sitemap');
});

// What Documenso (the e-signature service) calls when something happens to an envelope: a machine, with no session and no cookie, that
// proves who it is by the secret of the instance in a header (no CSRF token: it has no page to read one from). It is limited in
// pace per network address by the controller itself, which counts only the calls that fail to authenticate (a throttle middleware would
// count every call, and a flood of wrong secrets would then use up what the real ones are allowed). It only wakes up a synchronisation:
// see the controller.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])->group(function (): void {
    Route::post('/webhooks/documenso', DocumensoWebhookController::class)->name('webhooks.documenso');
});

// The public page of a share link and the photos and files it shows: an inventory read-only for anybody who holds the secret address
// (see ShareLinks). Visitors keep no session and no cookie: the address is the secret, and a session would store it (`storeCurrentUrl`),
// so nothing here starts one or checks a CSRF token (these routes only read). The token has no `where()` pattern on purpose: an address
// that is not shaped like a token must get the same 404 page as one that is unknown, expired or revoked, which the controllers answer.
// Each route is limited in pace per network and link (the `share-page` and `share-files` limiters).
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])->group(function (): void {
    Route::get(ShareLinks::PAGE_PATH, SharePageController::class)->middleware('throttle:share-page')->name('share.show');
    Route::get(ShareLinks::PAGE_PATH.'/items/{item}/attachments/{attachment}', [ShareFileController::class, 'show'])->middleware('throttle:share-files')->name('share.attachment');
    Route::get(ShareLinks::PAGE_PATH.'/items/{item}/attachments/{attachment}/thumbnail', [ShareFileController::class, 'thumbnail'])->middleware('throttle:share-files')->name('share.thumbnail');
});

// Passwordless sign-in: the page drives the /api/v1/auth endpoints. Signed-in visitors are sent to their space (the
// workspace they work in), where the pages drive the /api/v1 endpoints of their workspaces, their inventories, their
// account and (for administrators) the instance. Only these pages check that the session was not revoked ("sign out
// everywhere"): the public ones never look the visitor up for that.
Route::view('/login', 'auth.login')->middleware('guest')->name('login');
Route::middleware(['auth', AuthenticateSession::class])->group(function (): void {
    Route::get('/app', CurrentWorkspaceRedirectController::class)->name('app');
    Route::view('/app/workspaces', 'app.workspaces')->name('app.workspaces');
    Route::view('/app/account', 'app.account')->name('app.account');

    // The invitations addressed to the address of the account. The invitation mail links to this path (WorkspaceInvitationMail::PAGE, kept
    // equal to it by a test). A visitor who is not signed in goes through the login page and comes back here.
    Route::view('/app/invitations', 'app.invitations')->name('app.invitations');

    // "Use this template" on a public page: opens the dialog of a new inventory, with the template chosen, in a workspace
    // the person may change. A visitor who is not signed in goes through the login page and comes back here.
    Route::get('/app/templates/{slug}', TemplateUseController::class)->name('app.templates.use');

    // The pages of a workspace: its inventories, its members, the items of one of them and its fields. The ULID of the workspace is in
    // the path (not the "current" workspace of the account), so that a link always shows the same thing; the inventory
    // is looked up inside that workspace (scoped binding) and every page refuses, as a 404, whoever does not belong to it.
    Route::prefix('app/w/{workspace}')->name('app.')->group(function (): void {
        Route::get('/', WorkspacePageController::class)->name('workspace');
        Route::get('/members', WorkspaceMembersPageController::class)->name('workspace.members');

        Route::scopeBindings()->group(function (): void {
            Route::get('/inventories/{inventory}', InventoryPageController::class)->name('inventory');
            Route::get('/inventories/{inventory}/fields', InventoryFieldsPageController::class)->name('inventory.fields');
            Route::get('/inventories/{inventory}/versions', InventoryVersionsPageController::class)->name('inventory.versions');
            Route::get('/inventories/{inventory}/sharing', InventorySharingPageController::class)->name('inventory.sharing');
        });
    });

    // The administration of the instance: its administrators only (the "admin" gate), a 403 for everybody else.
    Route::middleware('can:admin')->prefix('app/admin')->name('app.admin.')->group(function (): void {
        Route::view('/users', 'app.admin.users')->name('users');
        Route::get('/settings', AdminSettingsPageController::class)->name('settings');
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
