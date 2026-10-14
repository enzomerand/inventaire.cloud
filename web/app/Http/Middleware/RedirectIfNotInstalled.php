<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallationState;
use App\Installer\SetupToken;
use App\Installer\StaticErrorPage;
use App\Providers\InstallerServiceProvider;
use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sends every visitor to the install wizard until the instance is installed.
 *
 * It is global middleware, ahead of the web group, so it applies to every URL
 * (including unknown ones) and never needs a session, a database or a
 * translated route: an instance that is not configured yet has none of them.
 * API clients cannot follow a redirect to an HTML page, so they get a 503 with
 * a machine-readable code instead.
 */
final class RedirectIfNotInstalled
{
    /**
     * Paths still served before the installation: the wizard itself, the
     * health check, built assets, and the two endpoints the wizard page calls
     * (CSRF token refresh and language switch).
     */
    private const EXEMPT = ['up', 'install', 'install/*', 'build/*', 'csrf-token', 'locale'];

    public function __construct(
        private readonly Application $app,
        private readonly InstallationState $state,
        private readonly SetupToken $token,
    ) {}

    /**
     * Lets installed instances through, and redirects the rest to /install.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->state->isInstalled() || $request->is('up')) {
            return $next($request);
        }

        $failure = $this->startupFailure();

        if ($failure !== null) {
            StaticErrorPage::report($failure);

            return StaticErrorPage::response();
        }

        if ($request->is(self::EXEMPT)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => __('The application is not installed yet.'),
                'code' => 'not_installed',
                'install_url' => route('install.show'),
            ], 503);
        }

        return new RedirectResponse(route('install.show'));
    }

    /**
     * Why the installer cannot run at all, or null when it can.
     *
     * The visitor only gets a generic page; the exception goes to the log.
     *
     * Also creates the setup token on the very first web access and writes it
     * to the log, which is where an administrator with server access finds it.
     */
    private function startupFailure(): ?InstallationException
    {
        if ($this->app->bound(InstallerServiceProvider::STARTUP_FAILURE)) {
            return $this->app->make(InstallerServiceProvider::STARTUP_FAILURE);
        }

        try {
            $this->token->ensure();
        } catch (InstallationException $exception) {
            return $exception;
        }

        $this->announceToken();

        return null;
    }

    /**
     * Writes the setup token to the installer log, once.
     *
     * The log is a channel of its own (one file, whatever LOG_CHANNEL and
     * LOG_LEVEL say) so the token always reaches the administrator. A logging
     * failure never breaks the page: the token stays on disk and is shown by
     * `php artisan inventaire:install-token`; the write is retried next visit.
     */
    private function announceToken(): void
    {
        try {
            $this->token->announce(static function (string $token): void {
                Log::channel('installer')->warning(
                    'inventaire.cloud setup token: '.$token
                    .' (also printed by "php artisan inventaire:install-token"). Open /install and enter it to start the installation.',
                );
            });
        } catch (Throwable) {
            error_log('inventaire.cloud: the setup token could not be written to storage/logs/installer.log; run "php artisan inventaire:install-token" to read it.');
        }
    }
}
