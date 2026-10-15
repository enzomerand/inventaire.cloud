<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Installer\InstallationState;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession as FrameworkAuthenticateSession;

/**
 * Signs out the sessions of an account that renewed its credentials ("sign out everywhere"), on the web pages.
 *
 * It is the framework's middleware, which compares the fingerprint stored in the session with the account's current
 * one ({@see User::getAuthPassword()}), with one difference: it does nothing until the instance is
 * installed. The install wizard runs before there is a database to look an account up in, and this middleware looks
 * the signed-in user up on every request (which a leftover "remember me" cookie of a previous installation would
 * turn into a database error on the wizard's pages).
 *
 * The API has the same check, through Sanctum's own middleware for the requests of the web front
 * (`sanctum.middleware.authenticate_session`).
 */
final class AuthenticateSession extends FrameworkAuthenticateSession
{
    public function __construct(AuthFactory $auth, private readonly InstallationState $state)
    {
        parent::__construct($auth);
    }

    /**
     * Checks the session of the signed-in user, once the instance is installed.
     *
     * @param  Request  $request
     */
    public function handle($request, Closure $next): mixed
    {
        if (! $this->state->isInstalled()) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
