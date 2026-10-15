<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request that carries an API token, for the routes that only the web front may call.
 *
 * {@see RequireStatefulRequest} checks that the request has a session, which Sanctum starts for every request that
 * comes from one of the application's own hosts: a request that sends a token as well (and the `Origin` of the
 * application, which anybody can write) has a session too, and Sanctum falls back to the token when the session has
 * nobody in it. Signed in that way, a caller would hold the abilities of a token, not the rights of a person at their
 * browser. For what an administrator does to other people's accounts the token is not enough, whatever its abilities:
 * a leaked one must not delete accounts. A browser never sends one.
 */
final class RefuseApiTokens
{
    /**
     * Refuses a request that has an `Authorization: Bearer` header.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null) {
            return ApiError::forbidden(__('This is only available from the web interface, not with an API token.'), 'session_required');
        }

        return $next($request);
    }
}
