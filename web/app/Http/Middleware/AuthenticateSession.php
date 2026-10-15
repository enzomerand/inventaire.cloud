<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SessionFingerprint;
use App\Installer\InstallationState;
use App\Models\User;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession as FrameworkAuthenticateSession;

/**
 * Signs out the sessions of an account that renewed its credentials ("sign out everywhere"), on the web pages.
 *
 * It is the framework's middleware, which compares the fingerprint stored in the session with the account's current
 * one ({@see User::getAuthPassword()}), with two differences:
 *
 * - it does nothing until the instance is installed. The install wizard runs before there is a database to look an
 *   account up in, and this middleware looks the signed-in user up on every request (which a leftover "remember me"
 *   cookie of a previous installation would turn into a database error on the wizard's pages);
 * - it refuses a signed-in session that carries no fingerprint (see {@see SessionFingerprint}) instead of giving it
 *   the current one, which would let a session opened before the fingerprints existed outlive a "sign out
 *   everywhere".
 *
 * It guards the pages of the signed-in space only (the routes of the `auth` group): the public pages never look the
 * visitor up for it, and a visitor with a revoked session who opens one simply sees it. The API has the same check,
 * through its own middleware for the requests of the web front ({@see AuthenticateApiSession}).
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

        $name = $this->auth->getDefaultDriver();
        $guard = $this->auth->guard($name);

        if ($guard instanceof SessionGuard && SessionFingerprint::isMissing($request, $guard, $name)) {
            $this->logout($request);
        }

        return parent::handle($request, $next);
    }
}
