<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\TokenAbility;
use App\Http\Responses\ApiError;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only if the API token it is made with has the ability the route asks for (`token:read`,
 * `token:write` or `token:manage`, see {@see TokenAbility}); otherwise it is a 403 with the code `token_ability_missing`.
 *
 * Only a token is judged. The web front's session (Sanctum gives it a transient token that can do anything) is not
 * restricted by this, and neither is a request nobody is signed in for: `auth:sanctum` answers those. A token never goes
 * beyond the role of its owner either: the policies of the models decide that, as for a session, so nothing here knows
 * about workspaces.
 *
 * A request that carries both a session and a token is neither of the two: Sanctum looks at the session first, so such a
 * request would be signed in as the person at their browser and the abilities of the token (which the caller chose to send)
 * would not be looked at. It is refused with the code `token_with_session`, whatever the token can do. The web front never
 * sends an `Authorization` header, so it is not affected.
 *
 * It must come after `auth:sanctum` (the middleware priority guarantees it). Naming an ability that does not exist is a
 * mistake in the routes, not in the request, and fails loudly.
 */
final class RequireTokenAbility
{
    /**
     * Refuses a request made with a token that lacks the ability.
     *
     * @param  Closure(Request): Response  $next
     * @param  string  $ability  The value of a {@see TokenAbility}.
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $required = TokenAbility::from($ability);
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        // Sanctum builds a personal access token only from the bearer of the request: anything else with a bearer present is the session.
        if ($user instanceof User && $request->bearerToken() !== null && ! $token instanceof PersonalAccessToken) {
            return ApiError::forbidden(__('Send either a session cookie or an API token, not both.'), 'token_with_session');
        }

        if ($token instanceof PersonalAccessToken && ! $token->can($required->value)) {
            return ApiError::forbidden(__('This API token lacks the :ability permission.', ['ability' => $required->value]), 'token_ability_missing');
        }

        return $next($request);
    }
}
