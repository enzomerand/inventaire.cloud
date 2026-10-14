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

// Before anything reads .env: the installer must know which variables the process really started with.
ProcessEnvironment::capture();

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Language first (so every answer is localized), then the install gate: it must precede everything
        // that could need a database, a session or an application key.
        $middleware->prepend([SetLocale::class, RedirectIfNotInstalled::class]);
        $middleware->encryptCookies(except: [LocaleResolver::COOKIE_NAME]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// Read once the configuration (and so .env) is loaded: the middleware callback above runs earlier.
$app->booted(fn () => TrustedProxies::apply($app->make('config')->get('app.trusted_proxies')));

return $app;
