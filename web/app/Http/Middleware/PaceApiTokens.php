<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pace of an API token: how many requests a token may make in a minute (`inventories.api_token_requests_per_minute`), whatever it
 * asks for, counted for each token apart and on top of the limits of the endpoints. It is by the token rather than the person, so that
 * the script of one token cannot use up what another is for. A request without a token (the web front's session) is not counted.
 *
 * A request that sends a bearer and a session is signed in by the session (Sanctum looks at it first) and is refused later (`token:<ability>`
 * or {@see RefuseApiTokens}): it is counted all the same, by the hash of what it sends as a token, so that sending both is no way around
 * the pace.
 *
 * It runs right after the authentication, which tells it the token, and before everything that refuses a request: every request a token
 * makes counts, the ones that are refused too. The limits of the endpoints (an export, a workspace, a token to make...) are a person's own
 * and run after the refusals (see `bootstrap/app.php`), so that a refused request, which did nothing, never uses them up.
 *
 * The answers carry `X-RateLimit-Limit` and `X-RateLimit-Remaining`, as {@see ThrottleRequests} gives them; past the pace the request is a
 * {@see ThrottleRequestsException} (a 429 in JSON, see `bootstrap/app.php`) with `Retry-After`. When a limit of an endpoint has answered too,
 * the headers are those of the limit that has the fewest requests left, which is what the framework does for the limits it stacks.
 */
final class PaceApiTokens
{
    /** The width of the window of the pace, in seconds: a minute. */
    private const WINDOW = 60;

    /**
     * Gets the limiter that keeps the counts.
     */
    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * Counts the request for its token and refuses it past the pace.
     *
     * @param  Closure(Request): Response  $next
     *
     * @throws ThrottleRequestsException Beyond the pace.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->key($request);

        if ($key === null) {
            return $next($request);
        }

        $maximum = config()->integer('inventories.api_token_requests_per_minute');

        if ($this->limiter->tooManyAttempts($key, $maximum)) {
            $retryAfter = $this->limiter->availableIn($key);

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'X-RateLimit-Limit' => $maximum,
                'X-RateLimit-Remaining' => 0,
                'Retry-After' => $retryAfter,
                'X-RateLimit-Reset' => time() + $retryAfter,
            ]);
        }

        $this->limiter->hit($key, self::WINDOW);

        $response = $next($request);
        $remaining = $this->limiter->retriesLeft($key, $maximum);

        // The limit of an endpoint that has answered already is kept if it is the closer one.
        $left = $response->headers->get('X-RateLimit-Remaining');

        if ($left === null || (int) $left > $remaining) {
            $response->headers->add(['X-RateLimit-Limit' => $maximum, 'X-RateLimit-Remaining' => $remaining]);
        }

        return $response;
    }

    /**
     * What the request is counted for: its token, or the text it sends as one when the session outranks it; null for a session alone.
     */
    private function key(Request $request): ?string
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken) {
            return 'api-token-pace:token:'.$token->getKey();
        }

        // A bearer the session outranks: the token is not the one that signed the request in, so the text itself is the key.
        $bearer = $request->bearerToken();

        return $bearer !== null ? 'api-token-pace:bearer:'.hash('sha256', $bearer) : null;
    }
}
