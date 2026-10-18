<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountDeletionController;
use App\Http\Controllers\Api\V1\AccountExportController;
use App\Http\Controllers\Api\V1\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\ApiTokenController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RequestCodeController;
use App\Http\Controllers\Api\V1\Auth\VerifyCodeController;
use App\Http\Controllers\Api\V1\CurrentWorkspaceController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\FieldTypeController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InventoryFieldController;
use App\Http\Controllers\Api\V1\InventoryVersionController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemRevisionController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\SignOutEverywhereController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Middleware\PaceApiTokens;
use App\Http\Middleware\RefuseApiTokens;
use App\Http\Middleware\RequireStatefulRequest;
use Illuminate\Support\Facades\Route;

/*
 * The JSON API, served under /api (the prefix of the route file) and versioned in the path.
 *
 * The web front calls it with its session cookie (Sanctum "stateful" requests, see config/sanctum.php); other
 * clients use API tokens. Every answer is JSON, even for a client that does not send an Accept header.
 *
 * Every route behind `auth:sanctum` is classified, and the test of the routes (tests/Feature/Api/Tokens/RouteClassificationTest.php)
 * fails for one that is not. Either it is reachable with a token, and says which ability the token needs (`token:read`,
 * `token:write` or `token:manage`, see App\Auth\TokenAbility); or it belongs to a person at their browser (their account, the
 * administration, the tokens themselves) and refuses any request that carries a token (RefuseApiTokens). A token never goes
 * beyond the role of its owner: the policies decide that, for a token as for a session.
 */
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // The catalogue of inventory templates is public (the gallery pages show the same files): no account needed, and a pace per
    // user when signed in, else per network (apart from the one of the public pages).
    Route::middleware('throttle:templates')->group(function (): void {
        Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::get('/templates/{slug}', [TemplateController::class, 'show'])->name('templates.show');
    });

    // The description of the API (OpenAPI 3.1), written by hand and checked against the routes by the tests of tests/Feature/Docs.
    // Public, and counted with the public pages (the page /docs/api draws the same file).
    Route::get('/openapi.json', OpenApiController::class)->middleware('throttle:public-pages')->name('openapi');

    // Passwordless sign-in: a code is emailed, then typed back. Session actions, so the web front only (a token is told so
    // before anything else: it is the more useful answer than "this address is not stateful").
    Route::prefix('auth')->name('auth.')->middleware([RefuseApiTokens::class, RequireStatefulRequest::class])->group(function (): void {
        Route::post('/code', RequestCodeController::class)->middleware('throttle:login-code')->name('code');
        Route::post('/verify', VerifyCodeController::class)->middleware('throttle:login-verify')->name('verify');
        Route::post('/logout', LogoutController::class)->middleware('auth:sanctum')->name('logout');
    });

    // Every route behind the authentication is counted for the token it is called with (PaceApiTokens, per token and minute, right after
    // the authentication); the session of the web front has none, and is not.
    Route::middleware(['auth:sanctum', PaceApiTokens::class])->group(function (): void {
        // What belongs to a person at their browser: their account and its security. No token, whatever its abilities.
        Route::middleware(RefuseApiTokens::class)->group(function (): void {
            Route::patch('/me', [MeController::class, 'update'])->name('me.update');
            Route::get('/me/export', AccountExportController::class)->middleware('throttle:account-export')->name('me.export');
            Route::put('/me/current-workspace', CurrentWorkspaceController::class)->name('me.current-workspace');

            // Session actions (this device is signed in again, or out): the web front only.
            Route::middleware(RequireStatefulRequest::class)->group(function (): void {
                Route::post('/me/sign-out-everywhere', SignOutEverywhereController::class)->name('me.sign-out-everywhere');
                Route::post('/me/deletion-code', [AccountDeletionController::class, 'code'])->middleware('throttle:account-deletion-code')->name('me.deletion-code');
                Route::delete('/me', [AccountDeletionController::class, 'destroy'])->middleware('throttle:account-deletion')->name('me.destroy');

                // The API tokens of the account: a token cannot make others, nor revoke itself. The id of a token is digits only,
                // and few enough for any database to read.
                Route::get('/me/tokens', [ApiTokenController::class, 'index'])->name('me.tokens.index');
                Route::post('/me/tokens', [ApiTokenController::class, 'store'])->middleware('throttle:api-token-creation')->name('me.tokens.store');
                Route::delete('/me/tokens/{token}', [ApiTokenController::class, 'destroy'])->where('token', '[0-9]{1,18}')->name('me.tokens.destroy');
            });
        });

        // Inventories: those of a workspace are listed and created through it; everything else is reached from the
        // inventory, whose ULID alone says where it belongs. Bindings are scoped, so that a field or an item of another
        // inventory is a 404 (and so is an inventory of a workspace the user does not belong to: see InventoryPolicy).

        // Reading: every GET a token may make.
        Route::middleware('token:read')->group(function (): void {
            Route::get('/me', [MeController::class, 'show'])->name('me');
            Route::get('/field-types', FieldTypeController::class)->name('field-types.index');
            Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
            Route::get('/workspaces/{workspace}/inventories', [InventoryController::class, 'index'])->name('workspaces.inventories.index');

            Route::scopeBindings()->group(function (): void {
                Route::get('/inventories/{inventory}', [InventoryController::class, 'show'])->name('inventories.show');
                Route::get('/inventories/{inventory}/items', [ItemController::class, 'index'])->name('inventories.items.index');
                Route::get('/inventories/{inventory}/items/{item}', [ItemController::class, 'show'])->name('inventories.items.show');
                Route::get('/inventories/{inventory}/items/{item}/revisions', [ItemRevisionController::class, 'index'])->name('inventories.items.revisions.index');
                Route::get('/inventories/{inventory}/items/{item}/attachments/{attachment}', [AttachmentController::class, 'show'])->name('inventories.items.attachments.show');
                Route::get('/inventories/{inventory}/items/{item}/attachments/{attachment}/thumbnail', [AttachmentController::class, 'thumbnail'])->name('inventories.items.attachments.thumbnail');
                Route::get('/inventories/{inventory}/exports', [ExportController::class, 'index'])->name('inventories.exports.index');
                Route::get('/inventories/{inventory}/exports/{export}', [ExportController::class, 'show'])->name('inventories.exports.show');
                Route::get('/inventories/{inventory}/exports/{export}/download', [ExportController::class, 'download'])->name('inventories.exports.download');
                Route::get('/inventories/{inventory}/imports', [ImportController::class, 'index'])->name('inventories.imports.index');
                Route::get('/inventories/{inventory}/imports/{import}', [ImportController::class, 'show'])->name('inventories.imports.show');
                Route::get('/inventories/{inventory}/versions', [InventoryVersionController::class, 'index'])->name('inventories.versions.index');
                Route::get('/inventories/{inventory}/versions/{version}', [InventoryVersionController::class, 'show'])->name('inventories.versions.show');
                Route::get('/inventories/{inventory}/versions/{version}/pdf', [InventoryVersionController::class, 'pdf'])->name('inventories.versions.pdf');
                Route::get('/inventories/{inventory}/versions/{version}/snapshot', [InventoryVersionController::class, 'snapshot'])->name('inventories.versions.snapshot');
                Route::get('/inventories/{inventory}/versions/{version}/signed-pdf', [InventoryVersionController::class, 'signedPdf'])->name('inventories.versions.signed-pdf');
            });
        });

        // Writing: the content of an inventory. Its items and their files, and the files made of them or brought into them.
        Route::scopeBindings()->middleware('token:write')->group(function (): void {
            Route::post('/inventories/{inventory}/items', [ItemController::class, 'store'])->name('inventories.items.store');
            Route::patch('/inventories/{inventory}/items/{item}', [ItemController::class, 'update'])->name('inventories.items.update');
            Route::delete('/inventories/{inventory}/items/{item}', [ItemController::class, 'destroy'])->name('inventories.items.destroy');

            Route::post('/inventories/{inventory}/items/{item}/attachments', [AttachmentController::class, 'store'])->middleware('throttle:attachment-upload')->name('inventories.items.attachments.store');
            Route::delete('/inventories/{inventory}/items/{item}/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('inventories.items.attachments.destroy');

            // Exports: a file of the items (a spreadsheet, a CSV, a JSON, a PDF, a zip of the photos), written by a queued job for the
            // person who asked and nobody else: an export of another person is a 404, whatever their role.
            Route::post('/inventories/{inventory}/exports', [ExportController::class, 'store'])->middleware('throttle:exports')->name('inventories.exports.store');
            Route::delete('/inventories/{inventory}/exports/{export}', [ExportController::class, 'destroy'])->name('inventories.exports.destroy');

            // Imports: a spreadsheet or a CSV brought into an inventory. The file is read when it is sent (the columns, a guess of their
            // types and a proposal of where each goes), then a queued job adds the rows once the person started it. Changing the items is
            // what it does, so editors and owners only; and an import is its author's alone: another person's is a 404.
            Route::post('/inventories/{inventory}/imports', [ImportController::class, 'store'])->middleware('throttle:imports')->name('inventories.imports.store');
            Route::patch('/inventories/{inventory}/imports/{import}', [ImportController::class, 'update'])->middleware('throttle:imports')->name('inventories.imports.update');
            Route::post('/inventories/{inventory}/imports/{import}/start', [ImportController::class, 'start'])->name('inventories.imports.start');
            Route::delete('/inventories/{inventory}/imports/{import}', [ImportController::class, 'destroy'])->name('inventories.imports.destroy');

            // Official versions: the inventory frozen as a numbered, dated state, with a PDF and, if asked, a request for signatures. They
            // belong to the inventory, not to the person who froze it: whoever can see the inventory sees them. Freezing is for editors and
            // owners (and counted per person, since it locks the inventory while it reads every item), deleting for owners.
            Route::post('/inventories/{inventory}/versions', [InventoryVersionController::class, 'store'])->middleware('throttle:official-versions')->name('inventories.versions.store');
            Route::delete('/inventories/{inventory}/versions/{version}', [InventoryVersionController::class, 'destroy'])->name('inventories.versions.destroy');

            // The request for signatures of a version: cancelling it while its signers are awaited (Documenso is asked at once) and sending
            // it again after it failed. Editors and owners, like asking for signatures in the first place.
            Route::post('/inventories/{inventory}/versions/{version}/signature/cancel', [InventoryVersionController::class, 'cancelSignature'])->name('inventories.versions.signature.cancel');
            Route::post('/inventories/{inventory}/versions/{version}/signature/retry', [InventoryVersionController::class, 'retrySignature'])->name('inventories.versions.signature.retry');
        });

        // Managing: the structure. Workspaces, the inventories in them and their fields.
        Route::scopeBindings()->middleware('token:manage')->group(function (): void {
            Route::post('/workspaces', [WorkspaceController::class, 'store'])->middleware('throttle:workspace-creation')->name('workspaces.store');
            Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
            Route::delete('/workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');
            Route::post('/workspaces/{workspace}/inventories', [InventoryController::class, 'store'])->name('workspaces.inventories.store');

            Route::patch('/inventories/{inventory}', [InventoryController::class, 'update'])->name('inventories.update');
            Route::delete('/inventories/{inventory}', [InventoryController::class, 'destroy'])->name('inventories.destroy');

            Route::post('/inventories/{inventory}/fields', [InventoryFieldController::class, 'store'])->name('inventories.fields.store');
            Route::put('/inventories/{inventory}/fields/order', [InventoryFieldController::class, 'reorder'])->name('inventories.fields.order');
            Route::patch('/inventories/{inventory}/fields/{field}', [InventoryFieldController::class, 'update'])->name('inventories.fields.update');
            Route::delete('/inventories/{inventory}/fields/{field}', [InventoryFieldController::class, 'destroy'])->name('inventories.fields.destroy');
        });

        // The administration of the instance: its administrators only (the "admin" gate), and the web front only (a
        // session, never an API token). The id of an account is digits only, and few enough for any database to
        // read; the controller looks it up itself: see UserController::find().
        Route::prefix('admin')->name('admin.')->middleware([RefuseApiTokens::class, RequireStatefulRequest::class, 'can:admin'])->group(function (): void {
            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
            Route::post('/users', [AdminUserController::class, 'store'])->middleware('throttle:admin-user-creation')->name('users.store');
            Route::patch('/users/{user}', [AdminUserController::class, 'update'])->where('user', '[0-9]{1,18}')->name('users.update');
            Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->where('user', '[0-9]{1,18}')->name('users.destroy');

            Route::get('/settings', [AdminSettingsController::class, 'show'])->name('settings.show');
            Route::put('/settings', [AdminSettingsController::class, 'update'])->name('settings.update');
        });
    });
});
