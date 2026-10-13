<?php

declare(strict_types=1);

use App\Http\Middleware\SetLocale;
use App\Services\LocaleResolver;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global and first, so that 404s, 419s and the future API are localized too.
        $middleware->prepend(SetLocale::class);

        // The value is checked against the supported list on every read, and the
        // global SetLocale middleware needs to read it before cookies are decrypted.
        $middleware->encryptCookies(except: [LocaleResolver::COOKIE_NAME]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
