<?php

declare(strict_types=1);

use App\Http\Middleware\RedirectIfNotInstalled;
use App\Http\Middleware\SetLocale;
use App\Installer\ProcessEnvironment;
use App\Services\LocaleResolver;
use App\Services\TrustedProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// Before anything reads .env: the installer must know which variables the process really started with.
ProcessEnvironment::capture();

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Language first (so every answer is localized), then the install gate: it must precede everything
        // that could need a database, a session or an application key.
        $middleware->prepend([SetLocale::class, RedirectIfNotInstalled::class]);
        $middleware->encryptCookies(except: [LocaleResolver::COOKIE_NAME]);

        // The web front calls the API with its session cookie: Sanctum makes the requests that come from the
        // application's own host (config/sanctum.php) stateful, every other client stays stateless.
        $middleware->statefulApi();

        // A signed-out visitor of a page goes to the login page; an API client has nowhere to be redirected to
        // and gets a 401 in JSON (the default would also fail while no "login" route exists).
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API always answers JSON, whatever the client asked for: a script that forgets the Accept header must
        // not receive an HTML error page or a redirect to the login page.
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $exception): bool => $request->is('api/*') || $request->expectsJson());
    })->create();

// APP_ENVIRONMENT_FILE moves the configuration file out of the application folder (a Docker volume): Laravel and the
// installer, which falls back to the file Laravel loads, then use the same one. Unset, nothing changes.
$environmentFile = ProcessEnvironment::environmentFile();

if ($environmentFile !== null) {
    $app->useEnvironmentPath(dirname($environmentFile));
    $app->loadEnvironmentFrom(basename($environmentFile));
}

// Read once the configuration (and so .env) is loaded: the middleware callback above runs earlier.
$app->booted(fn () => TrustedProxies::apply($app->make('config')->get('app.trusted_proxies')));

return $app;
