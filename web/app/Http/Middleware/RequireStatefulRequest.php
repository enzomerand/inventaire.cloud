<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only the web front through: requests that carry a session, which
 * Sanctum starts for the calls that come from one of the application's own
 * hosts (see config/sanctum.php).
 *
 * Signing in and out are session actions, protected from cross-site forgery by
 * the CSRF check that goes with a stateful request. A call from anywhere else
 * could neither keep the session nor be told apart from a forged form post, so
 * it is refused with a reason an administrator can act on, instead of
 * appearing to work and leaving the person signed out.
 */
final class RequireStatefulRequest
{
    /**
     * Refuses a request that has no session.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return new JsonResponse([
                'message' => __('Signing in is not available from this address. The administrator must list it in SANCTUM_STATEFUL_DOMAINS.'),
                'code' => 'not_stateful',
            ], 403);
        }

        return $next($request);
    }
}
